<?php

declare(strict_types=1);

namespace App\Services;

use Doctrine\DBAL\Connection;

/**
 * Actual Start/End per task per calendar day (reporting).
 * Independent of Justin's project-day timesheet (fw_worker_task_schedules).
 */
class TaskDayWorkService
{
    private WorkClockService $clock;

    public function __construct(?WorkClockService $clock = null)
    {
        $this->clock = $clock ?? new WorkClockService();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findDayRow(
        Connection $conn,
        int $taskId,
        int $userId,
        string $workDateYmd,
    ): ?array {
        $row = $conn->executeQuery(
            'SELECT id, project_id, task_id, user_id, work_date,
                    work_start_lat, work_start_lng, work_start_at,
                    work_end_lat, work_end_lng, work_end_at,
                    created_at, updated_at
             FROM fw_prj_task_day_work
             WHERE task_id = ? AND user_id = ? AND work_date = ?',
            [$taskId, $userId, $workDateYmd]
        )->fetchAssociative();

        return $row ?: null;
    }

    /**
     * @return array{ok: true, row: array<string, mixed>}|array{ok: false, status: int, message: string}
     */
    public function checkIn(
        Connection $conn,
        int $projectId,
        int $taskId,
        int $userId,
        string $workDateYmd,
        string $phase,
        float $lat,
        float $lng,
    ): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDateYmd)) {
            return ['ok' => false, 'status' => 400, 'message' => 'work_date must be YYYY-MM-DD'];
        }
        if ($phase !== 'start' && $phase !== 'end') {
            return ['ok' => false, 'status' => 400, 'message' => 'phase must be start or end'];
        }
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return ['ok' => false, 'status' => 400, 'message' => 'lat/lng out of range'];
        }

        $dayCheck = $this->clock->assertWorkDateIsToday($workDateYmd);
        if (!$dayCheck['ok']) {
            return ['ok' => false, 'status' => 403, 'message' => $dayCheck['message']];
        }

        $task = $conn->executeQuery(
            'SELECT id, project_id FROM fw_prj_tasks WHERE id = ? AND project_id = ?',
            [$taskId, $projectId]
        )->fetchAssociative();
        if (!$task) {
            return ['ok' => false, 'status' => 404, 'message' => 'Task not found'];
        }

        $existing = $this->findDayRow($conn, $taskId, $userId, $workDateYmd);
        $now = $this->clock->now();
        $nowSql = $this->clock->nowSql($now);

        if ($phase === 'start') {
            if ($existing !== null && !empty($existing['work_start_at'])) {
                return ['ok' => false, 'status' => 409, 'message' => 'Start already recorded for this task day'];
            }
            if ($existing === null) {
                $conn->insert('fw_prj_task_day_work', [
                    'project_id' => $projectId,
                    'task_id' => $taskId,
                    'user_id' => $userId,
                    'work_date' => $workDateYmd,
                    'work_start_lat' => $lat,
                    'work_start_lng' => $lng,
                    'work_start_at' => $nowSql,
                ]);
            } else {
                $conn->executeStatement(
                    'UPDATE fw_prj_task_day_work
                     SET work_start_lat = ?, work_start_lng = ?, work_start_at = ?, updated_at = ?
                     WHERE id = ?',
                    [$lat, $lng, $nowSql, $nowSql, (int) $existing['id']]
                );
            }
        } else {
            if ($existing === null || empty($existing['work_start_at'])) {
                return ['ok' => false, 'status' => 409, 'message' => 'Start work before ending for this task day'];
            }
            if (!empty($existing['work_end_at'])) {
                return ['ok' => false, 'status' => 409, 'message' => 'End already recorded for this task day'];
            }

            $startIso = $this->clock->toApiIso($existing['work_start_at']);
            if ($startIso !== null) {
                try {
                    $startDt = new \DateTimeImmutable($startIso);
                    if ($now < $startDt) {
                        return [
                            'ok' => false,
                            'status' => 409,
                            'message' => 'End time cannot be before start time on the same work day.',
                        ];
                    }
                    if ($startDt->setTimezone($this->clock->timezone())->format('Y-m-d') !== $workDateYmd) {
                        return [
                            'ok' => false,
                            'status' => 409,
                            'message' => 'Start and end must be on the same calendar work day.',
                        ];
                    }
                } catch (\Throwable) {
                    // continue with write
                }
            }

            $conn->executeStatement(
                'UPDATE fw_prj_task_day_work
                 SET work_end_lat = ?, work_end_lng = ?, work_end_at = ?, updated_at = ?
                 WHERE id = ?',
                [$lat, $lng, $nowSql, $nowSql, (int) $existing['id']]
            );
        }

        $saved = $this->findDayRow($conn, $taskId, $userId, $workDateYmd);
        if ($saved === null) {
            return ['ok' => false, 'status' => 500, 'message' => 'Failed to load day work after check-in'];
        }

        return ['ok' => true, 'row' => $this->formatRow($saved)];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public function formatRow(array $r): array
    {
        $workDate = $r['work_date'] ?? '';
        if ($workDate instanceof \DateTimeInterface) {
            $workDate = $workDate->format('Y-m-d');
        }

        return [
            'id' => (int) $r['id'],
            'project_id' => (int) $r['project_id'],
            'task_id' => (int) $r['task_id'],
            'user_id' => (int) $r['user_id'],
            'work_date' => (string) $workDate,
            'work_start_lat' => $this->nullableFloat($r['work_start_lat'] ?? null),
            'work_start_lng' => $this->nullableFloat($r['work_start_lng'] ?? null),
            'work_start_at' => $this->clock->toApiIso($r['work_start_at'] ?? null),
            'work_end_lat' => $this->nullableFloat($r['work_end_lat'] ?? null),
            'work_end_lng' => $this->nullableFloat($r['work_end_lng'] ?? null),
            'work_end_at' => $this->clock->toApiIso($r['work_end_at'] ?? null),
        ];
    }

    private function nullableFloat(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_numeric($v)) {
            return null;
        }
        $f = (float) $v;

        return is_finite($f) ? $f : null;
    }

    public function tableExists(Connection $conn): bool
    {
        return (bool) $conn->executeQuery(
            'SHOW TABLES LIKE ' . $conn->quote('fw_prj_task_day_work')
        )->fetchOne();
    }
}
