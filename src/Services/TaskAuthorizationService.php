<?php

declare(strict_types=1);

namespace App\Services;

use Doctrine\DBAL\Connection;

/**
 * Field-level authorization for project task updates.
 * PM / admin: full control. Task lead and assigned foreman/worker: progress + field work. Contractors: read-only.
 */
class TaskAuthorizationService
{
    private static ?bool $projectForemanColumnExists = null;

    private const PM_GLOBAL_ROLES = ['admin', 'project_manager'];

    private const FIELD_FOREMAN_GLOBAL_ROLES = ['foreman'];

    /** Global roles that may record field work when assigned as task members (not task lead). */
    private const FIELD_CREW_GLOBAL_ROLES = ['foreman', 'worker'];

    /** Keys accepted on PUT /tasks/{id} (including assignee aliases). */
    private const ALL_UPDATE_KEYS = [
        'address',
        'wbs_path',
        'name',
        'start_planned',
        'end_planned',
        'start_time',
        'end_time',
        'milestone',
        'status',
        'progress_pct',
        'notes',
        'task_lead_id',
        'team_members',
        'invited_people',
        'resources',
        'baseline_start',
        'baseline_end',
        'actual_start',
        'actual_end',
        'field_work_started_at',
        'field_work_ended_at',
        'field_work_start_reason',
        'field_work_end_reason',
        'field_notes',
        'slack_days',
        'duration_days',
    ];

    private const TASK_LEAD_ALLOWED_KEYS = [
        'progress_pct',
        'field_work_started_at',
        'field_work_ended_at',
        'field_work_start_reason',
        'field_work_end_reason',
        'field_notes',
    ];

    /**
     * Same lead detection as TaskController when resolving task_lead_id from fw_prj_team_members.
     */
    public function isTaskLeadProjectRole(?string $role): bool
    {
        if ($role === null || $role === '') {
            return false;
        }
        $r = strtolower($role);

        return $r === 'task_lead'
            || str_contains($r, 'lead')
            || str_contains($r, 'supervisor')
            || str_contains($r, 'manager')
            || str_contains($r, 'foreman');
    }

    public function getGlobalRoleCode(Connection $conn, int $userId): ?string
    {
        $row = $conn->executeQuery(
            'SELECT role_code FROM fw_v_users WHERE id = ? LIMIT 1',
            [$userId]
        )->fetchAssociative();

        $code = $row['role_code'] ?? null;

        return is_string($code) ? strtolower($code) : null;
    }

    public function isProjectTaskManager(Connection $conn, int $userId, int $projectId): bool
    {
        $role = $this->getGlobalRoleCode($conn, $userId);
        if ($role !== null && in_array($role, self::PM_GLOBAL_ROLES, true)) {
            return true;
        }

        $row = $conn->executeQuery(
            'SELECT prj_manager FROM fw_projects WHERE id = ? LIMIT 1',
            [$projectId]
        )->fetchAssociative();

        return $row
            && isset($row['prj_manager'])
            && $row['prj_manager'] !== null
            && (int) $row['prj_manager'] === $userId;
    }

    /**
     * @return 'task_lead'|'member'|null
     */
    public function getTaskAssignmentRole(Connection $conn, int $taskId, int $userId): ?string
    {
        $rows = $conn->executeQuery(
            'SELECT role_in_project FROM fw_prj_team_members WHERE task_id = ? AND user_id = ?',
            [$taskId, $userId]
        )->fetchAllAssociative();

        foreach ($rows as $row) {
            if ($this->isTaskLeadProjectRole($row['role_in_project'] ?? null)) {
                return 'task_lead';
            }
        }

        if ($rows !== []) {
            return 'member';
        }

        return null;
    }

    public function isTaskLead(Connection $conn, int $taskId, int $userId): bool
    {
        return $this->getTaskAssignmentRole($conn, $taskId, $userId) === 'task_lead';
    }

    public function isAssignedToTask(Connection $conn, int $taskId, int $userId): bool
    {
        $count = $conn->executeQuery(
            'SELECT COUNT(*) FROM fw_prj_team_members WHERE task_id = ? AND user_id = ?',
            [$taskId, $userId]
        )->fetchOne();

        return (int) $count > 0;
    }

    /**
     * Global foreman assigned to the task may record field work even when role_in_project is member.
     */
    public function canActAsFieldForeman(Connection $conn, int $taskId, int $userId): bool
    {
        $role = $this->getGlobalRoleCode($conn, $userId);
        if ($role === null || !in_array($role, self::FIELD_FOREMAN_GLOBAL_ROLES, true)) {
            return false;
        }

        return $this->isAssignedToTask($conn, $taskId, $userId);
    }

    /**
     * Assigned foreman or worker may record field work when the task lead is off site.
     */
    public function canActAsFieldCrew(Connection $conn, int $taskId, int $userId): bool
    {
        $role = $this->getGlobalRoleCode($conn, $userId);
        if ($role === null || !in_array($role, self::FIELD_CREW_GLOBAL_ROLES, true)) {
            return false;
        }

        return $this->isAssignedToTask($conn, $taskId, $userId);
    }

