<?php

declare(strict_types=1);

namespace App\Services;

use Doctrine\DBAL\Connection;

/**
 * Worker-facing view of the PM Schedule: the last published snapshot of each week, with expected
 * times and km from the live slot (same source as GET /me/schedule).
 */
class PublishedScheduleService
{
    private const SLOT_SELECT = '
        SELECT s.id AS snapshot_row_id, s.worker_task_schedule_id, s.user_id, s.project_id,
               s.work_date, s.day_part, s.assignment_note,
               live.distance_km, live.expected_start_time, live.expected_end_time,
               p.prj_name AS project_name, p.address AS project_address
        FROM fw_worker_task_schedule_snapshots s
        INNER JOIN fw_projects p ON p.id = s.project_id
        LEFT JOIN fw_worker_task_schedules live ON live.id = s.worker_task_schedule_id';

    private const SLOT_ORDER = "
        ORDER BY s.work_date, CASE s.day_part WHEN 'am' THEN 1 WHEN 'pm' THEN 2 ELSE 3 END, s.id";

    /**
     * @return list<array{
     *   entry_id: int, user_id: int, project_id: int, project_name: ?string, project_address: ?string,
     *   work_date: string, day_part: string, assignment_note: ?string, distance_km: ?string,
     *   expected_start_time: ?string, expected_end_time: ?string
     * }>
     */
    public function slotsForUser(Connection $conn, int $userId, string $fromYmd, string $toYmd): array
    {
        $rows = $conn->executeQuery(
            self::SLOT_SELECT . ' WHERE s.user_id = ? AND s.work_date >= ? AND s.work_date <= ?' . self::SLOT_ORDER,
            [$userId, $fromYmd, $toYmd]
        )->fetchAllAssociative();

        return array_map(fn (array $r): array => $this->formatSlot($r), $rows);
    }

    /**
     * Field crew with published slots on $ymd and their expected day: earliest start, latest end.
     *
     * @return array<int, array{start_time: ?string, end_time: ?string}> keyed by user id
     */
    public function expectedDaysOn(Connection $conn, string $ymd): array
    {
        $rows = $conn->executeQuery(
            "SELECT s.user_id,
                    MIN(live.expected_start_time) AS start_time,
                    MAX(live.expected_end_time) AS end_time
             FROM fw_worker_task_schedule_snapshots s
             LEFT JOIN fw_worker_task_schedules live ON live.id = s.worker_task_schedule_id
             WHERE s.work_date = ?
             GROUP BY s.user_id",
            [$ymd]
        )->fetchAllAssociative();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['user_id']] = [
                'start_time' => self::hm($r['start_time'] ?? null),
                'end_time' => self::hm($r['end_time'] ?? null),
            ];
        }

        return $out;
    }

    /** First number in the PM's free-text km ("45", "45 km", "12,5"); null when there is none. */
    public static function parseKm(?string $raw): ?float
    {
        if ($raw === null || preg_match('/\d+(?:[.,]\d+)?/', $raw, $m) !== 1) {
            return null;
        }

        return (float) str_replace(',', '.', $m[0]);
    }

    /** MySQL TIME ("07:00:00") to "07:00"; null when empty. */
    public static function hm(mixed $raw): ?string
    {
        if ($raw === null || preg_match('/^(\d{1,2}):(\d{2})/', trim((string) $raw), $m) !== 1) {
            return null;
        }

        return sprintf('%02d:%s', (int) $m[1], $m[2]);
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function formatSlot(array $r): array
    {
        $workDate = $r['work_date'] instanceof \DateTimeInterface
            ? $r['work_date']->format('Y-m-d')
            : substr((string) $r['work_date'], 0, 10);
        $text = static function (mixed $v): ?string {
            $s = $v === null ? '' : trim((string) $v);

            return $s === '' ? null : $s;
        };

        return [
            'entry_id' => $r['worker_task_schedule_id'] !== null
                ? (int) $r['worker_task_schedule_id']
                : (int) $r['snapshot_row_id'],
            'user_id' => (int) $r['user_id'],
            'project_id' => (int) $r['project_id'],
            'project_name' => $text($r['project_name'] ?? null),
            'project_address' => $text($r['project_address'] ?? null),
            'work_date' => $workDate,
            'day_part' => (string) $r['day_part'],
            'assignment_note' => $text($r['assignment_note'] ?? null),
            'distance_km' => $text($r['distance_km'] ?? null),
            'expected_start_time' => self::hm($r['expected_start_time'] ?? null),
            'expected_end_time' => self::hm($r['expected_end_time'] ?? null),
        ];
    }
}
