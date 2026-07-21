<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\ValueObjects\NotificationRequest;
use Doctrine\DBAL\Connection;
use Monolog\Logger;
use Throwable;

/**
 * Processes fw_event_outbox jobs (Event Rules actions) via NotificationDispatcher.
 * Instant paths (field work / lifecycle) must not be re-sent from outbox.
 */
class EventOutboxProcessor
{
    /** Event types already delivered synchronously — outbox notify is a no-op. */
    private const INSTANT_NOTIFY_EVENT_TYPES = [
        'TASK_FIELD_WORK_STARTED',
        'TASK_FIELD_WORK_ENDED',
        'PROJECT_BECAME_ACTIVE',
        'PROJECT_BECAME_INACTIVE',
    ];

    private Connection $connection;
    private EventLoggingService $eventLoggingService;
    private NotificationDispatcher $dispatcher;
    private ProjectNotificationService $projectNotificationService;
    private NotificationContentResolver $contentResolver;

    public function __construct(
        private readonly Logger $logger,
        ?EventLoggingService $eventLoggingService = null,
        ?NotificationDispatcher $dispatcher = null,
        ?ProjectNotificationService $projectNotificationService = null,
        ?NotificationContentResolver $contentResolver = null,
    ) {
        $this->connection = Database::getConnection();
        $this->eventLoggingService = $eventLoggingService ?? new EventLoggingService($logger);
        $this->dispatcher = $dispatcher ?? new NotificationDispatcher($logger);
        $this->projectNotificationService = $projectNotificationService
            ?? new ProjectNotificationService(new Database(), $logger);
        $this->contentResolver = $contentResolver ?? new NotificationContentResolver($logger);
    }

    /**
     * @return array{processed: int, sent: int, skipped: int, errors: int}
     */
    public function processPending(int $limit = 100): array
    {
        $limit = max(1, min(1000, $limit));
        $stats = ['processed' => 0, 'sent' => 0, 'skipped' => 0, 'errors' => 0];

        $events = $this->eventLoggingService->getPendingOutboxEvents($limit);
        foreach ($events as $event) {
            $stats['processed']++;
            $result = $this->processOne($event);
            $stats[$result]++;
        }

        $this->logger->info('Event outbox batch processed', $stats);

        return $stats;
    }

    /**
     * @param array{id: int|string, event_type?: string, payload?: array<string, mixed>|null} $event
     * @return 'sent'|'skipped'|'errors'
     */
    public function processOne(array $event): string
    {
        $outboxId = (int) ($event['id'] ?? 0);
        if ($outboxId <= 0) {
            return 'errors';
        }

        try {
            $payload = $event['payload'] ?? null;
            if (!is_array($payload)) {
                $this->eventLoggingService->updateOutboxEventStatus($outboxId, 'error', 'Invalid outbox payload');
                return 'errors';
            }

            $action = $payload['action'] ?? null;
            $normalized = $this->normalizeAction($action);
            if ($normalized === null) {
                $this->eventLoggingService->updateOutboxEventStatus(
                    $outboxId,
                    'error',
                    'Unknown or empty outbox action'
                );
                return 'errors';
            }

            $scheduleGate = $this->applyScheduleGate((string) ($event['event_type'] ?? $payload['event_type'] ?? ''));
            if ($scheduleGate === 'defer') {
                $this->logger->info('Outbox deferred until schedule window', [
                    'outbox_id' => $outboxId,
                    'event_type' => $event['event_type'] ?? null,
                ]);
                return 'skipped';
            }
            if ($scheduleGate === 'skip') {
                $this->eventLoggingService->updateOutboxEventStatus(
                    $outboxId,
                    'sent',
                    'skipped_outside_schedule'
                );
                return 'skipped';
            }

            $outcome = match ($normalized['type']) {
                'notify' => $this->handleNotify($payload, $normalized),
                'create_report' => $this->handleCreateReport($payload, $normalized),
                'log', 'log_only' => 'skipped',
                'legacy' => $this->handleLegacy($payload, $normalized['legacy_action']),
                default => throw new \RuntimeException('Unsupported action type: ' . $normalized['type']),
            };

            if ($outcome === 'error') {
                $this->eventLoggingService->updateOutboxEventStatus(
                    $outboxId,
                    'error',
                    'Action processing failed'
                );
                return 'errors';
            }

            $this->eventLoggingService->updateOutboxEventStatus(
                $outboxId,
                'sent',
                $outcome === 'skipped' ? 'skipped_no_delivery' : null
            );

            return $outcome === 'skipped' ? 'skipped' : 'sent';
        } catch (Throwable $e) {
            $this->logger->error('Outbox item failed', [
                'outbox_id' => $outboxId,
                'error' => $e->getMessage(),
            ]);
            $this->eventLoggingService->updateOutboxEventStatus($outboxId, 'error', $e->getMessage());
            return 'errors';
        }
    }

