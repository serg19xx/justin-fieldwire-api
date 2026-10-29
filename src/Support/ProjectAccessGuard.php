<?php

declare(strict_types=1);

namespace App\Support;

use App\Database\Database;
use App\Services\ProjectAccessService;
use Flight;

/**
 * Route-level project access checks. Each method sends a 403/404 JSON response and returns false
 * when access is denied, so routes can do: if (!ProjectAccessGuard::canView($id)) return;
 */
final class ProjectAccessGuard
{
    private static ?ProjectAccessService $service = null;

    public static function canView(?int $projectId): bool
    {
        return self::check($projectId, false);
    }

    public static function canManage(?int $projectId): bool
    {
        return self::check($projectId, true);
    }

    public static function canViewPlanFolder(int $folderId): bool
    {
        if ($folderId < 0) {
            // Virtual folders (task photos, schedule documents) are scoped by ?project_id=
            return self::canView(isset($_GET['project_id']) ? (int) $_GET['project_id'] : null);
        }

        return self::canView(self::service()->projectIdForPlanFolder($folderId));
    }

    public static function canManagePlanFolder(int $folderId): bool
    {
        return self::canManage(self::service()->projectIdForPlanFolder($folderId));
    }

    public static function canViewPlanFile(int $fileId): bool
    {
        if ($fileId < 0) {
            // Virtual files are task photos / schedule documents; their controllers check access
            return true;
        }

        return self::canView(self::service()->projectIdForPlanFile($fileId));
    }

    public static function canManagePlanFile(int $fileId): bool
    {
        return self::canManage(self::service()->projectIdForPlanFile($fileId));
    }

    /**
     * Destination folder of a move/copy must belong to a project the user manages.
     */
    public static function canManageTargetPlanFolder(string $bodyKey): bool
    {
        $body = json_decode((string) file_get_contents('php://input'), true);
        $target = is_array($body) ? ($body[$bodyKey] ?? null) : null;
        if ($target === null || $target === '') {
            return true;
        }

        return self::canManagePlanFolder((int) $target);
    }

    private static function check(?int $projectId, bool $manage): bool
    {
        if ($projectId === null || $projectId <= 0) {
            self::deny(404, 'Project not found');

            return false;
        }

        $user = Flight::get('current_user');
        $service = self::service();
        $allowed = $manage
            ? $service->canManageProject($user, $projectId)
            : $service->canViewProject($user, $projectId);
        if (!$allowed) {
            self::deny(403, $manage
                ? 'Only the project manager can change this'
                : 'You do not have access to this project');
        }

        return $allowed;
    }

    private static function deny(int $code, string $message): void
    {
        Flight::json([
            'error_code' => $code,
            'status' => 'error',
            'message' => $message,
            'data' => null,
        ], $code);
    }

    private static function service(): ProjectAccessService
    {
        return self::$service ??= new ProjectAccessService(Database::getConnection());
    }
}
