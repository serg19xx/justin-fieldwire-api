<?php

declare(strict_types=1);

namespace App\Services;

use Doctrine\DBAL\Connection;
use Monolog\Logger;

/**
 * Attendance rules on top of the Start day / End day punches.
 *
 * Paid start: a punch before the scheduled start or up to START_GRACE_MINUTES after it pays from the
 * scheduled start; a later punch pays from the punch. Paid end: a punch before the scheduled end pays
 * to the punch and flags an early leave; a later punch pays to the scheduled end and, when it is more
 * than OVERTIME_GRACE_MINUTES late, flags overtime for PM approval. A started day without End day is
 * closed at the scheduled end AUTO_CLOSE_MINUTES after the working window (scheduled end or the
 * worker's "Working late" time). Rules never change a time the PM has set, nor a locked day.
 */
class AttendanceService
{
    public const START_GRACE_MINUTES = 15;
    public const OVERTIME_GRACE_MINUTES = 15;
    public const AUTO_CLOSE_MINUTES = 60;
    public const NO_START_AFTER_MINUTES = 45;
    public const SITE_GRACE_MINUTES = 15;

    public const FLAG_LATE_START = 'late_start';
    public const FLAG_EARLY_LEAVE = 'early_leave';
    public const FLAG_AUTO_CLOSED = 'auto_closed';
    public const FLAG_OVERTIME = 'overtime';
    public const FLAG_REVIEWED = 'reviewed';

    /** Minutes added by the "Working late" choices; "open" pauses reminders until End day. */
    public const EXTEND_MINUTES = ['1h' => 60, '2h' => 120];

    private const DAY_TABLE = 'fw_worker_day_work';
    private const SITE_TABLE = 'fw_worker_day_site_events';
    private const CLOSED_TASK_STATUSES = ['completed', 'done'];

    private static ?bool $siteTable = null;

    private WorkClockService $clock;
    private WorkerDayWorkService $dayWork;
    private Logger $logger;

    /** @var array<int, array{lat: float, lng: float}|null> */
    private array $siteLocations = [];

    public function __construct(?WorkClockService $clock = null, ?Logger $logger = null)
    {
        $this->clock = $clock ?? new WorkClockService();
        $this->dayWork = new WorkerDayWorkService($this->clock);
        $this->logger = $logger ?? new Logger('attendance');
    }

    public function isAvailable(Connection $conn): bool
    {
        return $this->dayWork->tableExists($conn) && $this->dayWork->attendanceColumnsPresent($conn);
    }

    public function siteEventsAvailable(Connection $conn): bool
    {
        return self::$siteTable ??= (bool) $conn->executeQuery(
            'SHOW TABLES LIKE ' . $conn->quote(self::SITE_TABLE)
        )->fetchOne();
    }

