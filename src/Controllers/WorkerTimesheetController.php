<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use App\Services\WorkClockService;
use App\Services\WorkerDayWorkService;
use Flight;
use Monolog\Logger;

/**
 * Worker monthly timesheet: paid hours from Start day / End day punches, tasks with scheduled time.
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

            // Tasks the user is involved in that overlap the month (Gantt plan).
            $taskSql = '
                SELECT DISTINCT t.id AS task_id, t.name AS task_name, t.project_id,
                       t.start_planned, t.end_planned, t.start_time, t.end_time,
                       t.address AS task_address,
                       p.prj_name AS project_name, p.address AS project_address
                FROM fw_prj_tasks t
                INNER JOIN fw_projects p ON p.id = t.project_id
                INNER JOIN fw_prj_team_members tm ON tm.task_id = t.id AND tm.user_id = ?
                WHERE t.start_planned IS NOT NULL
                  AND t.start_planned <= ?
                  AND COALESCE(t.end_planned, t.start_planned) >= ?
                ORDER BY t.start_planned, t.start_time, t.id
            ';
            $tasks = $conn->executeQuery($taskSql, [$userId, $to, $from])->fetchAllAssociative();

            /** @var array<string, list<array<string, mixed>>> $byDay */
            $byDay = [];
            $cursor = new \DateTimeImmutable($from);
            $end = new \DateTimeImmutable($to);
            while ($cursor <= $end) {
                $ymd = $cursor->format('Y-m-d');
                foreach ($tasks as $t) {
                    $start = substr((string) $t['start_planned'], 0, 10);
                    $endT = isset($t['end_planned']) && $t['end_planned'] !== null && $t['end_planned'] !== ''
                        ? substr((string) $t['end_planned'], 0, 10)
                        : $start;
                    if ($start <= $ymd && $ymd <= $endT) {
                        $scheduledStart = $this->formatTimeOfDay($t['start_time'] ?? null);
                        $scheduledEnd = $this->formatTimeOfDay($t['end_time'] ?? null);
                        $byDay[$ymd][] = [
                            'project_id' => (int) $t['project_id'],
                            'project_name' => $t['project_name'] !== null ? (string) $t['project_name'] : null,
                            'project_address' => $this->trimAddress($t['project_address'] ?? null),
                            'task_id' => (int) $t['task_id'],
                            'task_name' => $t['task_name'] !== null ? (string) $t['task_name'] : null,
                            'task_address' => $this->trimAddress($t['task_address'] ?? null),
                            'scheduled_start' => $scheduledStart,
                            'scheduled_end' => $scheduledEnd,
                            'scheduled_hours' => $this->scheduledHours($scheduledStart, $scheduledEnd),
                        ];
                    }
                }
                $cursor = $cursor->modify('+1 day');
            }

            $workerDay = new WorkerDayWorkService();
            /** @var array<string, array<string, mixed>> $dayWorkByDate */
            $dayWorkByDate = [];
            if ($workerDay->tableExists($conn)) {
                foreach ($workerDay->findRange($conn, $userId, $from, $to) as $row) {
                    $formatted = $workerDay->formatRow($row);
                    $dayWorkByDate[(string) $formatted['work_date']] = $formatted;
                }
            }

            $daysOut = [];
            $totalHours = 0.0;
            foreach (array_keys($dayWorkByDate) as $ymd) {
                $byDay[$ymd] ??= [];
            }
            ksort($byDay);
            foreach ($byDay as $ymd => $entries) {
                $dayWork = $dayWorkByDate[$ymd] ?? null;
                // Paid hours come only from the Start day / End day timesheet punch.
                $dayHours = $dayWork !== null && $dayWork['hours'] !== null ? (float) $dayWork['hours'] : 0.0;
                $totalHours += $dayHours;
                $daysOut[] = [
                    'work_date' => $ymd,
                    'day_work' => $dayWork,
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

    /**
     * GET /api/v1/me/work-day?work_date=YYYY-MM-DD (defaults to today)
     */
    public function myWorkDay(): void
    {
        $userId = (int) (Flight::get('current_user')['id'] ?? 0);
        if ($userId <= 0) {
            $this->error('Unauthorized', 401);
            return;
        }

        $clock = new WorkClockService();
        $workDate = isset($_GET['work_date']) ? trim((string) $_GET['work_date']) : $clock->todayYmd();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
            $this->error('work_date must be YYYY-MM-DD', 400);
            return;
        }

        try {
            $conn = Database::getConnection();
            $service = new WorkerDayWorkService($clock);
            $row = $service->tableExists($conn) ? $service->findDayRow($conn, $userId, $workDate) : null;

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'OK',
                'data' => [
                    'today' => $clock->todayYmd(),
                    'day_work' => $row !== null ? $service->formatRow($row) : null,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('myWorkDay failed', ['error' => $e->getMessage()]);
            $this->error('Failed to load work day', 500);
        }
    }

    /**
     * POST /api/v1/me/work-day/check-in  { work_date, phase: start|end, lat?, lng? }
     */
    public function checkInMyWorkDay(): void
    {
        $userId = (int) (Flight::get('current_user')['id'] ?? 0);
        if ($userId <= 0) {
            $this->error('Unauthorized', 401);
            return;
        }

        $body = json_decode(Flight::request()->getBody(), true);
        if (!is_array($body)) {
            $body = [];
        }
        $clock = new WorkClockService();
        $workDate = isset($body['work_date']) ? trim((string) $body['work_date']) : $clock->todayYmd();
        $phase = isset($body['phase']) ? (string) $body['phase'] : '';
        $lat = isset($body['lat']) && is_numeric($body['lat']) ? (float) $body['lat'] : null;
        $lng = isset($body['lng']) && is_numeric($body['lng']) ? (float) $body['lng'] : null;

        try {
            $conn = Database::getConnection();
            $service = new WorkerDayWorkService($clock);
            if (!$service->tableExists($conn)) {
                $this->error('Start day / End day is not available until database migration is applied', 503);
                return;
            }

            $result = $service->checkIn($conn, $userId, $workDate, $phase, $lat, $lng);
            if (!$result['ok']) {
                $this->error($result['message'], $result['status']);
                return;
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => $phase === 'start' ? 'Day started' : 'Day ended',
                'data' => ['day_work' => $result['row']],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('checkInMyWorkDay failed', ['error' => $e->getMessage()]);
            $this->error('Failed to record work day', 500);
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

    /** MySQL TIME ("08:00:00") to "08:00"; null when empty. */
    private function formatTimeOfDay(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $s = trim((string) $raw);
        if (!preg_match('/^(\d{1,2}):(\d{2})/', $s, $m)) {
            return null;
        }

        return sprintf('%02d:%s', (int) $m[1], $m[2]);
    }

    private function scheduledHours(?string $start, ?string $end): ?float
    {
        if ($start === null || $end === null) {
            return null;
        }
        [$sh, $sm] = array_map('intval', explode(':', $start));
        [$eh, $em] = array_map('intval', explode(':', $end));
        $minutes = ($eh * 60 + $em) - ($sh * 60 + $sm);

        return $minutes > 0 ? round($minutes / 60, 2) : null;
    }
}
