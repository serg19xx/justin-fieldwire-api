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

    private const BASE_COLUMNS = 'id, user_id, work_date,
        work_start_lat, work_start_lng, work_start_at,
        work_end_lat, work_end_lng, work_end_at';

    private static ?bool $photoColumns = null;

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

    /** True once scripts/add-worker-day-work-photos.sql is applied. */
    public function photoColumnsPresent(Connection $conn): bool
    {
        if (self::$photoColumns === null) {
            try {
                $cols = $conn->createSchemaManager()->listTableColumns(self::TABLE);
                self::$photoColumns = isset($cols['work_start_photo'], $cols['work_end_photo']);
            } catch (\Throwable) {
                self::$photoColumns = false;
            }
        }

        return self::$photoColumns;
    }

    private function selectColumns(Connection $conn): string
    {
        return self::BASE_COLUMNS
            . ($this->photoColumnsPresent($conn) ? ', work_start_photo, work_end_photo' : '');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findDayRow(Connection $conn, int $userId, string $workDateYmd): ?array
    {
        $row = $conn->executeQuery(
            'SELECT ' . $this->selectColumns($conn) . '
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
            'SELECT ' . $this->selectColumns($conn) . '
             FROM fw_worker_day_work
             WHERE user_id = ? AND work_date >= ? AND work_date <= ?
             ORDER BY work_date',
            [$userId, $fromYmd, $toYmd]
        )->fetchAllAssociative();
    }

    /** Stored photo path (relative to public/) for one punch, or null. */
    public function photoPath(Connection $conn, int $userId, string $workDateYmd, string $phase): ?string
    {
        if (!$this->tableExists($conn) || !$this->photoColumnsPresent($conn)) {
            return null;
        }
        $row = $this->findDayRow($conn, $userId, $workDateYmd);
        $path = $row[$phase === 'end' ? 'work_end_photo' : 'work_start_photo'] ?? null;

        return is_string($path) && $path !== '' ? $path : null;
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
        ?string $photoPath = null,
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
        $withPhoto = $photoPath !== null && $this->photoColumnsPresent($conn);

        if ($phase === 'start') {
            if ($existing !== null && !empty($existing['work_start_at'])) {
                return ['ok' => false, 'status' => 409, 'message' => 'Day already started'];
            }
            $values = [
                'work_start_lat' => $lat,
                'work_start_lng' => $lng,
                'work_start_at' => $nowSql,
            ];
            if ($withPhoto) {
                $values['work_start_photo'] = $photoPath;
            }
            if ($existing === null) {
                $conn->insert(self::TABLE, ['user_id' => $userId, 'work_date' => $workDateYmd] + $values);
            } else {
                $conn->update(self::TABLE, $values, ['id' => (int) $existing['id']]);
            }
        } else {
            if ($existing === null || empty($existing['work_start_at'])) {
                return ['ok' => false, 'status' => 409, 'message' => 'Start the day before ending it'];
            }
            if (!empty($existing['work_end_at'])) {
                return ['ok' => false, 'status' => 409, 'message' => 'Day already ended'];
            }
            $values = [
                'work_end_lat' => $lat,
                'work_end_lng' => $lng,
                'work_end_at' => $nowSql,
            ];
            if ($withPhoto) {
                $values['work_end_photo'] = $photoPath;
            }
            $conn->update(self::TABLE, $values, ['id' => (int) $existing['id']]);
        }

        $saved = $this->findDayRow($conn, $userId, $workDateYmd);
        if ($saved === null) {
            return ['ok' => false, 'status' => 500, 'message' => 'Failed to load day after check-in'];
        }

        return ['ok' => true, 'row' => $this->formatRow($saved)];
    }

    /**
     * Fill the actual start/end of schedule slot rows from the day-level punch when the slot has none,
     * and flag which punches have a photo. Rows need `user_id` and `work_date`.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function overlayOntoScheduleRows(Connection $conn, array $rows): array
    {
        if ($rows === [] || !$this->tableExists($conn)) {
            return $rows;
        }

        $pairs = [];
        foreach ($rows as $r) {
            $pairs[(int) $r['user_id'] . '|' . $this->ymd($r['work_date'] ?? '')] = true;
        }
        $where = [];
        $params = [];
        foreach (array_keys($pairs) as $key) {
            [$uid, $ymd] = explode('|', $key, 2);
            $where[] = '(user_id = ? AND work_date = ?)';
            $params[] = (int) $uid;
            $params[] = $ymd;
        }
        $dayRows = $conn->executeQuery(
            'SELECT ' . $this->selectColumns($conn) . ' FROM fw_worker_day_work WHERE ' . implode(' OR ', $where),
            $params
        )->fetchAllAssociative();

        $byKey = [];
        foreach ($dayRows as $d) {
            $byKey[(int) $d['user_id'] . '|' . $this->ymd($d['work_date'])] = $d;
        }

        foreach ($rows as $i => $r) {
            $day = $byKey[(int) $r['user_id'] . '|' . $this->ymd($r['work_date'] ?? '')] ?? null;
            if ($day === null) {
                continue;
            }
            if (empty($r['work_start_at']) && !empty($day['work_start_at'])) {
                $rows[$i]['work_start_at'] = $day['work_start_at'];
                $rows[$i]['work_start_lat'] = $day['work_start_lat'];
                $rows[$i]['work_start_lng'] = $day['work_start_lng'];
            }
            if (empty($r['work_end_at']) && !empty($day['work_end_at'])) {
                $rows[$i]['work_end_at'] = $day['work_end_at'];
                $rows[$i]['work_end_lat'] = $day['work_end_lat'];
                $rows[$i]['work_end_lng'] = $day['work_end_lng'];
            }
            $rows[$i]['has_work_start_photo'] = !empty($day['work_start_photo']);
            $rows[$i]['has_work_end_photo'] = !empty($day['work_end_photo']);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    public function formatRow(array $r): array
    {
        return [
            'work_date' => $this->ymd($r['work_date'] ?? ''),
            'work_start_at' => $this->clock->toApiIso($r['work_start_at'] ?? null),
            'work_end_at' => $this->clock->toApiIso($r['work_end_at'] ?? null),
            'hours' => $this->paidHours($r['work_start_at'] ?? null, $r['work_end_at'] ?? null),
            'break_hours' => self::BREAK_HOURS,
            'has_start_photo' => !empty($r['work_start_photo']),
            'has_end_photo' => !empty($r['work_end_photo']),
        ];
    }

    private function ymd(mixed $workDate): string
    {
        if ($workDate instanceof \DateTimeInterface) {
            return $workDate->format('Y-m-d');
        }

        return substr((string) $workDate, 0, 10);
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
