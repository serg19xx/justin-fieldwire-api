<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\ValueObjects\NotificationRequest;
use Doctrine\DBAL\Connection;
use Monolog\Logger;
use Throwable;

/**
 * Notifies Admin/PM when field users record work start/end.
 * Channels: email, SMS, push (respect mute). Urgent forces push even if muted.
 */
class FieldWorkNotificationService
{
    private Connection $connection;
    private NotificationDispatcher $dispatcher;
    private NotificationPreferenceService $preferenceService;

    public function __construct(
        private readonly Logger $logger,
        ?NotificationDispatcher $dispatcher = null,
        ?NotificationPreferenceService $preferenceService = null,
    ) {
        $this->connection = Database::getConnection();
        $this->dispatcher = $dispatcher ?? new NotificationDispatcher($logger);
        $this->preferenceService = $preferenceService ?? new NotificationPreferenceService($logger);
    }

    /**
     * @param array<string, mixed> $task
     */
    public function notifyManagers(
        int $projectId,
        int $taskId,
        array $task,
        int $actorId,
        string $phase,
        bool $urgent = false,
    ): void {
        $eventType = $phase === 'ended' ? 'TASK_FIELD_WORK_ENDED' : 'TASK_FIELD_WORK_STARTED';
        $timeKey = $phase === 'ended' ? 'field_work_ended_at' : 'field_work_started_at';
        $reasonKey = $phase === 'ended' ? 'field_work_end_reason' : 'field_work_start_reason';

        $recipients = $this->resolveRecipients($projectId);
        if ($recipients === []) {
            $this->logger->info('No Admin/PM recipients for field work notification', [
                'project_id' => $projectId,
                'task_id' => $taskId,
                'phase' => $phase,
            ]);
            return;
        }

        $projectName = $this->resolveProjectName($projectId);
        $taskName = (string) ($task['name'] ?? ('Task #' . $taskId));
        $when = (string) ($task[$timeKey] ?? '');
        $reason = trim((string) ($task[$reasonKey] ?? ''));
        $actorName = $this->resolveUserDisplayName($actorId);
        $phaseLabel = $phase === 'ended' ? 'ended' : 'started';

        $title = $urgent
            ? "[Urgent] Work {$phaseLabel}: {$taskName}"
            : "Work {$phaseLabel}: {$taskName}";

        $lines = [
            "Field work {$phaseLabel} on task \"{$taskName}\"".($projectName !== '' ? " (project: {$projectName})" : '').'.',
            "Recorded by: {$actorName}",
        ];
        if ($when !== '') {
            $lines[] = "Time: {$when}";
        }
        if ($reason !== '') {
            $lines[] = "Reason: {$reason}";
        }
        if ($urgent) {
            $lines[] = 'Marked as Urgent by the sender.';
        }
        $message = implode("\n", $lines);
        $smsBody = $urgent
            ? "[Urgent] Work {$phaseLabel}: {$taskName}" . ($when !== '' ? " at {$when}" : '')
            : "Work {$phaseLabel}: {$taskName}" . ($when !== '' ? " at {$when}" : '');
        $pushBody = $smsBody;
        $url = "/tasks/projects/{$projectId}/tasks/{$taskId}";

        foreach ($recipients as $recipientId) {
            // Keep under VARCHAR(64) for fw_notifications.correlation_id.
            $correlation = substr(
                sprintf('fw:%s:%d:%s:u%d', $phase === 'ended' ? 'end' : 'start', $taskId, md5($when), $recipientId),
                0,
                64
            );
            $prefs = $this->preferenceService->getForUser($recipientId);
            $isMuted = $phase === 'ended'
                ? !$prefs['field_work_end_enabled']
                : !$prefs['field_work_start_enabled'];

            try {
                $channels = ['email', 'sms'];
                if ($urgent) {
                    $channels[] = 'push';
                }

                $this->dispatcher->dispatch(new NotificationRequest(
                    recipientUserId: $recipientId,
                    type: $eventType,
                    title: $title,
                    message: $message,
                    channels: $channels,
                    priority: $urgent ? 'high' : 'medium',
                    senderUserId: $actorId,
                    correlationId: $correlation,
                    url: $url,
                    data: [
                        'project_id' => $projectId,
                        'task_id' => $taskId,
                        'phase' => $phase,
                        'urgent' => $urgent,
                    ],
                    emailSubject: $title,
                    // EmailService currently sends this body as text/plain.
                    emailHtml: $message,
                    smsBody: $smsBody,
                    pushTitle: $title,
                    pushBody: $pushBody,
                ));

                // Urgent always delivers push even when Admin/PM muted start/end.
                if ($urgent && $isMuted) {
                    $this->dispatcher->dispatch(new NotificationRequest(
                        recipientUserId: $recipientId,
                        type: $eventType,
                        title: $title,
                        message: $message,
                        channels: ['push'],
                        priority: 'high',
                        senderUserId: $actorId,
                        correlationId: $correlation . ':urgent-push',
                        url: $url,
                        data: [
                            'project_id' => $projectId,
                            'task_id' => $taskId,
                            'phase' => $phase,
                            'urgent' => true,
                        ],
                        pushTitle: $title,
                        pushBody: $pushBody,
                        bypassPreferences: true,
                    ));
                }
            } catch (Throwable $e) {
                $this->logger->error('Failed to notify manager about field work', [
                    'recipient_id' => $recipientId,
                    'project_id' => $projectId,
                    'task_id' => $taskId,
                    'phase' => $phase,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /** @return list<int> */
    private function resolveRecipients(int $projectId): array
    {
        $ids = [];

        try {
            $managerId = $this->connection->fetchOne(
                'SELECT p.prj_manager
                 FROM fw_projects p
                 INNER JOIN fw_users u ON u.id = p.prj_manager
                 WHERE p.id = ?
                   AND u.status = 1
                   AND u.archived_at IS NULL
                 LIMIT 1',
                [$projectId]
            );
            if ($managerId !== false && $managerId !== null && (int) $managerId > 0) {
                $ids[] = (int) $managerId;
            }
        } catch (Throwable $e) {
            $this->logger->warning('Failed to resolve project manager for field work notify', [
                'project_id' => $projectId,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $admins = $this->connection->fetchFirstColumn(
                "SELECT u.id
                 FROM fw_users u
                 INNER JOIN fw_glob_roles r ON u.role_id = r.id
                 WHERE r.code = 'admin' AND u.status = 1 AND u.archived_at IS NULL"
            );
            foreach ($admins as $adminId) {
                $ids[] = (int) $adminId;
            }
        } catch (Throwable $e) {
            $this->logger->warning('Failed to resolve admins for field work notify', [
                'error' => $e->getMessage(),
            ]);
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    private function resolveProjectName(int $projectId): string
    {
        try {
            $name = $this->connection->fetchOne(
                'SELECT prj_name FROM fw_projects WHERE id = ? LIMIT 1',
                [$projectId]
            );
            return $name !== false && $name !== null ? (string) $name : '';
        } catch (Throwable) {
            return '';
        }
    }

    private function resolveUserDisplayName(int $userId): string
    {
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT first_name, last_name, email FROM fw_users WHERE id = ? LIMIT 1',
                [$userId]
            );
            if (!$row) {
                return 'User #' . $userId;
            }
            $name = trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')));
            return $name !== '' ? $name : (string) ($row['email'] ?? ('User #' . $userId));
        } catch (Throwable) {
            return 'User #' . $userId;
        }
    }
}
