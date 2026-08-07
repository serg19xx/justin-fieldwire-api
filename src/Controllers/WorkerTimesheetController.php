<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use App\Services\TaskDayWorkService;
use Flight;
use Monolog\Logger;

/**
 * Worker read-only monthly timesheet derived from Gantt tasks + per-day actual punches.
 * Not Justin's manual Schedule notebook.
 */
class WorkerTimesheetController
{
    public function __construct(
        private readonly Logger $logger
    ) {
    }

    private function error(string $message, int $http): void
    {
        Flight::json([
            'error_code' => $http,
            'status' => 'error',
            'message' => $message,
            'data' => null,
        ], $http);
    }

    /**
     * GET /api/v1/me/work-timesheet?year=2026&month=8
     */
    public function myMonthlyTimesheet(): void
    {
        $userId = (int) (Flight::get('current_user')['id'] ?? 0);
        if ($userId <= 0) {
            $this->error('Unauthorized', 401);
            return;
        }

        $year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
        $month = isset($_GET['month']) ? (int) $_GET['month'] : (int) date('n');
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            $this->error('year and month must be valid', 400);
            return;
        }

        $from = sprintf('%04d-%02d-01', $year, $month);
        $toDate = new \DateTimeImmutable($from);
        $to = $toDate->modify('last day of this month')->format('Y-m-d');

