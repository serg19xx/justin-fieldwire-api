<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\Support\ClientCommsTestRedirect;
use App\Support\ClientRegistryContacts;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Monolog\Logger;

class SmsMeetingInviteService
{
    private const DEFAULT_TIMEZONE = 'America/Toronto';
    /** Pending invite holds selected slots for 3 days (client reply window). */
    private const DEFAULT_EXPIRY_HOURS = 72;
    private const ALLOWED_ROLES = ['admin', 'project_manager'];
    private const MAX_OFFERED_SLOTS = 3;

    public function __construct(
        private readonly Logger $logger,
        private readonly TwilioService $twilioService,
        private readonly MeetingSlotFinderService $slotFinder = new MeetingSlotFinderService(),
    ) {
    }

    /**
     * @param array{
     *     meeting_date: string,
     *     slots: array<int, string>,
     *     duration_minutes?: int,
     *     title?: string,
     *     timezone?: string
     * } $payload
     * @return array{success: bool, invite_id?: int, message?: string, sent_to?: string, test_mode?: bool, error?: string, slots?: list<array{date: string, time: string, label: string}>}
     */
    public function sendInvite(int $userId, string $clientType, int $clientId, array $payload): array
    {
        $row = ClientRegistryContacts::fetchRow($clientType, $clientId);
        if ($row === null) {
            return ['success' => false, 'error' => 'Client not found'];
        }

        $phone = ClientRegistryContacts::resolvePhone($clientType, $row);
        if ($phone === null && ClientCommsTestRedirect::isEnabled()) {
            $testPhone = trim((string) ($_ENV['CLIENTS_COMMS_TEST_PHONE'] ?? ''));
            if ($testPhone !== '') {
                $phone = $testPhone;
            }
        }
        if ($phone === null) {
            return ['success' => false, 'error' => 'Client has no phone number on file'];
        }

        $parsed = $this->resolveInviteSlots($userId, $payload);
        if ($parsed === null) {
            return ['success' => false, 'error' => 'Invalid meeting date or time slots'];
        }
        if (isset($parsed['error'])) {
            return ['success' => false, 'error' => (string) $parsed['error']];
        }

        $clientName = ClientRegistryContacts::displayName($clientType, $row);
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            $title = 'Call with ' . $clientName;
        }

        $redirect = ClientCommsTestRedirect::phone($phone);
        $normalized = $this->normalizePhoneDigits($redirect['destination']);
        if ($normalized === '') {
            return ['success' => false, 'error' => 'Invalid phone number format'];
        }

        $sender = $this->resolveSenderProfile($userId);

        $smsBody = $this->buildInviteSms(
            $clientName,
            $sender['name'],
            $sender['role'],
            $title,
            $parsed['duration_minutes'],
            $parsed['slot_options'],
            $parsed['timezone'],
        );

        if (!$this->twilioService->sendSms($redirect['destination'], $smsBody)) {
            return ['success' => false, 'error' => 'Failed to send SMS'];
        }

        try {
            $conn = Database::getConnection();
            $this->cancelPendingInvites($conn, $normalized);

            $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->modify('+' . self::DEFAULT_EXPIRY_HOURS . ' hours')
                ->format('Y-m-d H:i:s.v');

            $insertParams = $this->buildInsertParams(
                $conn,
                $userId,
                $clientType,
                $clientId,
                $clientName,
                $phone,
                $normalized,
                $parsed,
                $title,
                $expiresAt,
            );

            $conn->executeStatement($insertParams['sql'], $insertParams['values']);

            $inviteId = (int) $conn->lastInsertId();

            $this->logger->info('SMS meeting invite sent', [
                'invite_id' => $inviteId,
                'user_id' => $userId,
                'client_type' => $clientType,
                'client_id' => $clientId,
                'test_mode' => $redirect['test_mode'],
            ]);

            return [
                'success' => true,
                'invite_id' => $inviteId,
                'message' => 'Meeting invite sent',
                'sent_to' => $redirect['destination'],
                'original_to' => $redirect['original'],
                'test_mode' => $redirect['test_mode'],
                'slots' => array_map(
                    static fn (array $slot): array => [
                        'date' => $slot['date'],
                        'time' => substr($slot['time'], 0, 5),
                        'label' => $slot['label'] ?? '',
                    ],
                    $parsed['slot_options'],
                ),
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Failed to store SMS meeting invite', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'Failed to save invite record'];
        }
    }

