<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use App\Services\DashboardService;
use Doctrine\DBAL\Connection;
use Flight;
use Monolog\Logger;
use Throwable;

/**
 * Live operational dashboard API (no snapshot storage).
 */
class DashboardController
{
    private Connection $connection;
    private DashboardService $service;

    public function __construct(private readonly Logger $logger)
    {
        $this->connection = Database::getConnection();
        $this->service = new DashboardService($logger);
    }

    /** GET /api/v1/dashboard/global */
    public function global(): void
    {
        $user = Flight::get('current_user');
        if (!$this->canAccessGlobal($user)) {
            $this->json(403, 'error', 'Forbidden', null);
            return;
        }

        try {
            $data = $this->service->buildGlobal($this->allowedProjectIds($user));
            $this->json(0, 'success', 'Dashboard retrieved', $data);
        } catch (Throwable $e) {
            $this->logger->error('Failed to build global dashboard', ['error' => $e->getMessage()]);
            $this->json(500, 'error', 'Failed to load dashboard', null);
        }
    }

    /** GET /api/v1/dashboard/field */
    public function field(): void
    {
        $user = Flight::get('current_user');
        if (!$this->canAccessField($user)) {
            $this->json(403, 'error', 'Forbidden', null);
            return;
        }

        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            $this->json(401, 'error', 'Unauthorized', null);
            return;
        }

        try {
            $data = $this->service->buildField($userId);
            $this->json(0, 'success', 'Dashboard retrieved', $data);
        } catch (Throwable $e) {
            $this->logger->error('Failed to build field dashboard', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            $this->json(500, 'error', 'Failed to load dashboard', null);
        }
    }

    /** GET /api/v1/dashboard/project/@project_id */
    public function forProject(int $projectId): void
    {
        $user = Flight::get('current_user');
        if (!$this->canAccessProject($user, $projectId)) {
            $this->json(403, 'error', 'Forbidden', null);
            return;
        }

        try {
            $data = $this->service->buildForProject($projectId);
            $this->json(0, 'success', 'Dashboard retrieved', $data);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'Project not found') {
                $this->json(404, 'error', 'Project not found', null);
                return;
            }
            $this->logger->error('Failed to build project dashboard', [
                'project_id' => $projectId,
                'error' => $e->getMessage(),
            ]);
            $this->json(500, 'error', 'Failed to load dashboard', null);
        } catch (Throwable $e) {
            $this->logger->error('Failed to build project dashboard', [
                'project_id' => $projectId,
                'error' => $e->getMessage(),
            ]);
            $this->json(500, 'error', 'Failed to load dashboard', null);
        }
    }

    /**
     * @param array<string, mixed>|null $user
     */
    private function canAccessField(?array $user): bool
    {
        if ($user === null) {
            return false;
        }

        if (($user['role_category'] ?? null) === 'task') {
            return true;
        }

        $role = strtolower((string) ($user['role_code'] ?? ''));
        return in_array($role, ['worker', 'foreman', 'contractor'], true);
    }

    /**
     * @param array<string, mixed>|null $user
     */
    private function canAccessGlobal(?array $user): bool
    {
        $role = $user['role_code'] ?? null;
        return $role === 'admin' || $role === 'project_manager';
    }

    /**
     * @param array<string, mixed>|null $user
     */
    private function canAccessProject(?array $user, int $projectId): bool
    {
        if ($this->canAccessGlobal($user)) {
            return $this->projectExists($projectId);
        }

        $allowed = $this->allowedProjectIds($user);
        if ($allowed === null) {
            return $this->projectExists($projectId);
        }
        return in_array($projectId, $allowed, true);
    }

    private function projectExists(int $projectId): bool
    {
        try {
            $id = $this->connection->fetchOne('SELECT id FROM fw_projects WHERE id = ? LIMIT 1', [$projectId]);
            return $id !== false && $id !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed>|null $user
     * @return list<int>|null
     */
    private function allowedProjectIds(?array $user): ?array
    {
        if (($user['role_code'] ?? null) === 'admin') {
            return null;
        }

        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return [];
        }

        $hasForeman = $this->projectForemanColumnPresent();
        $sql = 'SELECT id FROM fw_projects WHERE prj_manager = ?';
        $params = [$userId];
        if ($hasForeman) {
            $sql .= ' OR project_foreman_id = ?';
            $params[] = $userId;
        }

        try {
            $ids = $this->connection->fetchFirstColumn($sql, $params);
            return array_map(static fn ($v): int => (int) $v, $ids);
        } catch (Throwable $e) {
            $this->logger->error('Failed to resolve allowed projects for dashboard', [
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

    private function json(int $errorCode, string $status, string $message, mixed $data, int $httpStatus = 200): void
    {
        $http = $status === 'success' ? 200 : ($errorCode >= 400 ? $errorCode : $httpStatus);
        Flight::json([
            'error_code' => $errorCode,
            'status' => $status,
            'message' => $message,
            'data' => $data,
        ], $http);
    }
}