        try {
            $conn = Database::getConnection();
            $dayWork = new TaskDayWorkService();
            $hasDayWork = $dayWork->tableExists($conn);

            // Tasks the user is involved in that overlap the month (Gantt plan).
            $taskSql = '
                SELECT DISTINCT t.id AS task_id, t.name AS task_name, t.project_id,
                       t.start_planned, t.end_planned, t.address AS task_address,
                       p.prj_name AS project_name, p.address AS project_address,
                       p.latitude AS project_latitude, p.longitude AS project_longitude
                FROM fw_prj_tasks t
                INNER JOIN fw_projects p ON p.id = t.project_id
                INNER JOIN fw_prj_team_members tm ON tm.task_id = t.id AND tm.user_id = ?
                WHERE t.start_planned IS NOT NULL
                  AND t.start_planned <= ?
                  AND COALESCE(t.end_planned, t.start_planned) >= ?
                ORDER BY t.start_planned, t.id
            ';
            $tasks = $conn->executeQuery($taskSql, [$userId, $to, $from])->fetchAllAssociative();

            /** @var array<string, list<array<string, mixed>>> $byDay */
            $byDay = [];
            $cursor = new \DateTimeImmutable($from);
            $end = new \DateTimeImmutable($to);
            while ($cursor <= $end) {
                $ymd = $cursor->format('Y-m-d');
                $byDay[$ymd] = [];
                foreach ($tasks as $t) {
                    $start = substr((string) $t['start_planned'], 0, 10);
                    $endT = isset($t['end_planned']) && $t['end_planned'] !== null && $t['end_planned'] !== ''
                        ? substr((string) $t['end_planned'], 0, 10)
                        : $start;
                    if ($start <= $ymd && $ymd <= $endT) {
                        $byDay[$ymd][] = [
                            'project_id' => (int) $t['project_id'],
                            'project_name' => $t['project_name'] !== null ? (string) $t['project_name'] : null,
                            'project_address' => $this->trimAddress($t['project_address'] ?? null),
                            'task_id' => (int) $t['task_id'],
                            'task_name' => $t['task_name'] !== null ? (string) $t['task_name'] : null,
                            'task_address' => $this->trimAddress($t['task_address'] ?? null),
                            'work_start_at' => null,
                            'work_end_at' => null,
                            'hours' => null,
                            'has_actual' => false,
                        ];
                    }
                }
                $cursor = $cursor->modify('+1 day');
            }

            // Overlay actual punches from task day work.
            if ($hasDayWork) {
                $actuals = $conn->executeQuery(
                    'SELECT d.task_id, d.project_id, d.work_date, d.work_start_at, d.work_end_at,
                            t.name AS task_name, t.address AS task_address,
                            p.prj_name AS project_name, p.address AS project_address
                     FROM fw_prj_task_day_work d
                     INNER JOIN fw_prj_tasks t ON t.id = d.task_id
                     INNER JOIN fw_projects p ON p.id = d.project_id
                     WHERE d.user_id = ? AND d.work_date >= ? AND d.work_date <= ?
                     ORDER BY d.work_date, d.id',
                    [$userId, $from, $to]
                )->fetchAllAssociative();

                foreach ($actuals as $a) {
                    $ymd = $a['work_date'] instanceof \DateTimeInterface
                        ? $a['work_date']->format('Y-m-d')
                        : substr((string) $a['work_date'], 0, 10);
                    if (!isset($byDay[$ymd])) {
                        $byDay[$ymd] = [];
                    }
                    $taskId = (int) $a['task_id'];
                    $hours = $this->hoursBetween($a['work_start_at'] ?? null, $a['work_end_at'] ?? null);
                    $merged = false;
                    foreach ($byDay[$ymd] as &$entry) {
                        if ((int) $entry['task_id'] === $taskId) {
                            $entry['work_start_at'] = $a['work_start_at'] ?? null;
                            $entry['work_end_at'] = $a['work_end_at'] ?? null;
                            $entry['hours'] = $hours;
                            $entry['has_actual'] = !empty($a['work_start_at']);
                            $merged = true;
                            break;
                        }
                    }
                    unset($entry);
                    if (!$merged) {
                        $byDay[$ymd][] = [
                            'project_id' => (int) $a['project_id'],
                            'project_name' => $a['project_name'] !== null ? (string) $a['project_name'] : null,
                            'project_address' => $this->trimAddress($a['project_address'] ?? null),
                            'task_id' => $taskId,
                            'task_name' => $a['task_name'] !== null ? (string) $a['task_name'] : null,
                            'task_address' => $this->trimAddress($a['task_address'] ?? null),
                            'work_start_at' => $a['work_start_at'] ?? null,
                            'work_end_at' => $a['work_end_at'] ?? null,
                            'hours' => $hours,
                            'has_actual' => !empty($a['work_start_at']),
                        ];
                    }
                }
            }

            $daysOut = [];
            $totalHours = 0.0;
            ksort($byDay);
            foreach ($byDay as $ymd => $entries) {
                if ($entries === []) {
                    continue;
                }
                $dayHours = 0.0;
                foreach ($entries as $e) {
                    if (isset($e['hours']) && is_numeric($e['hours'])) {
                        $dayHours += (float) $e['hours'];
                    }
                }
                $totalHours += $dayHours;
                $daysOut[] = [
                    'work_date' => $ymd,
                    'entries' => $entries,
                    'hours' => round($dayHours, 2),
                ];
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'OK',
                'data' => [
                    'year' => $year,
                    'month' => $month,
                    'from' => $from,
                    'to' => $to,
                    'days' => $daysOut,
                    'total_hours' => round($totalHours, 2),
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('myMonthlyTimesheet failed', ['error' => $e->getMessage()]);
            $this->error('Failed to load timesheet', 500);
        }
    }

    private function trimAddress(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $s = trim((string) $raw);

        return $s === '' ? null : $s;
    }

    private function hoursBetween(mixed $start, mixed $end): ?float
    {
        if ($start === null || $end === null || $start === '' || $end === '') {
            return null;
        }
        try {
            $a = new \DateTimeImmutable(is_string($start) ? $start : (string) $start);
            $b = new \DateTimeImmutable(is_string($end) ? $end : (string) $end);
            $sec = $b->getTimestamp() - $a->getTimestamp();
            if ($sec < 0) {
                return null;
            }

            return round($sec / 3600, 2);
        } catch (\Throwable) {
            return null;
        }
    }
}