    /**
     * Process inbound SMS reply from Twilio webhook.
     *
     * @param array<string, mixed> $twilioPayload
     * @return array{success: bool, reply_sms?: string, error?: string}
     */
    public function handleInboundSms(array $twilioPayload): array
    {
        $fromRaw = trim((string) ($twilioPayload['From'] ?? ''));
        $body = trim((string) ($twilioPayload['Body'] ?? ''));

        if ($fromRaw === '' || $body === '') {
            return ['success' => true, 'reply_sms' => ''];
        }

        $normalized = $this->normalizePhoneDigits($fromRaw);
        if ($normalized === '') {
            return ['success' => true, 'reply_sms' => ''];
        }

        try {
            $conn = Database::getConnection();
            $invite = $this->findActivePendingInvite($conn, $normalized);
            if ($invite === null) {
                return [
                    'success' => true,
                    'reply_sms' => 'FieldWire: no active meeting request found. Please contact your project manager directly.',
                ];
            }

            $offered = $this->offeredSlotsFromInvite($invite);
            $maxSlot = count($offered);
            if ($maxSlot === 0) {
                return [
                    'success' => true,
                    'reply_sms' => 'FieldWire: no active meeting request found. Please contact your project manager directly.',
                ];
            }

            $slot = $this->parseSlotChoice($body, $maxSlot);
            if ($slot === null) {
                return [
                    'success' => true,
                    'reply_sms' => 'FieldWire: please reply with ' . $this->formatReplyOptionsList($maxSlot) . ' to choose your preferred meeting time.',
                ];
            }

            $chosenIndex = $offered[$slot - 1]['index'];
            return $this->confirmInvite($conn, $invite, $chosenIndex);
        } catch (\Throwable $e) {
            $this->logger->error('Inbound SMS meeting invite failed', [
                'from' => $fromRaw,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{success: bool, invite?: array<string, mixed>, error?: string}
     */
    public function getLatestInvite(int $userId, string $clientType, int $clientId): array
    {
        if (!ClientRegistryContacts::isAllowedType($clientType)) {
            return ['success' => false, 'error' => 'Invalid client type'];
        }

        try {
            $conn = Database::getConnection();
            $row = $conn->executeQuery(
                'SELECT * FROM fw_sms_meeting_invites
                 WHERE user_id = ? AND client_type = ? AND client_id = ?
                 ORDER BY id DESC LIMIT 1',
                [$userId, $clientType, $clientId],
            )->fetchAssociative();

            if (!is_array($row)) {
                return ['success' => true, 'invite' => null];
            }

            return ['success' => true, 'invite' => $this->mapInviteRow($row)];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Failed to load invite'];
        }
    }

    public static function isAllowedRole(?string $roleCode): bool
    {
        return in_array(strtolower((string) $roleCode), self::ALLOWED_ROLES, true);
    }

    /**
     * @return array{success: bool, date?: string, min_date?: string, duration_minutes?: int, timezone?: string, hold_days?: int, schedule?: list<array{time: string, available: bool, reason: string|null}>, available_count?: int, error?: string}
     */
    public function getDaySchedule(int $userId, array $payload): array
    {
        $date = trim((string) ($payload['date'] ?? ''));
        $duration = (int) ($payload['duration_minutes'] ?? 30);
        $timezone = trim((string) ($payload['timezone'] ?? self::DEFAULT_TIMEZONE));
        if ($timezone === '') {
            $timezone = self::DEFAULT_TIMEZONE;
        }

        return $this->slotFinder->getDaySchedule($userId, $date, $duration, $timezone);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{
     *     duration_minutes: int,
     *     timezone: string,
     *     meeting_date: string,
     *     slot_options: list<array{date: string, time: string, label?: string}>,
     *     error?: string
     * }|null
     */
    private function resolveInviteSlots(int $userId, array $payload): ?array
    {
        $meetingDate = trim((string) ($payload['meeting_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $meetingDate)) {
            return null;
        }

        $slotsRaw = $payload['slots'] ?? null;
        if (!is_array($slotsRaw) || $slotsRaw === []) {
            return ['duration_minutes' => 30, 'timezone' => self::DEFAULT_TIMEZONE, 'meeting_date' => $meetingDate, 'slot_options' => [], 'error' => 'Select at least one time slot'];
        }

        $times = [];
        foreach ($slotsRaw as $slot) {
            $normalized = $this->normalizeTimeInput((string) $slot);
            if ($normalized === null) {
                return null;
            }
            $times[] = substr($normalized, 0, 5);
        }

        $duration = (int) ($payload['duration_minutes'] ?? 30);
        if ($duration < 5 || $duration > 480) {
            $duration = 30;
        }

        $timezone = trim((string) ($payload['timezone'] ?? self::DEFAULT_TIMEZONE));
        if ($timezone === '') {
            $timezone = self::DEFAULT_TIMEZONE;
        }

        try {
            new DateTimeZone($timezone);
        } catch (\Throwable) {
            $timezone = self::DEFAULT_TIMEZONE;
        }

        $validated = $this->slotFinder->validateSelectedSlots($userId, $meetingDate, $times, $duration, $timezone);
        if (!$validated['success']) {
            return [
                'duration_minutes' => $duration,
                'timezone' => $timezone,
                'meeting_date' => $meetingDate,
                'slot_options' => [],
                'error' => $validated['error'] ?? 'Invalid time slots',
            ];
        }

        return [
            'duration_minutes' => $duration,
            'timezone' => $timezone,
            'meeting_date' => $meetingDate,
            'slot_options' => $validated['slots'] ?? [],
        ];
    }

    /**
     * @param array{duration_minutes: int, timezone: string, meeting_date: string, slot_options: list<array{date: string, time: string, label?: string}>} $parsed
     * @return array{sql: string, values: list<mixed>}
     */
    private function buildInsertParams(
        Connection $conn,
        int $userId,
        string $clientType,
        int $clientId,
        string $clientName,
        string $phone,
        string $normalized,
        array $parsed,
        string $title,
        string $expiresAt,
    ): array {
        $options = $parsed['slot_options'];
        $date = $parsed['meeting_date'];
        $slotTimes = [
            $options[0]['time'] ?? null,
            $options[1]['time'] ?? null,
            $options[2]['time'] ?? null,
        ];

        if ($this->hasSlotDateColumns($conn)) {
            return [
                'sql' => 'INSERT INTO fw_sms_meeting_invites
                 (user_id, client_type, client_id, client_name, client_phone, client_phone_normalized,
                  meeting_date, slot1_date, slot2_date, slot3_date,
                  slot1_time, slot2_time, slot3_time, duration_minutes, meeting_title,
                  timezone, status, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                'values' => [
                    $userId,
                    $clientType,
                    $clientId,
                    $clientName,
                    $phone,
                    $normalized,
                    $date,
                    $date,
                    isset($options[1]) ? $date : null,
                    isset($options[2]) ? $date : null,
                    $slotTimes[0],
                    $slotTimes[1],
                    $slotTimes[2],
                    $parsed['duration_minutes'],
                    $title,
                    $parsed['timezone'],
                    'pending',
                    $expiresAt,
                ],
            ];
        }

        return [
            'sql' => 'INSERT INTO fw_sms_meeting_invites
             (user_id, client_type, client_id, client_name, client_phone, client_phone_normalized,
              meeting_date, slot1_time, slot2_time, slot3_time, duration_minutes, meeting_title,
              timezone, status, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'values' => [
                $userId,
                $clientType,
                $clientId,
                $clientName,
                $phone,
                $normalized,
                $date,
                $slotTimes[0],
                $slotTimes[1],
                $slotTimes[2],
                $parsed['duration_minutes'],
                $title,
                $parsed['timezone'],
                'pending',
                $expiresAt,
            ],
        ];
    }

    /**
     * @return list<array{index: int, time: string, date: string}>
     */
    private function offeredSlotsFromInvite(array $invite): array
    {
        $slots = [];
        $defs = [
            1 => [(string) ($invite['slot1_date'] ?? $invite['meeting_date']), (string) ($invite['slot1_time'] ?? '')],
            2 => [(string) ($invite['slot2_date'] ?? $invite['meeting_date']), (string) ($invite['slot2_time'] ?? '')],
            3 => [(string) ($invite['slot3_date'] ?? $invite['meeting_date']), (string) ($invite['slot3_time'] ?? '')],
        ];

        foreach ($defs as $index => [$date, $time]) {
            $time = trim($time);
            if ($time === '' || $time === '00:00:00') {
                continue;
            }
            $slots[] = ['index' => $index, 'time' => $time, 'date' => $date];
        }

        return $slots;
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

    private function resolveSlotDate(array $invite, int $slot): string
    {
        $fallback = (string) ($invite['meeting_date'] ?? '');
        $field = match ($slot) {
            1 => 'slot1_date',
            2 => 'slot2_date',
            3 => 'slot3_date',
            default => '',
        };

        if ($field !== '' && isset($invite[$field]) && $invite[$field] !== null && $invite[$field] !== '') {
            return (string) $invite[$field];
        }

        return $fallback;
    }

    /**
     * @return array{name: string, role: string}
     */
    private function resolveSenderProfile(int $userId): array
    {
        try {
            $conn = Database::getConnection();
            $row = $conn->executeQuery(
                'SELECT first_name, last_name, email, job_title, role_name, role_code
                 FROM fw_v_users WHERE id = ? LIMIT 1',
                [$userId],
            )->fetchAssociative();

            if (!is_array($row)) {
                return ['name' => 'FieldWire', 'role' => ''];
            }

            $first = trim((string) ($row['first_name'] ?? ''));
            $last = trim((string) ($row['last_name'] ?? ''));
            $name = trim($first . ' ' . $last);
            if ($name === '') {
                $name = trim((string) ($row['email'] ?? '')) ?: 'FieldWire';
            }

            $jobTitle = trim((string) ($row['job_title'] ?? ''));
            $role = $jobTitle !== ''
                ? $jobTitle
                : $this->formatRoleLabel(
                    isset($row['role_name']) ? (string) $row['role_name'] : null,
                    isset($row['role_code']) ? (string) $row['role_code'] : null,
                );

            return ['name' => $name, 'role' => $role];
        } catch (\Throwable) {
            return ['name' => 'FieldWire', 'role' => ''];
        }
    }

    private function formatRoleLabel(?string $roleName, ?string $roleCode): string
    {
        $roleName = trim((string) $roleName);
        if ($roleName !== '') {
            return $roleName;
        }

        return match (strtolower(trim((string) $roleCode))) {
            'project_manager' => 'Project Manager',
            'admin' => 'Administrator',
            default => 'FieldWire team',
        };
    }

    private function formatDurationLabel(int $durationMinutes): string
    {
        if ($durationMinutes >= 60 && $durationMinutes % 60 === 0) {
            $hours = (int) ($durationMinutes / 60);
            return $hours === 1 ? '1 hour' : $hours . ' hours';
        }

        if ($durationMinutes >= 60) {
            $hours = intdiv($durationMinutes, 60);
            $minutes = $durationMinutes % 60;
            return $hours . ' hr ' . $minutes . ' min';
        }

        return $durationMinutes . ' min';
    }

    /**
     * @param list<array{date: string, time: string, label?: string}> $slotOptions
     */
    private function buildInviteSms(
        string $clientName,
        string $senderName,
        string $senderRole,
        string $meetingTitle,
        int $durationMinutes,
        array $slotOptions,
        string $timezone,
    ): string {
        $durationLabel = $this->formatDurationLabel($durationMinutes);

        $fromLine = $senderName;
        if ($senderRole !== '') {
            $fromLine .= ' (' . $senderRole . ')';
        }

        $lines = [
            'FieldWire — meeting request',
            '',
            'Hi ' . $clientName . ',',
            '',
            $fromLine . ' would like to schedule a call with you.',
            '',
            'Topic: ' . $meetingTitle,
            'Date: ' . $this->formatDateLabel((string) ($slotOptions[0]['date'] ?? ''), $timezone),
            'Duration: ' . $durationLabel,
            '',
            'Reply with your preferred time:',
        ];

        foreach ($slotOptions as $index => $slot) {
            $lines[] = ($index + 1) . ') ' . $this->formatTimeLabel(substr((string) $slot['time'], 0, 5));
        }

        $lines[] = '';
        $lines[] = $this->formatReplyInstruction(count($slotOptions));

        return implode("\n", $lines);
    }

    private function formatReplyInstruction(int $count): string
    {
        return 'Text back ' . $this->formatReplyOptionsList($count) . '.';
    }

    private function formatReplyOptionsList(int $count): string
    {
        return match ($count) {
            1 => '1',
            2 => '1 or 2',
            default => '1, 2, or 3',
        };
    }

    private function parseSlotChoice(string $body, int $maxSlot): ?int
    {
        $clean = strtolower(trim($body));
        $clean = preg_replace('/[^0-9]/', '', $clean) ?? '';
        if ($clean === '') {
            return null;
        }

        $choice = (int) $clean;
        if ($choice >= 1 && $choice <= $maxSlot) {
            return $choice;
        }

        if (preg_match('/^(?:option\s*)?([1-3])\.?$/i', trim($body), $m)) {
            $choice = (int) $m[1];
            return $choice >= 1 && $choice <= $maxSlot ? $choice : null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $invite
     * @return array{success: bool, reply_sms?: string, error?: string}
     */
    private function confirmInvite(Connection $conn, array $invite, int $slot): array
    {
        $inviteId = (int) $invite['id'];
        $slotTimes = [
            1 => (string) $invite['slot1_time'],
            2 => (string) $invite['slot2_time'],
            3 => (string) $invite['slot3_time'],
        ];
        $chosenTime = $slotTimes[$slot] ?? null;
        if ($chosenTime === null) {
            return ['success' => true, 'reply_sms' => 'FieldWire: invalid choice. Please reply with 1, 2, or 3.'];
        }

        $timezone = (string) ($invite['timezone'] ?? self::DEFAULT_TIMEZONE);
        $meetingDate = $this->resolveSlotDate($invite, $slot);
        $durationMinutes = (int) ($invite['duration_minutes'] ?? 30);
        $userId = (int) $invite['user_id'];
        $clientName = (string) $invite['client_name'];
        $title = (string) $invite['meeting_title'];

        $startAt = $this->buildDateTime($meetingDate, $chosenTime, $timezone);
        $endAt = $startAt->modify('+' . $durationMinutes . ' minutes');

        $description = sprintf(
            'Confirmed via SMS (option %d). Client: %s.',
            $slot,
            $clientName,
        );

        $conn->beginTransaction();
        try {
            $conn->executeStatement(
                'INSERT INTO fw_calendar_events
                 (user_id, project_id, title, description, location, start_at, end_at, all_day, requires_presence)
                 VALUES (?, NULL, ?, ?, NULL, ?, ?, 0, 1)',
                [
                    $userId,
                    $title,
                    $description,
                    $startAt->format('Y-m-d H:i:s'),
                    $endAt->format('Y-m-d H:i:s'),
                ],
            );

            $eventId = (int) $conn->lastInsertId();

            $conn->executeStatement(
                'UPDATE fw_sms_meeting_invites
                 SET status = ?, selected_slot = ?, calendar_event_id = ?, confirmed_at = UTC_TIMESTAMP(3)
                 WHERE id = ? AND status = ?',
                ['confirmed', $slot, $eventId, $inviteId, 'pending'],
            );

            $conn->commit();

            $this->logger->info('SMS meeting invite confirmed', [
                'invite_id' => $inviteId,
                'calendar_event_id' => $eventId,
                'slot' => $slot,
            ]);

            $timeLabel = $this->formatTimeLabel(substr($chosenTime, 0, 5));
            $dateLabel = $this->formatDateLabel($meetingDate, $timezone);
            $sender = $this->resolveSenderProfile($userId);
            $senderLine = $sender['name'];
            if ($sender['role'] !== '') {
                $senderLine .= ', ' . $sender['role'];
            }

            return [
                'success' => true,
                'reply_sms' => implode("\n", [
                    'FieldWire — confirmed',
                    '',
                    'Thank you, ' . $clientName . '!',
                    '',
                    $title,
                    $dateLabel . ' at ' . $timeLabel,
                    '',
                    '— ' . $senderLine,
                ]),
            ];
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findActivePendingInvite(Connection $conn, string $normalizedPhone): ?array
    {
        $this->expireStaleInvites($conn);

        $row = $conn->executeQuery(
            'SELECT * FROM fw_sms_meeting_invites
             WHERE client_phone_normalized = ?
               AND status = ?
               AND expires_at > UTC_TIMESTAMP(3)
             ORDER BY id DESC
             LIMIT 1',
            [$normalizedPhone, 'pending'],
        )->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    private function cancelPendingInvites(Connection $conn, string $normalizedPhone): void
    {
        $conn->executeStatement(
            'UPDATE fw_sms_meeting_invites
             SET status = ?
             WHERE client_phone_normalized = ? AND status = ?',
            ['cancelled', $normalizedPhone, 'pending'],
        );
    }

    private function expireStaleInvites(Connection $conn): void
    {
        $conn->executeStatement(
            'UPDATE fw_sms_meeting_invites
             SET status = ?
             WHERE status = ? AND expires_at <= UTC_TIMESTAMP(3)',
            ['expired', 'pending'],
        );
    }

    private function normalizePhoneDigits(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($digits) === 10) {
            $digits = '1' . $digits;
        }

        return $digits;
    }

    private function normalizeTimeInput(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^\d{1,2}:\d{2}$/', $value)) {
            [$h, $m] = array_map('intval', explode(':', $value));
            if ($h >= 0 && $h <= 23 && $m >= 0 && $m <= 59) {
                return sprintf('%02d:%02d:00', $h, $m);
            }
        }

        return null;
    }

    private function buildDateTime(string $date, string $time, string $timezone): DateTimeImmutable
    {
        $timePart = substr($time, 0, 8);
        if (strlen($timePart) === 5) {
            $timePart .= ':00';
        }

        return new DateTimeImmutable($date . ' ' . $timePart, new DateTimeZone($timezone));
    }

    private function formatTimeLabel(string $time): string
    {
        $parts = explode(':', $time);
        $hour = (int) ($parts[0] ?? 0);
        $minute = (int) ($parts[1] ?? 0);
        $suffix = $hour >= 12 ? 'PM' : 'AM';
        $hour12 = $hour % 12;
        if ($hour12 === 0) {
            $hour12 = 12;
        }

        return $minute === 0
            ? $hour12 . ' ' . $suffix
            : sprintf('%d:%02d %s', $hour12, $minute, $suffix);
    }

    private function formatDateLabel(string $date, string $timezone): string
    {
        try {
            $dt = new DateTimeImmutable($date, new DateTimeZone($timezone));
            return $dt->format('M j, Y');
        } catch (\Throwable) {
            return $date;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapInviteRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'status' => (string) $row['status'],
            'meeting_date' => (string) $row['meeting_date'],
            'slot1_date' => (string) ($row['slot1_date'] ?? $row['meeting_date']),
            'slot2_date' => (string) ($row['slot2_date'] ?? $row['meeting_date']),
            'slot3_date' => (string) ($row['slot3_date'] ?? $row['meeting_date']),
            'slot1_time' => substr((string) $row['slot1_time'], 0, 5),
            'slot2_time' => $row['slot2_time'] !== null ? substr((string) $row['slot2_time'], 0, 5) : null,
            'slot3_time' => $row['slot3_time'] !== null ? substr((string) $row['slot3_time'], 0, 5) : null,
            'duration_minutes' => (int) $row['duration_minutes'],
            'meeting_title' => (string) $row['meeting_title'],
            'selected_slot' => $row['selected_slot'] !== null ? (int) $row['selected_slot'] : null,
            'calendar_event_id' => $row['calendar_event_id'] !== null ? (int) $row['calendar_event_id'] : null,
            'confirmed_at' => $row['confirmed_at'],
            'expires_at' => $row['expires_at'],
            'created_at' => $row['created_at'],
        ];
    }
}
