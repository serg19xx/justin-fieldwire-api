<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;

/**
 * Builds a half-hour day schedule for PM manual slot selection.
 * Booking date must be at least 3 days from today. Selected slots are held while invite is pending (3 days).
 */
class MeetingSlotFinderService
{
    public const DEFAULT_TIMEZONE = 'America/Toronto';
    public const MIN_DAYS_AHEAD = 3;
    public const HOLD_DAYS = 3;
    private const WORKDAY_START_HOUR = 9;
    private const WORKDAY_END_HOUR = 17;
    private const SLOT_STEP_MINUTES = 30;
    private const MAX_SELECTED_SLOTS = 3;

    /**
     * @return array{
     *     success: bool,
     *     date?: string,
     *     min_date?: string,
     *     duration_minutes?: int,
     *     timezone?: string,
     *     hold_days?: int,
     *     schedule?: list<array{time: string, available: bool, reason: string|null}>,
     *     available_count?: int,
     *     error?: string
     * }
     */
    public function getDaySchedule(int $userId, string $date, int $durationMinutes, string $timezone): array
    {
        $durationMinutes = $this->normalizeDuration($durationMinutes);
        $timezone = $this->normalizeTimezone($timezone);
        $tz = new DateTimeZone($timezone);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['success' => false, 'error' => 'Invalid date format'];
        }

        try {
            $day = new DateTimeImmutable($date, $tz);
        } catch (\Throwable) {
            return ['success' => false, 'error' => 'Invalid date'];
        }

        $today = new DateTimeImmutable('today', $tz);
        $minDate = $today->modify('+' . self::MIN_DAYS_AHEAD . ' day');

        if ($day < $minDate->setTime(0, 0, 0)) {
            return [
                'success' => false,
                'error' => 'Meeting date must be at least ' . self::MIN_DAYS_AHEAD . ' days from today',
                'min_date' => $minDate->format('Y-m-d'),
            ];
        }