    /**
     * Field crew (role category "task") with a planned day on $ymd, weekends included: the published
     * PM Schedule (earliest expected start, latest expected end) wins over the task plan.
     *
     * @return array<int, array{user_id: int, first_name: string, last_name: string, phone: ?string, start_time: string, end_time: string, source: string}>
     */
    public function shiftsOn(Connection $conn, string $ymd): array
    {
        $byUser = [];
        foreach ($this->taskPlanShifts($conn, $ymd) as $shift) {
            $byUser[$shift['user_id']] = $shift;
        }

        $expected = array_filter(
            (new PublishedScheduleService())->expectedDaysOn($conn, $ymd),
            static fn (array $d): bool => $d['start_time'] !== null && $d['end_time'] !== null
        );
        if ($expected !== []) {
            $ids = array_keys($expected);
            $users = $conn->executeQuery(
                "SELECT u.id AS user_id, u.first_name, u.last_name, u.phone
                 FROM fw_users u
                 INNER JOIN fw_glob_roles r ON r.id = u.role_id AND r.category = 'task'
                 WHERE u.id IN (" . implode(', ', array_fill(0, count($ids), '?')) . ')
                   AND u.status = 1
                   AND u.archived_at IS NULL',
                $ids
            )->fetchAllAssociative();
            foreach ($users as $u) {
                $uid = (int) $u['user_id'];
                $byUser[$uid] = $this->shiftRow(
                    $u,
                    (string) $expected[$uid]['start_time'],
                    (string) $expected[$uid]['end_time'],
                    'schedule'
                );
            }
        }

        ksort($byUser);

        return $byUser;
    }

    /**
     * @return array{user_id: int, first_name: string, last_name: string, phone: ?string, start_time: string, end_time: string, source: string}|null
     */
    public function shiftFor(Connection $conn, int $userId, string $ymd): ?array
    {
        return $this->shiftsOn($conn, $ymd)[$userId] ?? null;
    }

    /**
     * End of the working window: the scheduled end, or the worker's later "Working late" time.
     *
     * @param array{end_time: string} $shift
     * @param array<string, mixed>|null $row
     */
    public function clockOutBase(string $ymd, array $shift, ?array $row): \DateTimeImmutable
    {
        $end = $this->at($ymd, $shift['end_time']);
        $extended = PublishedScheduleService::hm($row['extended_until'] ?? null);
        if ($extended !== null) {
            $until = $this->at($ymd, $extended);
            if ($until > $end) {
                return $until;
            }
        }

        return $end;
    }

    /** Apply the paid-time rule for the phase just punched. Never throws into the punch flow. */
    public function applyPunchRules(Connection $conn, int $userId, string $ymd, string $phase): void
    {
        if (!$this->isAvailable($conn)) {
            return;
        }
        $row = $this->dayWork->findDayRow($conn, $userId, $ymd);
        if ($row === null || !empty($row['locked_at'])) {
            return;
        }
        $shift = $this->shiftFor($conn, $userId, $ymd);
        if ($shift === null) {
            return;
        }
        $start = $this->at($ymd, $shift['start_time']);
        $end = $this->at($ymd, $shift['end_time']);
        if ($end <= $start) {
            return;
        }

        $flags = WorkerDayWorkService::parseFlags($row['attendance_flags'] ?? null);
        $values = [
            'scheduled_start_time' => $shift['start_time'] . ':00',
            'scheduled_end_time' => $shift['end_time'] . ':00',
        ];

        if ($phase === 'start' && ($row['start_source'] ?? null) !== WorkerDayWorkService::SOURCE_PM) {
            $punch = $this->localTime($row['work_start_at'] ?? null);
            if ($punch !== null) {
                $onTime = $punch <= $start->modify('+' . self::START_GRACE_MINUTES . ' minutes');
                $values['adjusted_start_time'] = $onTime ? $shift['start_time'] . ':00' : null;
                $values['start_source'] = $onTime ? WorkerDayWorkService::SOURCE_AUTO : null;
                $flags = $this->toggle($flags, self::FLAG_LATE_START, !$onTime);
            }
        }

        if ($phase === 'end' && ($row['end_source'] ?? null) !== WorkerDayWorkService::SOURCE_PM) {
            $punch = $this->localTime($row['work_end_at'] ?? null);
            if ($punch !== null) {
                $early = $punch < $end;
                $values['adjusted_end_time'] = $early ? null : $shift['end_time'] . ':00';
                $values['end_source'] = $early ? null : WorkerDayWorkService::SOURCE_AUTO;
                $values['auto_closed_at'] = null;
                $flags = $this->toggle($flags, self::FLAG_EARLY_LEAVE, $early);
                $flags = $this->toggle(
                    $flags,
                    self::FLAG_OVERTIME,
                    $punch > $end->modify('+' . self::OVERTIME_GRACE_MINUTES . ' minutes')
                );
                $flags = $this->toggle($flags, self::FLAG_AUTO_CLOSED, false);
            }
        }

        $values['attendance_flags'] = WorkerDayWorkService::formatFlags($flags);
        $conn->update(self::DAY_TABLE, $values, ['id' => (int) $row['id']]);
    }

    /**
     * Close started days without End day AUTO_CLOSE_MINUTES after the working window, paying to the
     * scheduled end. Checks today and yesterday (late shifts past midnight). $apply false only reports.
     *
     * @return list<array{user_id: int, work_date: string, end_time: string, status: string}>
     */
    public function autoClose(Connection $conn, \DateTimeImmutable $now, bool $apply): array
    {
        if (!$this->isAvailable($conn)) {
            return [];
        }
        $now = $now->setTimezone($this->clock->timezone());
        $out = [];
        foreach ([$now->modify('-1 day')->format('Y-m-d'), $now->format('Y-m-d')] as $ymd) {
            $rows = $conn->executeQuery(
                'SELECT ' . $this->dayWork->selectColumns($conn) . "
                 FROM fw_worker_day_work
                 WHERE work_date = ?
                   AND work_start_at IS NOT NULL
                   AND work_end_at IS NULL
                   AND adjusted_end_time IS NULL
                   AND locked_at IS NULL
                   AND extended_open = 0
                   AND (end_source IS NULL OR end_source <> 'pm')",
                [$ymd]
            )->fetchAllAssociative();
            if ($rows === []) {
                continue;
            }
            $shifts = $this->shiftsOn($conn, $ymd);
            foreach ($rows as $row) {
                $shift = $shifts[(int) $row['user_id']] ?? null;
                if ($shift === null || $shift['end_time'] <= $shift['start_time']) {
                    continue;
                }
                $closeAt = $this->clockOutBase($ymd, $shift, $row)
                    ->modify('+' . self::AUTO_CLOSE_MINUTES . ' minutes');
                if ($now < $closeAt) {
                    continue;
                }
                $status = 'dry_run';
                if ($apply) {
                    $flags = WorkerDayWorkService::parseFlags($row['attendance_flags'] ?? null);
                    $conn->update(self::DAY_TABLE, [
                        'adjusted_end_time' => $shift['end_time'] . ':00',
                        'end_source' => WorkerDayWorkService::SOURCE_AUTO,
                        'auto_closed_at' => $this->clock->nowSql($now),
                        'scheduled_start_time' => $shift['start_time'] . ':00',
                        'scheduled_end_time' => $shift['end_time'] . ':00',
                        'attendance_flags' => WorkerDayWorkService::formatFlags(
                            $this->toggle($flags, self::FLAG_AUTO_CLOSED, true)
                        ),
                    ], ['id' => (int) $row['id']]);
                    $status = 'closed';
                }
                $out[] = [
                    'user_id' => (int) $row['user_id'],
                    'work_date' => $ymd,
                    'end_time' => $shift['end_time'],
                    'status' => $status,
                ];
            }
        }

        return $out;
    }

    /**
     * "Working late": 1h|2h moves the end of the working window (reminders and auto-close follow),
     * "open" pauses them until End day, "cancel" goes back to the scheduled end. Extending reopens a
     * day the rules auto-closed. Overtime is still paid only after PM approval.
     *
     * @return array{ok: true, row: array<string, mixed>}|array{ok: false, status: int, message: string}
     */
    public function extend(Connection $conn, int $userId, string $ymd, string $mode, ?\DateTimeImmutable $now = null): array
    {
        if (!$this->isAvailable($conn)) {
            return ['ok' => false, 'status' => 503, 'message' => 'Working late is not available until database migration is applied'];
        }
        $now = ($now ?? $this->clock->now())->setTimezone($this->clock->timezone());
        $row = $this->dayWork->findDayRow($conn, $userId, $ymd);
        if ($row === null || empty($row['work_start_at'])) {
            return ['ok' => false, 'status' => 409, 'message' => 'Start the day first'];
        }
        if (!empty($row['work_end_at'])) {
            return ['ok' => false, 'status' => 409, 'message' => 'This day has already ended'];
        }
        if (!empty($row['locked_at'])) {
            return ['ok' => false, 'status' => 409, 'message' => 'This day was locked by your project manager'];
        }

        if ($mode === 'cancel') {
            $values = ['extended_until' => null, 'extended_open' => 0];
        } elseif ($mode === 'open') {
            $values = ['extended_until' => null, 'extended_open' => 1];
        } elseif (isset(self::EXTEND_MINUTES[$mode])) {
            $shift = $this->shiftFor($conn, $userId, $ymd);
            $from = $shift !== null ? $this->clockOutBase($ymd, $shift, $row) : $now;
            if ($from < $now) {
                $from = $now;
            }
            $until = $from->modify('+' . self::EXTEND_MINUTES[$mode] . ' minutes');
            $dayEnd = $this->at($ymd, '23:59');
            if ($until > $dayEnd) {
                $until = $dayEnd;
            }
            $values = ['extended_until' => $until->format('H:i') . ':00', 'extended_open' => 0];
        } else {
            return ['ok' => false, 'status' => 400, 'message' => 'mode must be 1h, 2h, open or cancel'];
        }

        if (
            $mode !== 'cancel'
            && !empty($row['auto_closed_at'])
            && ($row['end_source'] ?? null) === WorkerDayWorkService::SOURCE_AUTO
        ) {
            $values += [
                'adjusted_end_time' => null,
                'end_source' => null,
                'auto_closed_at' => null,
                'attendance_flags' => WorkerDayWorkService::formatFlags(
                    $this->toggle(WorkerDayWorkService::parseFlags($row['attendance_flags'] ?? null), self::FLAG_AUTO_CLOSED, false)
                ),
            ];
        }

        $conn->update(self::DAY_TABLE, $values, ['id' => (int) $row['id']]);

        return ['ok' => true, 'row' => $this->dayWork->formatRow((array) $this->dayWork->findDayRow($conn, $userId, $ymd))];
    }

    /**
     * Distinct sites of the worker's published day in visiting order, with arrival records.
     *
     * @return list<array<string, mixed>>
     */
    public function daySites(Connection $conn, int $userId, string $ymd): array
    {
        $slots = (new PublishedScheduleService())->slotsForUser($conn, $userId, $ymd, $ymd);
        $events = $this->siteEvents($conn, $ymd, $ymd, $userId);

        return $this->buildSites($conn, $slots, $events[$userId . '|' . $ymd] ?? []);
    }

    /**
     * "Arrived at site" for the second and later sites of the day. The first arrival is kept.
     *
     * @return array{ok: true, sites: list<array<string, mixed>>}|array{ok: false, status: int, message: string}
     */
    public function recordSiteArrival(
        Connection $conn,
        int $userId,
        string $ymd,
        int $projectId,
        ?float $lat,
        ?float $lng,
        string $source,
    ): array {
        if (!$this->siteEventsAvailable($conn)) {
            return ['ok' => false, 'status' => 503, 'message' => 'Site arrival is not available until database migration is applied'];
        }
        $dayCheck = $this->clock->assertWorkDateIsToday($ymd);
        if (!$dayCheck['ok']) {
            return ['ok' => false, 'status' => 403, 'message' => $dayCheck['message']];
        }
        if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            $lat = null;
            $lng = null;
        }
        $onSchedule = array_filter(
            $this->daySites($conn, $userId, $ymd),
            static fn (array $s): bool => $s['project_id'] === $projectId
        );
        if ($onSchedule === []) {
            return ['ok' => false, 'status' => 404, 'message' => 'This site is not on your schedule today'];
        }

        $conn->executeStatement(
            'INSERT IGNORE INTO ' . self::SITE_TABLE . ' (user_id, work_date, project_id, arrived_at, lat, lng, source)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$userId, $ymd, $projectId, $this->clock->nowSql(), $lat, $lng, $source]
        );

        return ['ok' => true, 'sites' => $this->daySites($conn, $userId, $ymd)];
    }

