<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use Doctrine\DBAL\Connection;
use Monolog\Logger;
use Throwable;

/**
 * Live operational dashboard (no snapshot storage).
 * Aggregates current DB state for global or single-project scope.
 */
class DashboardService
{
    private Connection $connection;

    public function __construct(private readonly Logger $logger)
    {
        $this->connection = Database::getConnection();
    }

    /**
     * @param list<int>|null $allowedProjectIds null = all projects (admin)
     * @return array<string, mixed>
     */
    public function buildGlobal(?array $allowedProjectIds = null): array
    {
        $projectIds = $this->resolveProjectIds($allowedProjectIds, null);
        $today = (new \DateTimeImmutable('now'))->format('Y-m-d');

        return [
            'generated_at' => $this->nowIso(),
            'scope' => 'global',
            'project_id' => null,
            'project_name' => null,
            'kpis' => $this->buildKpis($projectIds, $today),
            'alerts' => $this->buildAlerts($projectIds, $today),
            'activity' => $this->buildActivity($projectIds, 30),
        ];
    }

    /**
     * Live dashboard for field roles (foreman, worker, contractor) scoped to assigned projects.
     *
     * @return array<string, mixed>
     */
    public function buildField(int $userId): array
    {
        $projectIds = $this->resolveFieldProjectIds($userId);
        $today = (new \DateTimeImmutable('now'))->format('Y-m-d');

        return [
            'generated_at' => $this->nowIso(),
            'scope' => 'field',
            'project_id' => null,
            'project_name' => null,
            'kpis' => $this->buildKpis($projectIds, $today),
            'alerts' => $this->buildAlerts($projectIds, $today),
            'activity' => $this->buildActivity($projectIds, 30),
            'messages' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildForProject(int $projectId): array
    {
        $project = $this->connection->fetchAssociative(
            'SELECT id, prj_name FROM fw_projects WHERE id = ? LIMIT 1',
            [$projectId]
        );
        if (!$project) {
            throw new \RuntimeException('Project not found');
        }

        $today = (new \DateTimeImmutable('now'))->format('Y-m-d');
        $projectIds = [$projectId];

        return [
            'generated_at' => $this->nowIso(),
            'scope' => 'project',
            'project_id' => $projectId,
            'project_name' => (string) ($project['prj_name'] ?? ('Project #' . $projectId)),
            'kpis' => $this->buildKpis($projectIds, $today),
            'alerts' => $this->buildAlerts($projectIds, $today),
            'activity' => $this->buildActivity($projectIds, 30),
        ];
    }

    /**
     * Projects where the user is on the team or assigned as project foreman.
     *
     * @return list<int>
     */
    private function resolveFieldProjectIds(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        try {
            $conditions = [
                'EXISTS (
                    SELECT 1
                    FROM fw_prj_team_members tm
                    WHERE tm.project_id = p.id
                      AND tm.user_id = ?
                )',
            ];
            $params = [$userId];

            if ($this->projectForemanColumnPresent()) {
                $conditions[] = 'p.project_foreman_id = ?';
                $params[] = $userId;
            }

            $sql = 'SELECT DISTINCT p.id FROM fw_projects p WHERE (' . implode(' OR ', $conditions) . ') ORDER BY p.id ASC';
            $rows = $this->connection->fetchFirstColumn($sql, $params);

            return array_values(array_filter(
                array_map(static fn ($id): int => (int) $id, $rows),
                static fn (int $id): bool => $id > 0
            ));
        } catch (Throwable $e) {
            $this->logger->warning('Dashboard: failed to resolve field project ids', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    private function projectForemanColumnPresent(): bool
    {
        try {
            return (bool) $this->connection->fetchOne(
                "SHOW COLUMNS FROM fw_projects LIKE 'project_foreman_id'"
            );
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param list<int>|null $allowedProjectIds
     * @return list<int>
     */
    private function resolveProjectIds(?array $allowedProjectIds, ?int $singleProjectId): array
    {
        if ($singleProjectId !== null && $singleProjectId > 0) {
            return [$singleProjectId];
        }

        try {
            if ($allowedProjectIds === null) {
                $rows = $this->connection->fetchFirstColumn(
                    "SELECT id FROM fw_projects
                     WHERE LOWER(COALESCE(sys_status, 'draft')) = 'active'
                        OR sys_status = 'Active'
                     ORDER BY id ASC"
                );
            } elseif ($allowedProjectIds === []) {
                return [];
            } else {
                $placeholders = implode(',', array_fill(0, count($allowedProjectIds), '?'));
                $rows = $this->connection->fetchFirstColumn(
                    "SELECT id FROM fw_projects WHERE id IN ({$placeholders}) ORDER BY id ASC",
                    array_map(static fn (int $id): int => $id, $allowedProjectIds)
                );
            }
            return array_values(array_filter(array_map(static fn ($id): int => (int) $id, $rows), static fn (int $id): bool => $id > 0));
        } catch (Throwable $e) {
            $this->logger->warning('Dashboard: failed to resolve project ids', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * @param list<int> $projectIds
     * @return array<string, int>
     */
    private function buildKpis(array $projectIds, string $today): array
    {
        if ($projectIds === []) {
            return [
                'active_projects' => 0,
                'field_work_started_today' => 0,
                'field_work_ended_today' => 0,
                'field_work_open_today' => 0,
                'foreman_submitted_today' => 0,
                'urgent_last_7_days' => 0,
                'events_last_24h' => 0,
            ];
        }

        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));

        $activeProjects = count($projectIds);

        $startedToday = $this->safeCount(
            "SELECT COUNT(*) FROM fw_prj_tasks
             WHERE project_id IN ({$placeholders})
               AND DATE(field_work_started_at) = ?",
            array_merge($projectIds, [$today])
        );

        $endedToday = $this->safeCount(
            "SELECT COUNT(*) FROM fw_prj_tasks
             WHERE project_id IN ({$placeholders})
               AND DATE(field_work_ended_at) = ?",
            array_merge($projectIds, [$today])
        );

        $openToday = $this->safeCount(
            "SELECT COUNT(*) FROM fw_prj_tasks
             WHERE project_id IN ({$placeholders})
               AND DATE(field_work_started_at) = ?
               AND field_work_ended_at IS NULL",
            array_merge($projectIds, [$today])
        );

        $submittedToday = $this->safeCount(
            "SELECT COUNT(*) FROM fw_prj_tasks
             WHERE project_id IN ({$placeholders})
               AND DATE(field_submitted_at) = ?",
            array_merge($projectIds, [$today])
        );

        $urgent = $this->countUrgent($projectIds, 7);
        $events24h = $this->countEventsLastHours($projectIds, 24);

        return [
            'active_projects' => $activeProjects,
            'field_work_started_today' => $startedToday,
            'field_work_ended_today' => $endedToday,
            'field_work_open_today' => $openToday,
            'foreman_submitted_today' => $submittedToday,
            'urgent_last_7_days' => $urgent,
            'events_last_24h' => $events24h,
        ];
    }

    /**
     * @param list<int> $projectIds
     * @return list<array<string, mixed>>
     */
    private function buildAlerts(array $projectIds, string $today): array
    {
        if ($projectIds === []) {
            return [];
        }

        $alerts = [];
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));

        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT t.id AS task_id, t.name AS task_name, t.project_id, p.prj_name AS project_name,
                        t.field_work_started_at
                 FROM fw_prj_tasks t
                 INNER JOIN fw_projects p ON p.id = t.project_id
                 WHERE t.project_id IN ({$placeholders})
                   AND DATE(t.field_work_started_at) = ?
                   AND t.field_work_ended_at IS NULL
                   AND t.field_submitted_at IS NULL
                 ORDER BY t.field_work_started_at ASC
                 LIMIT 15",
                array_merge($projectIds, [$today])
            );
            foreach ($rows as $row) {
                $alerts[] = [
                    'severity' => 'warning',
                    'code' => 'FIELD_WORK_NOT_CLOSED',
                    'message' => sprintf(
                        '%s — field work started, not ended or submitted',
                        (string) ($row['task_name'] ?? ('Task #' . (int) ($row['task_id'] ?? 0)))
                    ),
                    'project_id' => (int) ($row['project_id'] ?? 0),
                    'project_name' => (string) ($row['project_name'] ?? ''),
                    'task_id' => (int) ($row['task_id'] ?? 0),
                    'task_name' => (string) ($row['task_name'] ?? ''),
                    'at' => (string) ($row['field_work_started_at'] ?? ''),
                ];
            }
        } catch (Throwable $e) {
            $this->logger->warning('Dashboard: failed to build alerts', ['error' => $e->getMessage()]);
        }

        return $alerts;
    }

    /**
     * @param list<int> $projectIds
     * @return list<array<string, mixed>>
     */
    private function buildActivity(array $projectIds, int $limit): array
    {
        if ($projectIds === []) {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));

        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT el.occurred_at, el.event_type, el.comment, el.entity_type, el.entity_id,
                        el.after_data, p.id AS project_id, p.prj_name AS project_name,
                        t.id AS task_id, t.name AS task_name
                 FROM fw_event_log el
                 LEFT JOIN fw_prj_tasks t ON el.entity_type = 'task' AND el.entity_id = t.id
                 LEFT JOIN fw_projects p ON (
                   (el.entity_type = 'project' AND el.entity_id = p.id)
                   OR (t.project_id IS NOT NULL AND t.project_id = p.id)
                 )
                 WHERE (
                   (el.entity_type = 'project' AND el.entity_id IN ({$placeholders}))
                   OR (t.project_id IN ({$placeholders}))
                 )
                 AND el.occurred_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
                 ORDER BY el.occurred_at DESC
                 LIMIT {$limit}",
                array_merge($projectIds, $projectIds)
            );
        } catch (Throwable $e) {
            $this->logger->warning('Dashboard: failed to build activity feed', ['error' => $e->getMessage()]);
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            $after = $this->decodeJson($row['after_data'] ?? null);
            $projectId = (int) ($row['project_id'] ?? $after['project_id'] ?? 0);
            $taskId = (int) ($row['task_id'] ?? ($row['entity_type'] === 'task' ? ($row['entity_id'] ?? 0) : 0));
            $items[] = [
                'at' => (string) ($row['occurred_at'] ?? ''),
                'event_type' => (string) ($row['event_type'] ?? ''),
                'title' => $this->activityTitle($row, $after),
                'project_id' => $projectId,
                'project_name' => (string) ($row['project_name'] ?? $after['project_name'] ?? ''),
                'task_id' => $taskId > 0 ? $taskId : null,
                'task_name' => (string) ($row['task_name'] ?? $after['task_name'] ?? $after['name'] ?? '') ?: null,
                'comment' => (string) ($row['comment'] ?? '') ?: null,
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $after
     */
    private function activityTitle(array $row, array $after): string
    {
        $type = (string) ($row['event_type'] ?? '');
        $taskName = (string) ($row['task_name'] ?? $after['task_name'] ?? $after['name'] ?? '');
        if ($taskName !== '') {
            return $this->humanizeEventType($type) . ' — ' . $taskName;
        }
        if ($type !== '') {
            return $this->humanizeEventType($type);
        }
        return 'Activity';
    }

    private function humanizeEventType(string $type): string
    {
        return str_replace('_', ' ', strtolower($type));
    }

    /**
     * @param list<int> $projectIds
     */
    private function countUrgent(array $projectIds, int $days): int
    {
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT data FROM fw_notifications
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                   AND (
                     title LIKE '%Urgent%'
                     OR message LIKE '%[Urgent]%'
                     OR data LIKE '%\"urgent\":true%'
                     OR data LIKE '%\"urgent\":1%'
                   )",
                [$days]
            );
            $count = 0;
            foreach ($rows as $row) {
                $data = $this->decodeJson($row['data'] ?? null);
                $pid = (int) ($data['project_id'] ?? 0);
                if ($pid > 0 && in_array($pid, $projectIds, true)) {
                    $count++;
                }
            }
            return $count;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param list<int> $projectIds
     */
    private function countEventsLastHours(array $projectIds, int $hours): int
    {
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        try {
            $direct = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM fw_event_log
                 WHERE entity_type = 'project' AND entity_id IN ({$placeholders})
                   AND occurred_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)",
                array_merge($projectIds, [$hours])
            );
            $taskEvents = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM fw_event_log el
                 INNER JOIN fw_prj_tasks t ON el.entity_type = 'task' AND el.entity_id = t.id
                 WHERE t.project_id IN ({$placeholders})
                   AND el.occurred_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)",
                array_merge($projectIds, [$hours])
            );
            return $direct + $taskEvents;
        } catch (Throwable) {
            return 0;
        }
    }

    /** @param list<mixed> $params */
    private function safeCount(string $sql, array $params): int
    {
        try {
            return (int) ($this->connection->fetchOne($sql, $params) ?: 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return array<string, mixed> */
    private function decodeJson(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function nowIso(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
