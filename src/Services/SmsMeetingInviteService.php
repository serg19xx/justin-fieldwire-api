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
    private const DEFAULT_EXPIRY_HOURS = 72;
    private const ALLOWED_ROLES = ['admin', 'project_manager'];

    public function __construct(
        private readonly Logger $logger,
        private readonly TwilioService $twilioService,
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
     * @return array{success: bool, invite_id?: int, message?: string, sent_to?: string, test_mode?: bool, error?: string}
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

        $parsed = $this->parseInvitePayload($payload);
        if ($parsed === null) {
            return ['success' => false, 'error' => 'Invalid meeting date or time slots'];
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

        $smsBody = $this->buildInviteSms(
            $clientName,
            $parsed['meeting_date'],
            $parsed['slots'],
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

            $conn->executeStatement(
                'INSERT INTO fw_sms_meeting_invites
                 (user_id, client_type, client_id, client_name, client_phone, client_phone_normalized,
                  meeting_date, slot1_time, slot2_time, slot3_time, duration_minutes, meeting_title,
                  timezone, status, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $userId,
                    $clientType,
                    $clientId,
                    $clientName,
                    $phone,
                    $normalized,
                    $parsed['meeting_date'],
                    $parsed['slots'][0],
                    $parsed['slots'][1],
                    $parsed['slots'][2],
                    $parsed['duration_minutes'],
                    $title,
                    $parsed['timezone'],
                    'pending',
                    $expiresAt,
                ],
            );

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

        $slot = $this->parseSlotChoice($body);
        if ($slot === null) {
            return [
                'success' => true,
                'reply_sms' => 'Please reply with 1, 2, or 3 to choose a time slot.',
            ];
        }

        try {
            $conn = Database::getConnection();
            $invite = $this->findActivePendingInvite($conn, $normalized);
            if ($invite === null) {
                return [
                    'success' => true,
                    'reply_sms' => 'No active meeting invite found. Please contact your project manager.',
                ];
            }

            $result = $this->confirmInvite($conn, $invite, $slot);
            return $result;
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
     * @param array<string, mixed> $payload
     * @return array{
     *     meeting_date: string,
     *     slots: array{0: string, 1: string, 2: string},
     *     duration_minutes: int,
     *     timezone: string
     * }|null
     */
    private function parseInvitePayload(array $payload): ?array
    {
        $meetingDate = trim((string) ($payload['meeting_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $meetingDate)) {
            return null;
        }

        $slotsRaw = $payload['slots'] ?? null;
        if (!is_array($slotsRaw) || count($slotsRaw) !== 3) {
            return null;
        }

        $slots = [];
        foreach ($slotsRaw as $slot) {
            $normalized = $this->normalizeTimeInput((string) $slot);
            if ($normalized === null) {
                return null;
            }
            $slots[] = $normalized;
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

        return [
            'meeting_date' => $meetingDate,
            'slots' => [$slots[0], $slots[1], $slots[2]],
            'duration_minutes' => $duration,
            'timezone' => $timezone,
        ];
    }

    /**
     * @param array{0: string, 1: string, 2: string} $slots
     */
    private function buildInviteSms(
        string $clientName,
        string $meetingDate,
        array $slots,
        string $timezone,
    ): string {
        $dateLabel = $this->formatDateLabel($meetingDate, $timezone);

        return implode("\n", [
            'FieldWire meeting invite for ' . $clientName . ', ' . $dateLabel . ':',
            '1 - ' . $this->formatTimeLabel($slots[0]),
            '2 - ' . $this->formatTimeLabel($slots[1]),
            '3 - ' . $this->formatTimeLabel($slots[2]),
            'Reply with 1, 2, or 3.',
        ]);
    }

    private function parseSlotChoice(string $body): ?int
    {
        $clean = strtolower(trim($body));
        $clean = preg_replace('/[^0-9]/', '', $clean) ?? '';
        if ($clean === '1' || $clean === '2' || $clean === '3') {
            return (int) $clean;
        }

        if (preg_match('/^(?:option\s*)?([123])\.?$/i', trim($body), $m)) {
            return (int) $m[1];
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
            return ['success' => true, 'reply_sms' => 'Invalid choice. Reply with 1, 2, or 3.'];
        }

        $timezone = (string) ($invite['timezone'] ?? self::DEFAULT_TIMEZONE);
        $meetingDate = (string) $invite['meeting_date'];
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

            return [
                'success' => true,
                'reply_sms' => 'Confirmed: ' . $title . ' on ' . $dateLabel . ' at ' . $timeLabel . '. Thank you!',
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
            'slot1_time' => substr((string) $row['slot1_time'], 0, 5),
            'slot2_time' => substr((string) $row['slot2_time'], 0, 5),
            'slot3_time' => substr((string) $row['slot3_time'], 0, 5),
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
