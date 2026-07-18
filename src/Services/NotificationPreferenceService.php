<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use Doctrine\DBAL\Connection;
use Monolog\Logger;
use Throwable;

class NotificationPreferenceService
{
    private Connection $connection;

    public function __construct(
        private readonly Logger $logger
    ) {
        $this->connection = Database::getConnection();
    }

    /**
     * @return array{
     *   outbound_enabled: bool,
     *   email_enabled: bool,
     *   sms_enabled: bool,
     *   push_enabled: bool,
     *   field_work_start_enabled: bool,
     *   field_work_end_enabled: bool
     * }
     */
    public function getForUser(int $userId): array
    {
        $defaults = $this->defaults();

        try {
            $row = $this->connection->fetchAssociative(
                'SELECT outbound_enabled, email_enabled, sms_enabled, push_enabled,
                        field_work_start_enabled, field_work_end_enabled
                 FROM fw_notification_preferences
                 WHERE user_id = ?
                 LIMIT 1',
                [$userId]
            );
            if (!$row) {
                return $defaults;
            }

            return [
                'outbound_enabled' => (bool) (int) $row['outbound_enabled'],
                'email_enabled' => (bool) (int) $row['email_enabled'],
                'sms_enabled' => (bool) (int) $row['sms_enabled'],
                'push_enabled' => (bool) (int) $row['push_enabled'],
                'field_work_start_enabled' => array_key_exists('field_work_start_enabled', $row)
                    ? (bool) (int) $row['field_work_start_enabled']
                    : true,
                'field_work_end_enabled' => array_key_exists('field_work_end_enabled', $row)
                    ? (bool) (int) $row['field_work_end_enabled']
                    : true,
            ];
        } catch (Throwable $e) {
            $this->logger->error('Failed to load notification preferences', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return $defaults;
        }
    }

    /**
     * Field roles: master Email/SMS/Push switches.
     * Admin/PM: only mute inbound field-work start/end (other auto events = Event Rules).
     *
     * @param array<string, mixed> $patch
     * @return array{
     *   outbound_enabled: bool,
     *   email_enabled: bool,
     *   sms_enabled: bool,
     *   push_enabled: bool,
     *   field_work_start_enabled: bool,
     *   field_work_end_enabled: bool
     * }
     */
    public function updateForUser(int $userId, array $patch): array
    {
        $roleCode = $this->resolveUserRoleCode($userId);
        $isConfigurator = $this->isConfiguratorRole($roleCode);
        $current = $this->getForUser($userId);

        if ($isConfigurator) {
            $allowedKeys = ['field_work_start_enabled', 'field_work_end_enabled'];
            $hasAllowed = false;
            foreach ($allowedKeys as $key) {
                if (array_key_exists($key, $patch)) {
                    $current[$key] = (bool) $patch[$key];
                    $hasAllowed = true;
                }
            }
            if (!$hasAllowed) {
                throw new \InvalidArgumentException(
                    'Admins and project managers may only mute field work start/end notifications'
                );
            }
        } else {
            if (!$this->isDeliveryRole($roleCode)) {
                throw new \InvalidArgumentException('Notification preferences are not available for this role');
            }
            foreach (['outbound_enabled', 'email_enabled', 'sms_enabled', 'push_enabled'] as $key) {
                if (array_key_exists($key, $patch)) {
                    $current[$key] = (bool) $patch[$key];
                }
            }
        }

        $this->connection->executeStatement(
            'INSERT INTO fw_notification_preferences
                (user_id, outbound_enabled, email_enabled, sms_enabled, push_enabled,
                 field_work_start_enabled, field_work_end_enabled, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                outbound_enabled = VALUES(outbound_enabled),
                email_enabled = VALUES(email_enabled),
                sms_enabled = VALUES(sms_enabled),
                push_enabled = VALUES(push_enabled),
                field_work_start_enabled = VALUES(field_work_start_enabled),
                field_work_end_enabled = VALUES(field_work_end_enabled),
                updated_at = NOW()',
            [
                $userId,
                $current['outbound_enabled'] ? 1 : 0,
                $current['email_enabled'] ? 1 : 0,
                $current['sms_enabled'] ? 1 : 0,
                $current['push_enabled'] ? 1 : 0,
                $current['field_work_start_enabled'] ? 1 : 0,
                $current['field_work_end_enabled'] ? 1 : 0,
            ]
        );

        return $current;
    }

    /**
     * Admin rules define available events/channels. User event choices default to OFF.
     *
     * @return list<array{
     *   event_type: string,
     *   label: string,
     *   description: string,
     *   severity: string,
     *   allowed_channels: list<string>,
     *   email_enabled: bool,
     *   sms_enabled: bool,
     *   push_enabled: bool
     * }>
     */
    public function getAvailableEventsForUser(int $userId): array
    {
        $roleCode = $this->resolveUserRoleCode($userId);
        // Admin/PM configure system events; they do not opt into personal delivery here.
        if ($this->isConfiguratorRole($roleCode) || !$this->isDeliveryRole($roleCode)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT event_type, severity, actions, comment, conditions
             FROM fw_event_rules
             WHERE enabled = 1
             ORDER BY event_type ASC'
        );
        $savedRows = $this->connection->fetchAllAssociative(
            'SELECT event_type, email_enabled, sms_enabled, push_enabled
             FROM fw_notification_event_preferences
             WHERE user_id = ?',
            [$userId]
        );
        $saved = [];
        foreach ($savedRows as $row) {
            $saved[(string) $row['event_type']] = $row;
        }

        $events = [];
        foreach ($rows as $row) {
            $eventType = (string) $row['event_type'];
            if (str_contains($eventType, 'TEST')) {
                continue;
            }
            $channels = $this->extractAllowedChannels($row['actions'] ?? null);
            if ($channels === []) {
                continue;
            }
            if (!$this->eventTargetsDeliveryRole($row['actions'] ?? null, $row['conditions'] ?? null, $roleCode)) {
                continue;
            }
            $preference = $saved[$eventType] ?? null;
            $events[] = [
                'event_type' => $eventType,
                'label' => $this->eventLabel($eventType),
                'description' => trim((string) ($row['comment'] ?? '')),
                'severity' => (string) ($row['severity'] ?? 'important'),
                'allowed_channels' => $channels,
                'email_enabled' => $preference ? (bool) (int) $preference['email_enabled'] : false,
                'sms_enabled' => $preference ? (bool) (int) $preference['sms_enabled'] : false,
                'push_enabled' => $preference ? (bool) (int) $preference['push_enabled'] : false,
            ];
        }

        return $events;
    }

    /**
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function updateEventForUser(int $userId, string $eventType, array $patch): array
    {
        $roleCode = $this->resolveUserRoleCode($userId);
        if ($this->isConfiguratorRole($roleCode) || !$this->isDeliveryRole($roleCode)) {
            throw new \InvalidArgumentException(
                'Only field roles configure personal event delivery preferences'
            );
        }

        $eventType = strtoupper(trim($eventType));
        $rule = $this->connection->fetchAssociative(
            'SELECT event_type, severity, actions, comment, conditions
             FROM fw_event_rules
             WHERE event_type = ? AND enabled = 1
             LIMIT 1',
            [$eventType]
        );
        if (!$rule) {
            throw new \InvalidArgumentException('Event is not available');
        }

        $allowedChannels = $this->extractAllowedChannels($rule['actions'] ?? null);
        if ($allowedChannels === []) {
            throw new \InvalidArgumentException('Event has no user notification channels');
        }
        if (!$this->eventTargetsDeliveryRole($rule['actions'] ?? null, $rule['conditions'] ?? null, $roleCode)) {
            throw new \InvalidArgumentException('Event is not available for your role');
        }

        $current = [
            'email_enabled' => false,
            'sms_enabled' => false,
            'push_enabled' => false,
        ];
        $existing = $this->connection->fetchAssociative(
            'SELECT email_enabled, sms_enabled, push_enabled
             FROM fw_notification_event_preferences
             WHERE user_id = ? AND event_type = ?
             LIMIT 1',
            [$userId, $eventType]
        );
        if ($existing) {
            foreach (array_keys($current) as $key) {
                $current[$key] = (bool) (int) $existing[$key];
            }
        }

        foreach (array_keys($current) as $key) {
            if (array_key_exists($key, $patch)) {
                $channel = str_replace('_enabled', '', $key);
                if (!in_array($channel, $allowedChannels, true) && (bool) $patch[$key]) {
                    throw new \InvalidArgumentException("Channel {$channel} is not enabled by admin for this event");
                }
                $current[$key] = (bool) $patch[$key];
            }
        }

        $this->connection->executeStatement(
            'INSERT INTO fw_notification_event_preferences
                (user_id, event_type, email_enabled, sms_enabled, push_enabled, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                email_enabled = VALUES(email_enabled),
                sms_enabled = VALUES(sms_enabled),
                push_enabled = VALUES(push_enabled),
                updated_at = NOW()',
            [
                $userId,
                $eventType,
                $current['email_enabled'] ? 1 : 0,
                $current['sms_enabled'] ? 1 : 0,
                $current['push_enabled'] ? 1 : 0,
            ]
        );

        return [
            'event_type' => $eventType,
            'label' => $this->eventLabel($eventType),
            'description' => trim((string) ($rule['comment'] ?? '')),
            'severity' => (string) ($rule['severity'] ?? 'important'),
            'allowed_channels' => $allowedChannels,
            ...$current,
        ];
    }

    public function isChannelAllowed(
        int $userId,
        string $eventType,
        string $channel,
        bool $bypass = false
    ): bool
    {
        if ($bypass) {
            return true;
        }

        $roleCode = $this->resolveUserRoleCode($userId);

        // Admin/PM: mute only field-work start/end; other inbound events follow Event Rules.
        if ($this->isConfiguratorRole($roleCode)) {
            $normalizedType = strtoupper(trim($eventType));
            $prefs = $this->getForUser($userId);
            if ($normalizedType === 'TASK_FIELD_WORK_STARTED') {
                return $prefs['field_work_start_enabled'];
            }
            if ($normalizedType === 'TASK_FIELD_WORK_ENDED') {
                return $prefs['field_work_end_enabled'];
            }
            return true;
        }

        if (!$this->isDeliveryRole($roleCode)) {
            return false;
        }

        $prefs = $this->getForUser($userId);
        if (!$prefs['outbound_enabled']) {
            return false;
        }

        $masterAllowed = match ($channel) {
            'email' => $prefs['email_enabled'],
            'sms' => $prefs['sms_enabled'],
            'push' => $prefs['push_enabled'],
            default => false,
        };
        if (!$masterAllowed) {
            return false;
        }

        $eventField = $channel . '_enabled';
        if (!in_array($eventField, ['email_enabled', 'sms_enabled', 'push_enabled'], true)) {
            return false;
        }

        $eventValue = $this->connection->fetchOne(
            "SELECT `{$eventField}`
             FROM fw_notification_event_preferences
             WHERE user_id = ? AND event_type = ?
             LIMIT 1",
            [$userId, strtoupper(trim($eventType))]
        );

        // Explicit opt-in: newly added events never start spamming field users automatically.
        return $eventValue !== false && (bool) (int) $eventValue;
    }

    public function isConfiguratorRole(?string $roleCode): bool
    {
        return in_array((string) $roleCode, ['admin', 'project_manager'], true);
    }

    public function isDeliveryRole(?string $roleCode): bool
    {
        return in_array(
            (string) $roleCode,
            ['worker', 'foreman', 'contractor', 'inspector'],
            true
        );
    }

    public function resolveUserRoleCode(int $userId): ?string
    {
        try {
            $code = $this->connection->fetchOne(
                'SELECT r.code
                 FROM fw_users u
                 LEFT JOIN fw_glob_roles r ON u.role_id = r.id
                 WHERE u.id = ?
                 LIMIT 1',
                [$userId]
            );
            return $code !== false && $code !== null ? (string) $code : null;
        } catch (Throwable $e) {
            $this->logger->warning('Failed to resolve user role for notifications', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /** @return list<string> */
    private function extractAllowedChannels(mixed $rawActions): array
    {
        $actions = is_string($rawActions) ? json_decode($rawActions, true) : $rawActions;
        if (!is_array($actions)) {
            return [];
        }

        $channels = [];
        foreach ($actions as $action) {
            if (is_array($action)) {
                if (($action['type'] ?? null) !== 'notify') {
                    continue;
                }
                foreach (($action['channels'] ?? []) as $channel) {
                    if (in_array($channel, ['email', 'sms', 'push'], true)) {
                        $channels[] = $channel;
                    }
                }
                continue;
            }

            if (!is_string($action)) {
                continue;
            }
            $normalized = strtolower($action);
            if ($normalized === 'notify' || str_contains($normalized, 'email')) {
                $channels[] = 'email';
            }
            if (str_contains($normalized, 'sms')) {
                $channels[] = 'sms';
            }
            if (str_contains($normalized, 'push')) {
                $channels[] = 'push';
            }
        }

        return array_values(array_unique($channels));
    }

    /**
     * An event is configurable by a field role only when Event Rules target that role
     * (or leave recipients open for field delivery roles).
     */
    private function eventTargetsDeliveryRole(
        mixed $rawActions,
        mixed $rawConditions,
        string $roleCode
    ): bool {
        $recipients = $this->extractRecipientRoles($rawActions, $rawConditions);
        if ($recipients === []) {
            // Open notify rules without explicit recipients are for field roles.
            return $this->isDeliveryRole($roleCode);
        }

        return in_array($roleCode, $recipients, true);
    }

    /** @return list<string> */
    private function extractRecipientRoles(mixed $rawActions, mixed $rawConditions): array
    {
        $roles = [];
        $actions = is_string($rawActions) ? json_decode($rawActions, true) : $rawActions;
        if (is_array($actions)) {
            foreach ($actions as $action) {
                if (is_array($action)) {
                    if (($action['type'] ?? null) !== 'notify') {
                        continue;
                    }
                    foreach (($action['recipients'] ?? []) as $role) {
                        if (is_string($role) && $role !== '') {
                            $roles[] = $role;
                        }
                    }
                    continue;
                }
                if (!is_string($action)) {
                    continue;
                }
                $normalized = strtolower($action);
                if (str_contains($normalized, 'project_manager') || str_contains($normalized, 'manager')) {
                    $roles[] = 'project_manager';
                }
                if (str_contains($normalized, 'admin')) {
                    $roles[] = 'admin';
                }
                if (str_contains($normalized, 'contractor')) {
                    $roles[] = 'contractor';
                }
                if (str_contains($normalized, 'worker')) {
                    $roles[] = 'worker';
                }
                if (str_contains($normalized, 'foreman')) {
                    $roles[] = 'foreman';
                }
            }
        }

        $conditions = is_string($rawConditions) ? json_decode($rawConditions, true) : $rawConditions;
        if (is_array($conditions)) {
            $notifyRoles = $conditions['notify_roles']['value']
                ?? $conditions['notify_roles']
                ?? null;
            if (is_array($notifyRoles)) {
                foreach ($notifyRoles as $role) {
                    if (is_string($role) && $role !== '') {
                        $roles[] = $role;
                    }
                }
            }
        }

        return array_values(array_unique($roles));
    }

    private function eventLabel(string $eventType): string
    {
        return ucwords(strtolower(str_replace('_', ' ', $eventType)));
    }

    /**
     * @return array{
     *   outbound_enabled: bool,
     *   email_enabled: bool,
     *   sms_enabled: bool,
     *   push_enabled: bool,
     *   field_work_start_enabled: bool,
     *   field_work_end_enabled: bool
     * }
     */
    private function defaults(): array
    {
        return [
            'outbound_enabled' => true,
            'email_enabled' => true,
            'sms_enabled' => true,
            'push_enabled' => true,
            'field_work_start_enabled' => true,
            'field_work_end_enabled' => true,
        ];
    }
}
