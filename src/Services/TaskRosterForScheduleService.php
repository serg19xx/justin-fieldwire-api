<?php

declare(strict_types=1);

namespace App\Services;

use Doctrine\DBAL\Connection;

/**
 * Single place for "user is on task roster" and auto-adding members when saving schedule slots,
 * aligned with fw_prj_team_members (task lead + members) used by task PATCH/create.
 */
final class TaskRosterForScheduleService
{
    /** Any row for (project, task, user) counts (task_lead, member, etc.). */
    public function isUserOnTask(Connection $conn, int $projectId, int $taskId, int $userId): bool
    {
        $one = $conn->executeQuery(
            'SELECT 1 FROM fw_prj_team_members WHERE project_id = ? AND task_id = ? AND user_id = ? LIMIT 1',
            [$projectId, $taskId, $userId]
        )->fetchOne();

        return (bool) $one;
    }

    /**
     * User may appear on project roster with task_id NULL or on any task in the project.
     */
    public function isUserProjectParticipant(Connection $conn, int $projectId, int $userId): bool
    {
        $one = $conn->executeQuery(
            'SELECT 1 FROM fw_prj_team_members WHERE project_id = ? AND user_id = ? LIMIT 1',
            [$projectId, $userId]
        )->fetchOne();

        return (bool) $one;
    }

    public function userExistsActive(Connection $conn, int $userId): bool
    {
        $id = $conn->executeQuery(
            'SELECT id FROM fw_v_users WHERE id = ? AND archived_at IS NULL',
            [$userId]
        )->fetchOne();

        return (bool) $id;
    }

    public function isTaskMilestone(Connection $conn, int $taskId, int $projectId): bool
    {
        $m = $conn->executeQuery(
            'SELECT milestone FROM fw_prj_tasks WHERE id = ? AND project_id = ?',
            [$taskId, $projectId]
        )->fetchOne();

        return $m !== null && $m !== '' && $m !== false;
    }

    /**
     * Ensures the user is on the task roster; may INSERT a member row. Call inside a transaction.
     *
     * @return non-empty-string|null Error message, or null on success
     */
    public function ensureUserOnTaskForScheduleSlot(Connection $conn, int $projectId, int $taskId, int $userId): ?string
    {
        if ($this->isUserOnTask($conn, $projectId, $taskId, $userId)) {
            return null;
        }
        if (!$this->userExistsActive($conn, $userId)) {
            return "cannot add user {$userId} to task {$taskId}: user not found or archived";
        }
        if (!$this->isUserProjectParticipant($conn, $projectId, $userId)) {
            return "cannot add user {$userId} to task {$taskId}: user is not a member of this project";
        }
        if ($this->isTaskMilestone($conn, $taskId, $projectId)) {
            return "cannot add user {$userId} to task {$taskId}: milestone tasks only support users already on the task roster (e.g. task lead); assign them on the task first";
        }
        try {
            $conn->executeStatement(
                "INSERT INTO fw_prj_team_members (project_id, task_id, user_id, role_in_project) VALUES (?, ?, ?, 'member')
                 ON DUPLICATE KEY UPDATE role_in_project = IF(role_in_project = 'task_lead', 'task_lead', 'member')",
                [$projectId, $taskId, $userId]
            );
        } catch (\Throwable $e) {
            return "cannot add user {$userId} to task {$taskId}: {$e->getMessage()}";
        }

        return null;
    }
}