    public function canSubmitFieldWork(Connection $conn, int $projectId, int $taskId, int $userId): bool
    {
        return $this->isTaskLead($conn, $taskId, $userId)
            || $this->canActAsFieldCrew($conn, $taskId, $userId)
            || $this->canActAsProjectForemanOnTask($conn, $projectId, $userId);
    }

    public function projectForemanColumnPresent(Connection $conn): bool
    {
        if (self::$projectForemanColumnExists !== null) {
            return self::$projectForemanColumnExists;
        }

        $row = $conn->executeQuery(
            'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            ['fw_projects', 'project_foreman_id']
        )->fetchAssociative();

        self::$projectForemanColumnExists = (int) ($row['c'] ?? 0) > 0;

        return self::$projectForemanColumnExists;
    }

    public function resolveProjectForemanUserId(Connection $conn, int $projectId): ?int
    {
        if (!$this->projectForemanColumnPresent($conn)) {
            return null;
        }

        $row = $conn->executeQuery(
            'SELECT project_foreman_id FROM fw_projects WHERE id = ? LIMIT 1',
            [$projectId]
        )->fetchAssociative();

        if (!$row || $row['project_foreman_id'] === null) {
            return null;
        }

        $id = (int) $row['project_foreman_id'];

        return $id > 0 ? $id : null;
    }

    public function isProjectForeman(Connection $conn, int $projectId, int $userId): bool
    {
        $foremanId = $this->resolveProjectForemanUserId($conn, $projectId);

        return $foremanId !== null && $foremanId === $userId;
    }

    /**
     * Project foreman may record field work on any task in the project (coordinates crews by phone).
     */
    public function canActAsProjectForemanOnTask(Connection $conn, int $projectId, int $userId): bool
    {
        if (!$this->isProjectForeman($conn, $projectId, $userId)) {
            return false;
        }

        $role = $this->getGlobalRoleCode($conn, $userId);

        return $role !== null && in_array($role, self::FIELD_FOREMAN_GLOBAL_ROLES, true);
    }

    /**
     * Accountable foreman for field-work events: task override when different from project foreman.
     */
    public function resolveAccountableForemanUserId(Connection $conn, int $projectId, int $taskId): ?int
    {
        $projectForemanId = $this->resolveProjectForemanUserId($conn, $projectId);
        $taskLeadId = $this->resolveTaskLeadUserId($conn, $taskId);

        if ($taskLeadId !== null && $projectForemanId !== null && $taskLeadId !== $projectForemanId) {
            return $taskLeadId;
        }

        if ($taskLeadId !== null) {
            return $taskLeadId;
        }

        return $projectForemanId;
    }

    public function resolveTaskLeadUserId(Connection $conn, int $taskId): ?int
    {
        $rows = $conn->executeQuery(
            'SELECT user_id, role_in_project FROM fw_prj_team_members WHERE task_id = ?',
            [$taskId]
        )->fetchAllAssociative();

        foreach ($rows as $row) {
            if ($this->isTaskLeadProjectRole($row['role_in_project'] ?? null)) {
                return (int) $row['user_id'];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{allowed: bool, message?: string, filtered?: array<string, mixed>}
     */
    public function authorizeTaskUpdate(
        Connection $conn,
        int $userId,
        int $projectId,
        int $taskId,
        array $data,
    ): array {
        if ($this->isProjectTaskManager($conn, $userId, $projectId)) {
            return ['allowed' => true, 'filtered' => $data];
        }

        $presentKeys = $this->extractPresentUpdateKeys($data);
        if ($presentKeys === []) {
            return ['allowed' => false, 'message' => 'No fields to update'];
        }

        $assignment = $this->getTaskAssignmentRole($conn, $taskId, $userId);
        $isProjectForemanActor = $this->canActAsProjectForemanOnTask($conn, $projectId, $userId);

        if ($assignment === null && !$isProjectForemanActor) {
            return ['allowed' => false, 'message' => 'You are not assigned to this task'];
        }

        if ($assignment === 'member') {
            if (!$this->canActAsFieldCrew($conn, $taskId, $userId)) {
                return ['allowed' => false, 'message' => 'You can only view task field data'];
            }
        }

        // task_lead — ignore null values for keys they cannot set (older clients)
        foreach (array_keys($data) as $key) {
            if (($data[$key] ?? null) === null && !in_array($key, self::TASK_LEAD_ALLOWED_KEYS, true)) {
                unset($data[$key]);
            }
        }
        $presentKeys = $this->extractPresentUpdateKeys($data);
        if ($presentKeys === []) {
            return ['allowed' => false, 'message' => 'No fields to update'];
        }

        // task_lead
        $forbidden = array_diff($presentKeys, self::TASK_LEAD_ALLOWED_KEYS);
        if ($forbidden !== []) {
            return [
                'allowed' => false,
                'message' => 'Task lead can only update progress and field work data',
            ];
        }

        $filtered = [];
        foreach (self::TASK_LEAD_ALLOWED_KEYS as $key) {
            if (array_key_exists($key, $data)) {
                $filtered[$key] = $data[$key];
            }
        }

        return ['allowed' => true, 'filtered' => $filtered];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function extractPresentUpdateKeys(array $data): array
    {
        $keys = [];
        foreach (self::ALL_UPDATE_KEYS as $key) {
            if (array_key_exists($key, $data)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
