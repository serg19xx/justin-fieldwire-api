<?php

declare(strict_types=1);

namespace App\Services;

use Doctrine\DBAL\Connection;
use Throwable;

/**
 * Project-level read/manage checks for shared project resources (plans, team roster).
 *
 * View: office staff (admin, project manager), the project's PM / foreman, anyone on the
 * project team (project or task level), and client users linked to the project.
 * Manage: office staff only.
 */
final class ProjectAccessService
{
    private const OFFICE_ROLES = ['admin', 'project_manager'];

    /** fw_projects / fw_project_clients client_table values per client user column */
    private const CLIENT_TABLE_BY_USER_COLUMN = [
        'physician_id' => 'physician',
        'pharmacist_id' => 'pharmacist',
    ];

    /** @var array<int, bool> */
    private array $projectExistsCache = [];

    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * @param array<string, mixed>|null $user
     */
    public function isOfficeUser(?array $user): bool
    {
        return in_array($user['role_code'] ?? null, self::OFFICE_ROLES, true);
    }

    /**
     * @param array<string, mixed>|null $user
     */
    public function canManageProject(?array $user, int $projectId): bool
    {
        return $this->isOfficeUser($user) && $this->projectExists($projectId);
    }

    /**
     * @param array<string, mixed>|null $user
     */
    public function canViewProject(?array $user, int $projectId): bool
    {
        if ($projectId <= 0 || !$this->projectExists($projectId)) {
            return false;
        }
        if ($this->isOfficeUser($user)) {
            return true;
        }
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }

        try {
            return $this->isProjectLeadership($userId, $projectId)
                || $this->isOnProjectTeam($userId, $projectId)
                || $this->isLinkedClient($userId, $projectId);
        } catch (Throwable) {
            return false;
        }
    }

    public function projectIdForPlanFolder(int $folderId): ?int
    {
        $id = $this->conn->fetchOne('SELECT project_id FROM fw_plan_folders WHERE id = ?', [$folderId]);

        return $id === false || $id === null ? null : (int) $id;
    }

    public function projectIdForPlanFile(int $fileId): ?int
    {
        $id = $this->conn->fetchOne(
            'SELECT f.project_id FROM fw_plan_files pf JOIN fw_plan_folders f ON f.id = pf.folder_id WHERE pf.id = ?',
            [$fileId]
        );

        return $id === false || $id === null ? null : (int) $id;
    }

    private function projectExists(int $projectId): bool
    {
        if (!array_key_exists($projectId, $this->projectExistsCache)) {
            $id = $this->conn->fetchOne('SELECT id FROM fw_projects WHERE id = ?', [$projectId]);
            $this->projectExistsCache[$projectId] = $id !== false && $id !== null;
        }

        return $this->projectExistsCache[$projectId];
    }

    private function isProjectLeadership(int $userId, int $projectId): bool
    {
        $sql = 'SELECT 1 FROM fw_projects WHERE id = ? AND (prj_manager = ?';
        $params = [$projectId, $userId];
        if ($this->conn->fetchOne("SHOW COLUMNS FROM fw_projects LIKE 'project_foreman_id'")) {
            $sql .= ' OR project_foreman_id = ?';
            $params[] = $userId;
        }
        $sql .= ')';

        return (bool) $this->conn->fetchOne($sql, $params);
    }

    private function isOnProjectTeam(int $userId, int $projectId): bool
    {
        return (bool) $this->conn->fetchOne(
            'SELECT 1 FROM fw_prj_team_members WHERE project_id = ? AND user_id = ? LIMIT 1',
            [$projectId, $userId]
        );
    }

    /**
     * Same matching as the client portals: client record id per table, or the client email.
     */
    private function isLinkedClient(int $userId, int $projectId): bool
    {
        $user = $this->conn->fetchAssociative(
            'SELECT u.email, u.physician_id, u.pharmacist_id, v.role_category
             FROM fw_users u JOIN fw_v_users v ON v.id = u.id WHERE u.id = ?',
            [$userId]
        );
        if (!$user || ($user['role_category'] ?? null) !== 'client') {
            return false;
        }

        $email = strtolower(trim((string) ($user['email'] ?? '')));
        $clientIds = [];
        foreach (self::CLIENT_TABLE_BY_USER_COLUMN as $column => $table) {
            if (!empty($user[$column])) {
                $clientIds[$table] = (int) $user[$column];
            }
        }

        foreach ($this->projectClientLinks($projectId) as $link) {
            $table = strtolower((string) ($link['client_table'] ?? ''));
            if (isset($clientIds[$table]) && (int) ($link['client_id'] ?? 0) === $clientIds[$table]) {
                return true;
            }
            if ($email !== '' && $this->clientDataEmail($link['client_data'] ?? null) === $email) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function projectClientLinks(int $projectId): array
    {
        $project = $this->conn->fetchAssociative(
            'SELECT client_table, client_id, client_data, client2_table, client2_id, client2_data FROM fw_projects WHERE id = ?',
            [$projectId]
        );
        if (!$project) {
            return [];
        }

        $links = [
            ['client_table' => $project['client_table'], 'client_id' => $project['client_id'], 'client_data' => $project['client_data']],
            ['client_table' => $project['client2_table'], 'client_id' => $project['client2_id'], 'client_data' => $project['client2_data']],
        ];

        try {
            $rows = $this->conn->fetchAllAssociative(
                'SELECT client_table, client_id, client_data FROM fw_project_clients WHERE project_id = ?',
                [$projectId]
            );
            array_push($links, ...$rows);
        } catch (Throwable) {
            // fw_project_clients is optional on older databases
        }

        return $links;
    }

    private function clientDataEmail(mixed $raw): string
    {
        $data = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($data)) {
            return '';
        }

        return strtolower(trim((string) ($data['email'] ?? '')));
    }
}