    /**
     * PM attendance review: every scheduled or punched worker-day in the range (up to today) with
     * shift, punches, paid times, sites and open issues. Locked days have no issues.
     *
     * @return list<array<string, mixed>>
     */
    public function report(Connection $conn, string $fromYmd, string $toYmd, ?\DateTimeImmutable $now = null): array
    {
        $now = ($now ?? $this->clock->now())->setTimezone($this->clock->timezone());
        $today = $this->clock->todayYmd($now);
        if ($toYmd > $today) {
            $toYmd = $today;
        }
        if ($fromYmd > $toYmd) {
            return [];
        }

        $rowsByDate = [];
        if ($this->dayWork->tableExists($conn)) {
            foreach ($this->dayWork->findAllInRange($conn, $fromYmd, $toYmd) as $r) {
                $rowsByDate[substr((string) $r['work_date'], 0, 10)][(int) $r['user_id']] = $r;
            }
        }
        $slotsByKey = [];
        foreach ((new PublishedScheduleService())->slotsInRange($conn, $fromYmd, $toYmd) as $slot) {
            $slotsByKey[$slot['user_id'] . '|' . $slot['work_date']][] = $slot;
        }
        $events = $this->siteEvents($conn, $fromYmd, $toYmd);

        $names = [];
        $pending = [];
        for ($d = new \DateTimeImmutable($fromYmd); $d->format('Y-m-d') <= $toYmd; $d = $d->modify('+1 day')) {
            $ymd = $d->format('Y-m-d');
            $shifts = $this->shiftsOn($conn, $ymd);
            foreach ($shifts as $uid => $shift) {
                $names[$uid] = trim($shift['first_name'] . ' ' . $shift['last_name']);
            }
            $uids = array_unique(array_merge(array_keys($shifts), array_keys($rowsByDate[$ymd] ?? [])));
            foreach ($uids as $uid) {
                $pending[] = [$ymd, (int) $uid, $shifts[$uid] ?? null, $rowsByDate[$ymd][$uid] ?? null];
            }
        }

        $missing = array_values(array_diff(array_unique(array_column($pending, 1)), array_keys($names)));
        if ($missing !== []) {
            $users = $conn->executeQuery(
                'SELECT id, first_name, last_name FROM fw_users WHERE id IN ('
                . implode(', ', array_fill(0, count($missing), '?')) . ')',
                $missing
            )->fetchAllAssociative();
            foreach ($users as $u) {
                $names[(int) $u['id']] = trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? ''));
            }
        }

        $out = [];
        foreach ($pending as [$ymd, $uid, $shift, $row]) {
            $key = $uid . '|' . $ymd;
            $sites = $this->buildSites($conn, $slotsByKey[$key] ?? [], $events[$key] ?? []);
            $out[] = [
                'user_id' => $uid,
                'worker_name' => ($names[$uid] ?? '') !== '' ? $names[$uid] : 'User #' . $uid,
                'work_date' => $ymd,
                'shift' => $shift !== null
                    ? ['start_time' => $shift['start_time'], 'end_time' => $shift['end_time'], 'source' => $shift['source']]
                    : null,
                'day' => $row !== null ? $this->dayWork->formatPayrollRow($row) : null,
                'sites' => $sites,
                'issues' => $this->issuesFor($ymd, $shift, $row, $sites, $now, $today),
            ];
        }

        usort($out, static fn (array $a, array $b): int => [$b['work_date'], $a['worker_name']] <=> [$a['work_date'], $b['worker_name']]);

        return $out;
    }

    /**
     * @param array<string, mixed>|null $shift
     * @param array<string, mixed>|null $row
     * @param list<array<string, mixed>> $sites
     * @return list<array{code: string, label: string}>
     */
    private function issuesFor(
        string $ymd,
        ?array $shift,
        ?array $row,
        array $sites,
        \DateTimeImmutable $now,
        string $today,
    ): array {
        if ($row !== null && !empty($row['locked_at'])) {
            return [];
        }
        $issues = [];
        $isPast = $ymd < $today;
        $flags = WorkerDayWorkService::parseFlags($row['attendance_flags'] ?? null);
        $startSource = $row['start_source'] ?? null;
        $endSource = $row['end_source'] ?? null;
        $startPunch = $this->localTime($row['work_start_at'] ?? null);
        $endPunch = $this->localTime($row['work_end_at'] ?? null);
        $adjustedStart = PublishedScheduleService::hm($row['adjusted_start_time'] ?? null);
        $adjustedEnd = PublishedScheduleService::hm($row['adjusted_end_time'] ?? null);
        $planStart = $shift['start_time'] ?? PublishedScheduleService::hm($row['scheduled_start_time'] ?? null) ?? '—';
        $planEnd = $shift['end_time'] ?? PublishedScheduleService::hm($row['scheduled_end_time'] ?? null) ?? '—';
        $add = static function (string $code, string $label) use (&$issues): void {
            $issues[] = ['code' => $code, 'label' => $label];
        };

        if (
            $shift !== null
            && $startPunch === null
            && $adjustedStart === null
            && $startSource !== WorkerDayWorkService::SOURCE_PM
            && ($isPast || $now >= $this->at($ymd, $shift['start_time'])->modify('+' . self::NO_START_AFTER_MINUTES . ' minutes'))
        ) {
            $add('no_start', "Not clocked in (shift {$planStart}–{$planEnd})");
        }
        if ($startPunch !== null && in_array(self::FLAG_LATE_START, $flags, true) && $startSource !== WorkerDayWorkService::SOURCE_PM) {
            $add('late_start', 'Late start: clocked in at ' . $startPunch->format('H:i') . " (shift starts {$planStart})");
        }
        if ($endPunch === null && in_array(self::FLAG_AUTO_CLOSED, $flags, true) && $endSource === WorkerDayWorkService::SOURCE_AUTO) {
            $add('auto_closed', 'Did not clock out: day closed at ' . ($adjustedEnd ?? $planEnd));
        }
        if ($startPunch !== null && $endPunch === null && $adjustedEnd === null && $endSource !== WorkerDayWorkService::SOURCE_PM) {
            if (!$isPast && !empty($row['extended_open'])) {
                $add('working_late', 'Working late (until done), no End day yet');
            } elseif (
                $isPast
                || ($shift !== null && $now >= $this->clockOutBase($ymd, $shift, $row)->modify('+' . self::AUTO_CLOSE_MINUTES . ' minutes'))
            ) {
                $add('no_end', 'No End day');
            }
        }
        if ($endPunch !== null && in_array(self::FLAG_EARLY_LEAVE, $flags, true) && $endSource !== WorkerDayWorkService::SOURCE_PM) {
            $add('early_leave', 'Left early at ' . $endPunch->format('H:i') . " (shift ends {$planEnd})");
        }
        if ($endPunch !== null && in_array(self::FLAG_OVERTIME, $flags, true) && $endSource !== WorkerDayWorkService::SOURCE_PM) {
            $add('overtime', 'Overtime to approve: clocked out at ' . $endPunch->format('H:i') . " (shift ends {$planEnd})");
        }
        if ($startPunch !== null && !in_array(self::FLAG_REVIEWED, $flags, true)) {
            foreach ($sites as $site) {
                if ($site['is_first'] || $site['arrived_at'] !== null) {
                    continue;
                }
                $expected = $site['expected_start_time'];
                $due = $isPast
                    || $endPunch !== null
                    || ($expected !== null && $now >= $this->at($ymd, $expected)->modify('+' . self::SITE_GRACE_MINUTES . ' minutes'));
                if ($due) {
                    $name = $site['project_name'] ?? ('Project #' . $site['project_id']);
                    $add('site_unconfirmed', "Arrival at {$name} not confirmed");
                }
            }
        }

        return $issues;
    }

    /**
     * @param list<array<string, mixed>> $slots one worker, one day
     * @param array<int, array<string, mixed>> $eventsByProject
     * @return list<array<string, mixed>>
     */
    private function buildSites(Connection $conn, array $slots, array $eventsByProject): array
    {
        usort($slots, static function (array $a, array $b): int {
            $sa = $a['expected_start_time'] ?? null;
            $sb = $b['expected_start_time'] ?? null;
            if ($sa === $sb) {
                return 0;
            }
            if ($sa === null) {
                return 1;
            }
            if ($sb === null) {
                return -1;
            }

            return $sa <=> $sb;
        });

        $sites = [];
        foreach ($slots as $slot) {
            $pid = (int) $slot['project_id'];
            if (isset($sites[$pid])) {
                $end = $slot['expected_end_time'] ?? null;
                if ($end !== null && ($sites[$pid]['expected_end_time'] === null || $end > $sites[$pid]['expected_end_time'])) {
                    $sites[$pid]['expected_end_time'] = $end;
                }
                continue;
            }
            $event = $eventsByProject[$pid] ?? null;
            $sites[$pid] = [
                'project_id' => $pid,
                'project_name' => $slot['project_name'] ?? null,
                'project_address' => $slot['project_address'] ?? null,
                'expected_start_time' => $slot['expected_start_time'] ?? null,
                'expected_end_time' => $slot['expected_end_time'] ?? null,
                'distance_km' => $slot['distance_km'] ?? null,
                'arrived_at' => $event !== null ? $this->clock->toApiIso($event['arrived_at']) : null,
                'arrival_source' => $event !== null ? (string) $event['source'] : null,
                'arrival_distance_m' => $event !== null ? $this->distanceFromSite($conn, $pid, $event) : null,
            ];
        }

        $out = array_values($sites);
        foreach ($out as $i => $site) {
            $out[$i]['is_first'] = $i === 0;
        }

        return $out;
    }

    /** @return array<string, array<int, array<string, mixed>>> keyed "userId|YYYY-MM-DD", then project id */
    private function siteEvents(Connection $conn, string $fromYmd, string $toYmd, ?int $userId = null): array
    {
        if (!$this->siteEventsAvailable($conn)) {
            return [];
        }
        $sql = 'SELECT user_id, work_date, project_id, arrived_at, lat, lng, source
                FROM ' . self::SITE_TABLE . ' WHERE work_date >= ? AND work_date <= ?';
        $params = [$fromYmd, $toYmd];
        if ($userId !== null) {
            $sql .= ' AND user_id = ?';
            $params[] = $userId;
        }
        $out = [];
        foreach ($conn->executeQuery($sql, $params)->fetchAllAssociative() as $e) {
            $out[(int) $e['user_id'] . '|' . substr((string) $e['work_date'], 0, 10)][(int) $e['project_id']] = $e;
        }

        return $out;
    }

    /** Meters between the arrival location and the project site; null without both points. */
    private function distanceFromSite(Connection $conn, int $projectId, array $event): ?int
    {
        if (!is_numeric($event['lat'] ?? null) || !is_numeric($event['lng'] ?? null)) {
            return null;
        }
        if (!array_key_exists($projectId, $this->siteLocations)) {
            $this->siteLocations[$projectId] = (new ProjectSiteLocationService($this->logger))->resolve($conn, $projectId);
        }
        $site = $this->siteLocations[$projectId];
        if ($site === null) {
            return null;
        }
        $lat1 = deg2rad((float) $event['lat']);
        $lat2 = deg2rad($site['lat']);
        $dLat = $lat2 - $lat1;
        $dLng = deg2rad($site['lng'] - (float) $event['lng']);
        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        return (int) round(6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    /**
     * @return list<array{user_id: int, first_name: string, last_name: string, phone: ?string, start_time: string, end_time: string, source: string}>
     */
    private function taskPlanShifts(Connection $conn, string $ymd): array
    {
        $placeholders = implode(', ', array_fill(0, count(self::CLOSED_TASK_STATUSES), '?'));
        $rows = $conn->executeQuery(
            "SELECT u.id AS user_id, u.first_name, u.last_name, u.phone,
                    MIN(t.start_time) AS start_time, MAX(t.end_time) AS end_time
             FROM fw_prj_team_members tm
             INNER JOIN fw_prj_tasks t ON t.id = tm.task_id
             INNER JOIN fw_users u ON u.id = tm.user_id
             INNER JOIN fw_glob_roles r ON r.id = u.role_id AND r.category = 'task'
             WHERE t.start_planned IS NOT NULL
               AND DATE(t.start_planned) <= ?
               AND DATE(COALESCE(t.end_planned, t.start_planned)) >= ?
               AND t.start_time IS NOT NULL
               AND t.end_time IS NOT NULL
               AND (t.status IS NULL OR t.status NOT IN ({$placeholders}))
               AND u.status = 1
               AND u.archived_at IS NULL
             GROUP BY u.id, u.first_name, u.last_name, u.phone
             ORDER BY u.id",
            array_merge([$ymd, $ymd], self::CLOSED_TASK_STATUSES)
        )->fetchAllAssociative();

        return array_map(fn (array $r): array => $this->shiftRow(
            $r,
            substr((string) $r['start_time'], 0, 5),
            substr((string) $r['end_time'], 0, 5),
            'tasks'
        ), $rows);
    }

    /**
     * @param array<string, mixed> $user
     * @return array{user_id: int, first_name: string, last_name: string, phone: ?string, start_time: string, end_time: string, source: string}
     */
    private function shiftRow(array $user, string $start, string $end, string $source): array
    {
        return [
            'user_id' => (int) $user['user_id'],
            'first_name' => trim((string) ($user['first_name'] ?? '')),
            'last_name' => trim((string) ($user['last_name'] ?? '')),
            'phone' => $user['phone'] !== null ? (string) $user['phone'] : null,
            'start_time' => $start,
            'end_time' => $end,
            'source' => $source,
        ];
    }

    /**
     * @param list<string> $flags
     * @return list<string>
     */
    private function toggle(array $flags, string $flag, bool $on): array
    {
        $flags = array_values(array_diff($flags, [$flag]));
        if ($on) {
            $flags[] = $flag;
        }

        return $flags;
    }

    private function at(string $ymd, string $hm): \DateTimeImmutable
    {
        return new \DateTimeImmutable($ymd . ' ' . $hm, $this->clock->timezone());
    }

    /** DB DATETIME (app wall clock) to a DateTime in the app timezone. */
    private function localTime(mixed $raw): ?\DateTimeImmutable
    {
        $iso = $this->clock->toApiIso($raw);

        return $iso === null ? null : (new \DateTimeImmutable($iso))->setTimezone($this->clock->timezone());
    }
}