        try {
            $conn = Database::getConnection();
            $dayStart = $day->setTime(0, 0, 0);
            $dayEnd = $day->setTime(23, 59, 59);
            $busy = $this->loadBusyIntervals($conn, $userId, $dayStart, $dayEnd, $tz);
            $schedule = $this->buildDaySchedule($day, $durationMinutes, $busy, $tz);
            $availableCount = count(array_filter($schedule, static fn (array $row): bool => $row['available']));

            return [
                'success' => true,
                'date' => $date,
                'min_date' => $minDate->format('Y-m-d'),
                'duration_minutes' => $durationMinutes,
                'timezone' => $timezone,
                'hold_days' => self::HOLD_DAYS,
                'schedule' => $schedule,
                'available_count' => $availableCount,
            ];
        } catch (\Throwable) {
            return ['success' => false, 'error' => 'Failed to load day schedule'];
        }
    }

    /**
     * @param list<string> $times HH:MM
     * @return array{success: bool, slots?: list<array{date: string, time: string, label: string}>, error?: string}
     */
    public function validateSelectedSlots(
        int $userId,
        string $date,
        array $times,
        int $durationMinutes,
        string $timezone,
    ): array {
        $durationMinutes = $this->normalizeDuration($durationMinutes);
        $timezone = $this->normalizeTimezone($timezone);

        $times = array_values(array_unique(array_map(
            static fn (string $time): string => substr(trim($time), 0, 5),
            $times,
        )));

        if ($times === []) {
            return ['success' => false, 'error' => 'Select at least one time slot'];
        }

        if (count($times) > self::MAX_SELECTED_SLOTS) {
            return ['success' => false, 'error' => 'Select no more than ' . self::MAX_SELECTED_SLOTS . ' time slots'];
        }

        foreach ($times as $time) {
            if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
                return ['success' => false, 'error' => 'Invalid time slot format'];
            }
        }

        sort($times);

        $scheduleResult = $this->getDaySchedule($userId, $date, $durationMinutes, $timezone);
        if (!$scheduleResult['success']) {
            return ['success' => false, 'error' => $scheduleResult['error'] ?? 'Invalid meeting date'];
        }

        $availableMap = [];
        foreach ($scheduleResult['schedule'] ?? [] as $row) {
            $availableMap[(string) $row['time']] = (bool) $row['available'];
        }

        $tz = new DateTimeZone($timezone);
        $slots = [];
        foreach ($times as $time) {
            if (!array_key_exists($time, $availableMap) || !$availableMap[$time]) {
                return ['success' => false, 'error' => 'One or more selected slots are no longer available'];
            }

            $dt = $this->buildDateTime($date, $time . ':00', $tz);
            $slots[] = [
                'date' => $date,
                'time' => $time . ':00',
                'label' => $this->formatSlotLabel($dt),
            ];
        }

        return ['success' => true, 'slots' => $slots];
    }

    private function normalizeDuration(int $durationMinutes): int
    {
        if ($durationMinutes < 5 || $durationMinutes > 480) {
            return 30;
        }

        return $durationMinutes;
    }

    private function normalizeTimezone(string $timezone): string
    {
        $timezone = trim($timezone);
        if ($timezone === '') {
            return self::DEFAULT_TIMEZONE;
        }

        try {
            new DateTimeZone($timezone);
            return $timezone;
        } catch (\Throwable) {
            return self::DEFAULT_TIMEZONE;
        }
    }

    /**
     * @return list<array{0: DateTimeImmutable, 1: DateTimeImmutable, 2: string}>
     */
    private function loadBusyIntervals(
        Connection $conn,
        int $userId,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
        DateTimeZone $tz,
    ): array {
        $busy = [];

        $events = $conn->executeQuery(
            'SELECT start_at, end_at, all_day, title
             FROM fw_calendar_events
             WHERE user_id = ?
               AND start_at <= ?
               AND COALESCE(end_at, start_at) >= ?',
            [
                $userId,
                $windowEnd->format('Y-m-d H:i:s'),
                $windowStart->format('Y-m-d H:i:s'),
            ],
        )->fetchAllAssociative();

        foreach ($events as $event) {
            $start = new DateTimeImmutable((string) $event['start_at'], $tz);
            if ((int) ($event['all_day'] ?? 0) === 1) {
                $dayStart = $start->setTime(0, 0, 0);
                $busy[] = [$dayStart, $dayStart->modify('+1 day'), 'All-day event'];
                continue;
            }

            $endRaw = $event['end_at'] ?? null;
            $end = $endRaw !== null
                ? new DateTimeImmutable((string) $endRaw, $tz)
                : $start->modify('+30 minutes');
            if ($end <= $start) {
                $end = $start->modify('+30 minutes');
            }

            $label = trim((string) ($event['title'] ?? ''));
            $busy[] = [$start, $end, $label !== '' ? $label : 'Calendar event'];
        }

        $inviteRows = $this->hasSlotDateColumns($conn)
            ? $conn->executeQuery(
                'SELECT meeting_date, slot1_date, slot2_date, slot3_date,
                        slot1_time, slot2_time, slot3_time, duration_minutes, timezone, client_name
                 FROM fw_sms_meeting_invites
                 WHERE user_id = ?
                   AND status = ?
                   AND expires_at > UTC_TIMESTAMP(3)',
                [$userId, 'pending'],
            )->fetchAllAssociative()
            : $conn->executeQuery(
                'SELECT meeting_date, slot1_time, slot2_time, slot3_time, duration_minutes, timezone, client_name
                 FROM fw_sms_meeting_invites
                 WHERE user_id = ?
                   AND status = ?
                   AND expires_at > UTC_TIMESTAMP(3)',
                [$userId, 'pending'],
            )->fetchAllAssociative();

        foreach ($inviteRows as $invite) {
            $inviteTz = $this->normalizeTimezone((string) ($invite['timezone'] ?? self::DEFAULT_TIMEZONE));
            $inviteTimezone = new DateTimeZone($inviteTz);
            $duration = $this->normalizeDuration((int) ($invite['duration_minutes'] ?? 30));
            $fallbackDate = (string) $invite['meeting_date'];
            $clientName = trim((string) ($invite['client_name'] ?? ''));

            $slotDefs = [
                [(string) ($invite['slot1_date'] ?? $fallbackDate), (string) $invite['slot1_time']],
                [(string) ($invite['slot2_date'] ?? $fallbackDate), (string) $invite['slot2_time']],
                [(string) ($invite['slot3_date'] ?? $fallbackDate), (string) $invite['slot3_time']],
            ];

            foreach ($slotDefs as [$slotDate, $time]) {
                if ($slotDate === '' || $time === '') {
                    continue;
                }
                $start = $this->buildDateTime($slotDate, $time, $inviteTimezone);
                $reason = $clientName !== ''
                    ? 'Held for SMS invite (' . $clientName . ')'
                    : 'Held for pending SMS invite';
                $busy[] = [$start, $start->modify('+' . $duration . ' minutes'), $reason];
            }
        }

        return $busy;
    }

    /**
     * @param list<array{0: DateTimeImmutable, 1: DateTimeImmutable, 2: string}> $busy
     * @return list<array{time: string, available: bool, reason: string|null}>
     */
    private function buildDaySchedule(
        DateTimeImmutable $day,
        int $durationMinutes,
        array $busy,
        DateTimeZone $tz,
    ): array {
        $schedule = [];
        $dayStart = $day->setTime(self::WORKDAY_START_HOUR, 0, 0);
        $dayEnd = $day->setTime(self::WORKDAY_END_HOUR, 0, 0);
        $cursor = $dayStart;

        while ($cursor->modify('+' . $durationMinutes . ' minutes') <= $dayEnd) {
            $slotEnd = $cursor->modify('+' . $durationMinutes . ' minutes');
            $reason = $this->findOverlapReason($cursor, $slotEnd, $busy);

            $schedule[] = [
                'time' => $cursor->format('H:i'),
                'available' => $reason === null,
                'reason' => $reason,
            ];

            $cursor = $cursor->modify('+' . self::SLOT_STEP_MINUTES . ' minutes');
        }

        return $schedule;
    }

    /**
     * @param list<array{0: DateTimeImmutable, 1: DateTimeImmutable, 2: string}> $busy
     */
    private function findOverlapReason(
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        array $busy,
    ): ?string {
        foreach ($busy as [$busyStart, $busyEnd, $reason]) {
            if ($start < $busyEnd && $end > $busyStart) {
                return $reason;
            }
        }

        return null;
    }

    private function buildDateTime(string $date, string $time, DateTimeZone $timezone): DateTimeImmutable
    {
        $timePart = substr($time, 0, 8);
        if (strlen($timePart) === 5) {
            $timePart .= ':00';
        }

        return new DateTimeImmutable($date . ' ' . $timePart, $timezone);
    }

    private function formatSlotLabel(DateTimeImmutable $dt): string
    {
        $time = $dt->format('g:i A');
        if (str_ends_with($time, ':00 AM') || str_ends_with($time, ':00 PM')) {
            $time = str_replace(':00 ', ' ', $time);
        }

        return $dt->format('M j, Y') . ' at ' . $time;
    }

    private function hasSlotDateColumns(Connection $conn): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        try {
            $result = $conn->executeQuery(
                "SHOW COLUMNS FROM fw_sms_meeting_invites LIKE 'slot1_date'",
            )->fetchOne();
            $cached = $result !== false && $result !== null;
        } catch (\Throwable) {
            $cached = false;
        }

        return $cached;
    }
}
