<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\ValueObjects\NotificationRequest;
use Doctrine\DBAL\Connection;
use Monolog\Logger;
use Throwable;

/**
 * Notify Admin/PM when a project becomes Active or leaves Active (inactive).
 * Draft and other pre-active transitions do not send notifications.
 */
class ProjectLifecycleNotificationService
{
    private Connection $connection;
    private NotificationDispatcher $dispatcher;

    public function __construct(
        private readonly Logger $logger,
        ?NotificationDispatcher $dispatcher = null,
    ) {
        $this->connection = Database::getConnection();
        $this->dispatcher = $dispatcher ?? new NotificationDispatcher($logger);
    }

    /**
     * @param array<string, mixed> $project
     */
    public function notifyIfLifecycleChanged(
        int $projectId,
        array $project,
        ?string $previousSysStatus,
        ?string $nextSysStatus,
        ?int $actorId = null,
    ): void {
        $before = $this->normalizeSysStatus($previousSysStatus);
        $after = $this->normalizeSysStatus($nextSysStatus);

        if ($before === $after) {
            return;
        }

        $kind = null;
        if ($after === 'active' && $before !== 'active') {
            $kind = 'active';
        } elseif ($before === 'active' && $after !== 'active') {
            $kind = 'inactive';
        }

        // Draft and other non-active transitions are silent.
        if ($kind === null) {
            $this->logger->info('Project sys_status change skipped for notifications', [
                'project_id' => $projectId,
                'from' => $before,
                'to' => $after,
            ]);
            return;
        }

        $recipients = $this->resolveRecipients($projectId, $project);
        if ($recipients === []) {
            $this->logger->warning('No recipients for project lifecycle notification', [
                'project_id' => $projectId,
                'kind' => $kind,
            ]);
            return;
        }

        $projectName = (string) ($project['prj_name'] ?? ('Project #' . $projectId));
        $title = $kind === 'active'
            ? "Project is active: {$projectName}"
            : "Project is inactive: {$projectName}";
        $message = $kind === 'active'
            ? "Project \"{$projectName}\" is now Active."
            : "Project \"{$projectName}\" is now inactive (lifecycle: " . ucfirst($after) . ").";
        $smsBody = $kind === 'active'
            ? "[FieldWire] Project active: {$projectName}"
            : "[FieldWire] Project inactive: {$projectName}";
        $eventType = $kind === 'active' ? 'PROJECT_BECAME_ACTIVE' : 'PROJECT_BECAME_INACTIVE';
        $url = "/projects/{$projectId}/detail";

        foreach ($recipients as $recipientId) {
            $correlation = substr(
                sprintf('prjlife:%s:%d:%s:u%d', $kind, $projectId, md5($before . '>' . $after), $recipientId),
                0,
                64
            );

            try {
                $this->dispatcher->dispatch(new NotificationRequest(
                    recipientUserId: $recipientId,
                    type: $eventType,
                    title: $title,
                    message: $message,
                    channels: ['email', 'sms', 'push'],
                    priority: 'high',
                    senderUserId: $actorId,
                    correlationId: $correlation,
                    url: $url,
                    data: [
                        'project_id' => $projectId,
                        'project_name' => $projectName,
                        'previous_sys_status' => $before,
                        'sys_status' => $after,
                        'lifecycle' => $kind,
                    ],
                    emailSubject: $title,
                    emailHtml: $message,
                    smsBody: $smsBody,
                    pushTitle: $title,
                    pushBody: $smsBody,
                    // Lifecycle alerts are operational; deliver even if personal event opt-in is empty.
                    bypassPreferences: true,
                ));
            } catch (Throwable $e) {
                $this->logger->error('Failed to notify about project lifecycle', [
                    'recipient_id' => $recipientId,
                    'project_id' => $projectId,
                    'kind' => $kind,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function normalizeSysStatus(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return 'draft';
        }
        $normalized = strtolower(trim($value));
        $normalized = str_replace([' ', '_'], '', $normalized);

        return match ($normalized) {
            'active', 'inprogress', 'underway' => 'active',
            'closing', 'closingout' => 'closing',
            'suspended', 'onhold', 'inactive' => 'suspended',
            'done', 'completed', 'archived' => 'done',
            'draft', 'planned' => 'draft',
            default => 'draft',
        };
    }

    /**
     * @param array<string, mixed> $project
     * @return list<int>
     */
    private function resolveRecipients(int $projectId, array $project): array
    {
        $ids = [];

        $managerId = isset($project['prj_manager']) ? (int) $project['prj_manager'] : 0;
        if ($managerId <= 0) {
            try {
                $managerId = (int) ($this->connection->fetchOne(
                    'SELECT p.prj_manager
                     FROM fw_projects p
                     INNER JOIN fw_users u ON u.id = p.prj_manager
                     WHERE p.id = ? AND u.status = 1 AND u.archived_at IS NULL
                     LIMIT 1',
                    [$projectId]
                ) ?: 0);
            } catch (Throwable) {
                $managerId = 0;
            }
        } else {
            // Ensure assigned manager is still active.
            try {
                $ok = $this->connection->fetchOne(
                    'SELECT id FROM fw_users WHERE id = ? AND status = 1 AND archived_at IS NULL LIMIT 1',
                    [$managerId]
                );
                if ($ok === false || $ok === null) {
                    $managerId = 0;
                }
            } catch (Throwable) {
                $managerId = 0;
            }
        }
        if ($managerId > 0) {
            $ids[] = $managerId;
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
            $this->logger->warning('Failed to resolve admins for project lifecycle notify', [
                'error' => $e->getMessage(),
            ]);
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }
}
