<?php

declare(strict_types=1);

namespace App\Services;

use Doctrine\DBAL\Connection;

/**
 * Day-level Start day / End day per worker (one pair per user per calendar day).
 * Independent of per-task day work (fw_prj_task_day_work).
 */
class WorkerDayWorkService
{
    private const TABLE = 'fw_worker_day_work';

    /** Unpaid lunch break deducted from every completed work day. */
    public const BREAK_HOURS = 0.5;

    private WorkClockService $clock;

    public function __construct(?WorkClockService $clock = null)
    {
        $this->clock = $clock ?? new WorkClockService();
    }

    public function tableExists(Connection $conn): bool
    {
        return (bool) $conn->executeQuery(
            'SHOW TABLES LIKE ' . $conn->quote(self::TABLE)
        )->fetchOne();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findDayRow(Connection $conn, int $userId, string $workDateYmd): ?array
    {
        $row = $conn->executeQuery(
            'SELECT id, user_id, work_date,
                    work_start_lat, work_start_lng, work_start_at,
                    work_end_lat, work_end_lng, work_end_at
             FROM fw_worker_day_work
             WHERE user_id = ? AND work_date = ?',
            [$userId, $workDateYmd]
        )->fetchAssociative();

        return $row ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findRange(Connection $conn, int $userId, string $fromYmd, string $toYmd): array
    {
        return $conn->executeQuery(
            'SELECT id, user_id, work_date,
                    work_start_lat, work_start_lng, work_start_at,
                    work_end_lat, work_end_lng, work_end_at
             FROM fw_worker_day_work
             WHERE user_id = ? AND work_date >= ? AND work_date <= ?
             ORDER BY work_date',
            [$userId, $fromYmd, $toYmd]
        )->fetchAllAssociative();
    }

    /**
     * Location is optional: a laptop or a denied browser prompt must not block the punch.
     *
     * @return array{ok: true, row: array<string, mixed>}|array{ok: false, status: int, message: string}
     */
    public function checkIn(
        Connection $conn,
        int $userId,
        string $workDateYmd,
        string $phase,
        ?float $lat,
        ?float $lng,
    ): array {
        if ($phase !== 'start' && $phase !== 'end') {
            return ['ok' => false, 'status' => 400, 'message' => 'phase must be start or end'];
        }
        if ($lat !== null && $lng !== null && ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180)) {
            return ['ok' => false, 'status' => 400, 'message' => 'lat/lng out of range'];
        }
        if ($lat === null || $lng === null) {
            $lat = null;
            $lng = null;
        }

        $dayCheck = $this->clock->assertWorkDateIsToday($workDateYmd);
        if (!$dayCheck['ok']) {
            return ['ok' => false, 'status' => 403, 'message' => $dayCheck['message']];
        }

        $existing = $this->findDayRow($conn, $userId, $workDateYmd);
        $nowSql = $this->clock->nowSql();

        if ($phase === 'start') {
            if ($existing !== null && !empty($existing['work_start_at'])) {
                return ['ok' => false, 'status' => 409, 'message' => 'Day already started'];
            }
            if ($existing === null) {
                $conn->insert(self::TABLE, [
                    'user_id' => $userId,
                    'work_date' => $workDateYmd,
                    'work_start_lat' => $lat,
                    'work_start_lng' => $lng,
                    'work_start_at' => $nowSql,
                ]);
            } else {
                $conn->executeStatement(
                    'UPDATE fw_worker_day_work
                     SET work_start_lat = ?, work_start_lng = ?, work_start_at = ?
                     WHERE id = ?',
                    [$lat, $lng, $nowSql, (int) $existing['id']]
                );
            }
        } else {
            if ($existing === null || empty($existing['work_start_at'])) {
                return ['ok' => false, 'status' => 409, 'message' => 'Start the day before ending it'];
            }
            if (!empty($existing['work_end_at'])) {
                return ['ok' => false, 'status' => 409, 'message' => 'Day already ended'];
            }
            $conn->executeStatement(
                'UPDATE fw_worker_day_work
                 SET work_end_lat = ?, work_end_lng = ?, work_end_at = ?
                 WHERE id = ?',
                [$lat, $lng, $nowSql, (int) $existing['id']]
            );
        }

        $saved = $this->findDayRow($conn, $userId, $workDateYmd);
        if ($saved === null) {
            return ['ok' => false, 'status' => 500, 'message' => 'Failed to load day after check-in'];
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
            'work_date' => substr((string) $workDate, 0, 10),
            'work_start_at' => $this->clock->toApiIso($r['work_start_at'] ?? null),
            'work_end_at' => $this->clock->toApiIso($r['work_end_at'] ?? null),
            'hours' => $this->paidHours($r['work_start_at'] ?? null, $r['work_end_at'] ?? null),
            'break_hours' => self::BREAK_HOURS,
        ];
    }

    private function paidHours(mixed $start, mixed $end): ?float
    {
        $a = $this->clock->toApiIso($start);
        $b = $this->clock->toApiIso($end);
        if ($a === null || $b === null) {
            return null;
        }
        $sec = (new \DateTimeImmutable($b))->getTimestamp() - (new \DateTimeImmutable($a))->getTimestamp();

        if ($sec < 0) {
            return null;
        }

        return round(max(0.0, $sec / 3600 - self::BREAK_HOURS), 2);
    }
}