    /**
     * Crontab schedule gate for outbox:
     * - no schedule → process immediately
     * - in window → process
     * - before window (same day) → leave pending (defer)
     * - wrong day / after window → skip permanently
     *
     * @return 'ok'|'defer'|'skip'
     */
    private function applyScheduleGate(string $eventType): string
    {
        if ($eventType === '') {
            return 'ok';
        }

        try {
            $row = $this->connection->fetchAssociative(
                'SELECT conditions FROM fw_event_rules WHERE event_type = ? AND enabled = 1 LIMIT 1',
                [$eventType]
            );
        } catch (Throwable $e) {
            $this->logger->warning('Failed to load rule schedule for outbox gate', [
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
            return 'ok';
        }

        if (!$row) {
            return 'ok';
        }

        $conditions = !empty($row['conditions'])
            ? json_decode((string) $row['conditions'], true)
            : null;
        if (!is_array($conditions)) {
            return 'ok';
        }

        $timeConditions = $conditions['time_conditions'] ?? null;
        if (is_array($timeConditions) && isset($timeConditions['value']) && is_array($timeConditions['value'])) {
            $timeConditions = $timeConditions['value'];
        }
        if (!is_array($timeConditions) || $timeConditions === []) {
            return 'ok';
        }

        $conditionsService = new EventConditionsService(new Database(), $this->logger);
        $result = $conditionsService->evaluateSchedule($timeConditions);

        return match ($result) {
            'none', 'match' => 'ok',
            'before' => 'defer',
            default => 'skip', // after | wrong_day
        };
    }

    /**
     * @param mixed $action
     * @return array{type: string, channels?: list<string>, recipients?: list<string>, period?: string, legacy_action?: string}|null
     */
    private function normalizeAction(mixed $action): ?array
    {
        if (is_string($action)) {
            $legacy = trim($action);
            if ($legacy === '') {
                return null;
            }
            if (in_array($legacy, ['notify', 'notify_project_manager', 'notify_admin', 'send_email_notification'], true)
                || str_starts_with($legacy, 'notify')
            ) {
                if ($legacy === 'notify') {
                    return [
                        'type' => 'notify',
                        'channels' => ['email'],
                        'recipients' => [],
                    ];
                }
                return ['type' => 'legacy', 'legacy_action' => $legacy];
            }
            if (in_array($legacy, ['generate_daily_report', 'send_email_report', 'create_report'], true)
                || str_contains($legacy, 'report')
            ) {
                return ['type' => 'legacy', 'legacy_action' => $legacy];
            }
            if ($legacy === 'log' || $legacy === 'log_only') {
                return ['type' => 'log'];
            }
            return ['type' => 'legacy', 'legacy_action' => $legacy];
        }

        if (!is_array($action)) {
            return null;
        }

        $type = strtolower((string) ($action['type'] ?? ''));
        if ($type === '') {
            return null;
        }

        if ($type === 'notify') {
            $channels = [];
            $rawChannels = $action['channels'] ?? null;
            if (is_array($rawChannels)) {
                foreach ($rawChannels as $channel) {
                    $channel = strtolower(trim((string) $channel));
                    if (in_array($channel, ['email', 'sms', 'push'], true)) {
                        $channels[] = $channel;
                    }
                }
            }
            // No delivery channels listed → legacy open notify defaults to email.
            // Dashboard/webhook-only rules intentionally yield empty channels (skip send).
            if ($channels === [] && ($rawChannels === null || $rawChannels === [])) {
                $channels = ['email'];
            }

            $recipients = [];
            foreach (($action['recipients'] ?? []) as $role) {
                if (is_string($role) && $role !== '') {
                    $recipients[] = $role;
                }
            }

            $normalized = [
                'type' => 'notify',
                'channels' => array_values(array_unique($channels)),
                'recipients' => array_values(array_unique($recipients)),
            ];

            // Preserve content config for NotificationContentResolver
            if (isset($action['channel_content']) && is_array($action['channel_content'])) {
                $normalized['channel_content'] = $action['channel_content'];
            }
            if (isset($action['channel_templates']) && is_array($action['channel_templates'])) {
                $normalized['channel_templates'] = $action['channel_templates'];
            }

            return $this->contentResolver->normalizeActionContent($normalized);
        }

        if ($type === 'create_report') {
            return [
                'type' => 'create_report',
                'period' => (string) ($action['period'] ?? 'daily'),
                'recipients' => is_array($action['recipients'] ?? null)
                    ? array_values(array_filter($action['recipients'], 'is_string'))
                    : [],
            ];
        }

        if ($type === 'log' || $type === 'log_only') {
            return ['type' => 'log'];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{type: string, channels?: list<string>, recipients?: list<string>} $action
     * @return 'sent'|'skipped'|'error'
     */
    private function handleNotify(array $payload, array $action): string
    {
        $eventType = strtoupper((string) ($payload['event_type'] ?? ''));

        if (in_array($eventType, self::INSTANT_NOTIFY_EVENT_TYPES, true)) {
            $this->logger->info('Skipping outbox notify for instant event type', [
                'event_type' => $eventType,
                'event_log_id' => $payload['event_log_id'] ?? null,
            ]);
            return 'skipped';
        }

        $eventLogId = isset($payload['event_log_id']) ? (int) $payload['event_log_id'] : 0;
        if ($eventLogId > 0 && $this->hasSuccessfulDeliveryForEventLog($eventLogId)) {
            $this->logger->info('Skipping outbox notify; delivery already recorded', [
                'event_log_id' => $eventLogId,
            ]);
            return 'skipped';
        }

        $channels = $action['channels'] ?? [];
        if ($channels === []) {
            $this->logger->info('Skipping outbox notify; no email/sms/push channels', [
                'event_type' => $eventType,
                'event_log_id' => $eventLogId,
            ]);
            return 'skipped';
        }

        $recipientIds = $this->resolveRecipientUserIds($payload, $action['recipients'] ?? []);
        if ($recipientIds === []) {
            $this->logger->warning('Outbox notify has no recipients', [
                'event_type' => $eventType,
                'event_log_id' => $eventLogId,
            ]);
            return 'skipped';
        }

        $content = $this->contentResolver->resolve($payload, $action);
        $title = $content['title'];
        $message = $content['message'];
        $actorId = isset($payload['actor_id']) ? (int) $payload['actor_id'] : null;
        $correlationBase = (string) ($payload['correlation_id'] ?? ('outbox:' . $eventLogId));
        $url = $this->buildUrl($payload);

        $anySent = false;
        $anyFailed = false;

        foreach ($recipientIds as $recipientId) {
            $correlation = substr($correlationBase . ':u' . $recipientId, 0, 64);
            $result = $this->dispatcher->dispatch(new NotificationRequest(
                recipientUserId: $recipientId,
                type: $eventType !== '' ? $eventType : 'OUTBOX_NOTIFY',
                title: $title,
                message: $message,
                channels: $channels,
                priority: $this->mapSeverityToPriority((string) ($payload['severity'] ?? 'important')),
                senderUserId: $actorId,
                eventLogId: $eventLogId > 0 ? $eventLogId : null,
                correlationId: $correlation,
                url: $url,
                data: [
                    'entity_type' => $payload['entity_type'] ?? null,
                    'entity_id' => $payload['entity_id'] ?? null,
                    'event_log_id' => $eventLogId > 0 ? $eventLogId : null,
                ],
                emailSubject: $content['email_subject'],
                emailHtml: $content['email_html'],
                smsBody: $content['sms_body'],
                pushTitle: $content['push_title'],
                pushBody: $content['push_body'],
            ));

            if ($result->hasSent()) {
                $anySent = true;
            }
            if ($result->hasFailures() && !$result->hasSent()) {
                $anyFailed = true;
            }
        }

        if ($anySent) {
            return 'sent';
        }
        if ($anyFailed) {
            return 'error';
        }

        return 'skipped';
    }

    /**
     * @param array<string, mixed> $payload
     * @param array{type: string, period?: string, recipients?: list<string>} $action
     * @return 'sent'|'skipped'|'error'
     */
    private function handleCreateReport(array $payload, array $action): string
    {
        try {
            $period = strtolower((string) ($action['period'] ?? 'daily'));
            if (!in_array($period, ['daily', 'weekly', 'monthly'], true)) {
                $period = 'daily';
            }

            $after = is_array($payload['after_data'] ?? null) ? $payload['after_data'] : [];
            $reportDate = (string) ($after['report_date'] ?? '');
            if ($reportDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
                $reportDate = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
            }

            $recipientIds = $this->resolveRecipientUserIds($payload, $action['recipients'] ?? []);

            $service = new DailyOperationalReportService($this->logger);
            $result = $service->runForPeriod($period, $reportDate, true, $recipientIds);

            $this->logger->info('Outbox create_report completed', [
                'period' => $period,
                'report_date' => $reportDate,
                'generated' => $result['generated'],
                'sent' => $result['sent'],
                'failed' => $result['failed'],
            ]);

            if (($result['failed'] ?? 0) > 0 && ($result['sent'] ?? 0) === 0) {
                return 'error';
            }
            if (($result['sent'] ?? 0) > 0 || ($result['generated'] ?? 0) > 0) {
                return 'sent';
            }

            return 'skipped';
        } catch (Throwable $e) {
            $this->logger->error('Outbox create_report failed', ['error' => $e->getMessage()]);
            return 'error';
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return 'sent'|'skipped'|'error'
     */
    private function handleLegacy(array $payload, string $legacyAction): string
    {
        try {
            $this->projectNotificationService->processLegacyOutboxAction($legacyAction, $payload);
            return 'sent';
        } catch (Throwable $e) {
            $this->logger->error('Legacy outbox action failed', [
                'action' => $legacyAction,
                'error' => $e->getMessage(),
            ]);
            return 'error';
        }
    }

    private function hasSuccessfulDeliveryForEventLog(int $eventLogId): bool
    {
        try {
            $count = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM fw_notifications
                 WHERE event_log_id = ? AND status = 'sent'",
                [$eventLogId]
            );
            return $count > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $roles
     * @return list<int>
     */
    private function resolveRecipientUserIds(array $payload, array $roles): array
    {
        $ids = [];
        $after = is_array($payload['after_data'] ?? null) ? $payload['after_data'] : [];
        $entityType = (string) ($payload['entity_type'] ?? '');
        $entityId = isset($payload['entity_id']) ? (int) $payload['entity_id'] : 0;

        if ($roles === []) {
            // Open notify → project manager if present, else admins.
            $roles = ['project_manager', 'admin'];
        }

        foreach ($roles as $role) {
            $role = strtolower(trim($role));
            if ($role === 'admin') {
                foreach ($this->fetchActiveUserIdsByRole('admin') as $id) {
                    $ids[] = $id;
                }
                continue;
            }

            if ($role === 'project_manager' || $role === 'manager') {
                $managerId = (int) ($after['prj_manager'] ?? $after['project_manager_id'] ?? 0);
                $projectId = 0;
                if ($entityType === 'project' && $entityId > 0) {
                    $projectId = $entityId;
                } elseif ($entityType === 'task' && $entityId > 0) {
                    $projectId = (int) ($after['project_id'] ?? 0);
                    if ($projectId <= 0) {
                        $projectId = (int) ($this->connection->fetchOne(
                            'SELECT project_id FROM fw_prj_tasks WHERE id = ? LIMIT 1',
                            [$entityId]
                        ) ?: 0);
                    }
                } else {
                    $projectId = (int) ($after['project_id'] ?? 0);
                }
                if ($managerId <= 0 && $projectId > 0) {
                    $managerId = (int) ($this->connection->fetchOne(
                        'SELECT prj_manager FROM fw_projects WHERE id = ? LIMIT 1',
                        [$projectId]
                    ) ?: 0);
                }
                if ($managerId > 0 && $this->isActiveUser($managerId)) {
                    $ids[] = $managerId;
                }
                continue;
            }

            if ($role === 'task_lead') {
                $leadId = (int) ($after['task_lead_id'] ?? $after['accountable_foreman_id'] ?? 0);
                if ($leadId <= 0 && $entityType === 'task' && $entityId > 0) {
                    $leadId = $this->resolveTaskLeadUserId($entityId);
                }
                if ($leadId > 0 && $this->isActiveUser($leadId)) {
                    $ids[] = $leadId;
                }
                continue;
            }

            if ($role === 'team_members') {
                $taskId = $entityType === 'task' ? $entityId : (int) ($after['task_id'] ?? 0);
                if ($taskId > 0) {
                    foreach ($this->resolveTaskMemberUserIds($taskId) as $memberId) {
                        $ids[] = $memberId;
                    }
                }
                continue;
            }

            // Global role codes used as recipients (worker, foreman, contractor, …)
            if (in_array($role, ['worker', 'foreman', 'contractor', 'inspector', 'project_manager'], true)) {
                foreach ($this->fetchActiveUserIdsByRole($role) as $id) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /** @return list<int> */
    private function fetchActiveUserIdsByRole(string $roleCode): array
    {
        try {
            $rows = $this->connection->fetchFirstColumn(
                "SELECT u.id
                 FROM fw_users u
                 INNER JOIN fw_glob_roles r ON u.role_id = r.id
                 WHERE r.code = ? AND u.status = 1 AND u.archived_at IS NULL",
                [$roleCode]
            );
            return array_map('intval', $rows);
        } catch (Throwable) {
            return [];
        }
    }

    private function isActiveUser(int $userId): bool
    {
        try {
            $id = $this->connection->fetchOne(
                'SELECT id FROM fw_users WHERE id = ? AND status = 1 AND archived_at IS NULL LIMIT 1',
                [$userId]
            );
            return $id !== false && $id !== null;
        } catch (Throwable) {
            return false;
        }
    }

    private function resolveTaskLeadUserId(int $taskId): int
    {
        try {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT user_id, role_in_project FROM fw_prj_team_members WHERE task_id = ?',
                [$taskId]
            );
            foreach ($rows as $row) {
                $role = strtolower((string) ($row['role_in_project'] ?? ''));
                if (
                    $role === 'task_lead'
                    || str_contains($role, 'lead')
                    || str_contains($role, 'foreman')
                    || str_contains($role, 'supervisor')
                ) {
                    return (int) $row['user_id'];
                }
            }
        } catch (Throwable) {
            // ignore
        }
        return 0;
    }

    /** @return list<int> */
    private function resolveTaskMemberUserIds(int $taskId): array
    {
        try {
            $rows = $this->connection->fetchFirstColumn(
                'SELECT DISTINCT user_id FROM fw_prj_team_members WHERE task_id = ?',
                [$taskId]
            );
            $ids = [];
            foreach ($rows as $id) {
                $uid = (int) $id;
                if ($uid > 0 && $this->isActiveUser($uid)) {
                    $ids[] = $uid;
                }
            }
            return $ids;
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string, mixed> $payload */
    private function buildTitle(array $payload): string
    {
        $eventType = (string) ($payload['event_type'] ?? 'EVENT');
        $after = is_array($payload['after_data'] ?? null) ? $payload['after_data'] : [];
        $name = (string) ($after['prj_name'] ?? $after['project_name'] ?? $after['task_name'] ?? $after['name'] ?? '');
        $label = ucwords(strtolower(str_replace('_', ' ', $eventType)));
        return $name !== '' ? "{$label}: {$name}" : $label;
    }

    /** @param array<string, mixed> $payload */
    private function buildMessage(array $payload): string
    {
        $comment = trim((string) ($payload['comment'] ?? ''));
        if ($comment !== '') {
            return $comment;
        }
        return $this->buildTitle($payload);
    }

    /** @param array<string, mixed> $payload */
    private function buildUrl(array $payload): string
    {
        $entityType = (string) ($payload['entity_type'] ?? '');
        $entityId = (int) ($payload['entity_id'] ?? 0);
        $after = is_array($payload['after_data'] ?? null) ? $payload['after_data'] : [];

        if ($entityType === 'project' && $entityId > 0) {
            return "/projects/{$entityId}/detail";
        }
        if ($entityType === 'task' && $entityId > 0) {
            $projectId = (int) ($after['project_id'] ?? 0);
            if ($projectId > 0) {
                return "/tasks/projects/{$projectId}/tasks/{$entityId}";
            }
        }
        return '/';
    }

    private function mapSeverityToPriority(string $severity): string
    {
        return match (strtolower($severity)) {
            'critical' => 'high',
            'important' => 'medium',
            default => 'medium',
        };
    }
}
