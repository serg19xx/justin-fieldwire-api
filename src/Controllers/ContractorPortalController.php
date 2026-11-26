<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use Doctrine\DBAL\Connection;
use Flight;
use Monolog\Logger;

/**
 * Contractor portal (session from temporary access key JWT).
 * One key opens all tasks assigned to that contractor on the project, plus Plans and Calendar.
 */
class ContractorPortalController
{
    private Logger $logger;
    private Database $database;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->database = new Database();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function contractorSession(): ?array
    {
        $user = Flight::get('current_user');
        if (!is_array($user) || ($user['auth_type'] ?? null) !== 'contractor_access') {
            return null;
        }

        return $user;
    }

    private function denyIfNotContractor(): ?array
    {
        $session = $this->contractorSession();
        if ($session === null) {
            Flight::json([
                'error_code' => 403,
                'status' => 'error',
                'message' => 'Contractor access required',
                'data' => null,
            ], 403);

            return null;
        }

        return $session;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadAssignedTasks(Connection $connection, int $projectId, ?int $contractorId, int $fallbackTaskId): array
    {
        if ($contractorId !== null && $contractorId > 0) {
            $rows = $connection->executeQuery(
                'SELECT t.id, t.project_id, t.name, t.category, t.address, t.start_planned, t.end_planned,
                        t.start_time, t.end_time, t.status, t.progress_pct, t.notes, t.milestone,
                        t.executor_type, t.contractor_id,
                        p.prj_name AS project_name
                 FROM fw_prj_tasks t
                 LEFT JOIN fw_projects p ON p.id = t.project_id
                 WHERE t.project_id = ?
                   AND t.executor_type = ?
                   AND t.contractor_id = ?
                 ORDER BY t.start_planned IS NULL ASC, t.start_planned ASC, t.name ASC',
                [$projectId, 'contractor', $contractorId]
            )->fetchAllAssociative();

            if ($rows !== []) {
                return $rows;
            }
        }

        $row = $connection->executeQuery(
            'SELECT t.id, t.project_id, t.name, t.category, t.address, t.start_planned, t.end_planned,
                    t.start_time, t.end_time, t.status, t.progress_pct, t.notes, t.milestone,
                    t.executor_type, t.contractor_id,
                    p.prj_name AS project_name
             FROM fw_prj_tasks t
             LEFT JOIN fw_projects p ON p.id = t.project_id
             WHERE t.id = ? AND t.project_id = ?
             LIMIT 1',
            [$fallbackTaskId, $projectId]
        )->fetchAssociative();

        return $row ? [$row] : [];
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function formatTask(array $task): array
    {
        return [
            'id' => (int) $task['id'],
            'project_id' => (int) $task['project_id'],
            'project_name' => $task['project_name'] ?? null,
            'name' => $task['name'],
            'category' => $task['category'] ?? null,
            'address' => $task['address'] ?? null,
            'start_planned' => $task['start_planned'] ?? null,
            'end_planned' => $task['end_planned'] ?? null,
            'start_time' => $task['start_time'] ?? null,
            'end_time' => $task['end_time'] ?? null,
            'status' => $task['status'] ?? null,
            'progress_pct' => isset($task['progress_pct']) ? (int) $task['progress_pct'] : 0,
            'notes' => $task['notes'] ?? null,
            'milestone' => $task['milestone'] ?? null,
        ];
    }

    /**
     * GET /api/v1/contractor-portal/workspace
     */
    public function workspace(): void
    {
        $session = $this->denyIfNotContractor();
        if ($session === null) {
            return;
        }

        try {
            $projectId = (int) ($session['project_id'] ?? 0);
            $taskId = (int) ($session['task_id'] ?? 0);
            $contractorId = isset($session['contractor_id']) && (int) $session['contractor_id'] > 0
                ? (int) $session['contractor_id']
                : null;
            $connection = $this->database->getConnection();

            $taskRows = $this->loadAssignedTasks($connection, $projectId, $contractorId, $taskId);
            if ($taskRows === []) {
                Flight::json(['error_code' => 404, 'status' => 'error', 'message' => 'Task not found', 'data' => null], 404);

                return;
            }

            $tasks = array_map(fn (array $row): array => $this->formatTask($row), $taskRows);
            $primary = $tasks[0];
            foreach ($tasks as $t) {
                if ((int) $t['id'] === $taskId) {
                    $primary = $t;
                    break;
                }
            }

            $contractor = null;
            $resolvedContractorId = $contractorId;
            if ($resolvedContractorId === null && !empty($taskRows[0]['contractor_id'])) {
                $resolvedContractorId = (int) $taskRows[0]['contractor_id'];
            }
            if ($resolvedContractorId !== null && $resolvedContractorId > 0) {
                $contractor = $connection->executeQuery(
                    'SELECT id, name, company, phone, email, trade FROM fw_contractors WHERE id = ? LIMIT 1',
                    [$resolvedContractorId]
                )->fetchAssociative() ?: null;
            }

            $taskIds = array_map(static fn (array $t): int => (int) $t['id'], $tasks);
            $photos = [];
            try {
                $placeholders = implode(',', array_fill(0, count($taskIds), '?'));
                $params = array_merge([$projectId], $taskIds);
                $photos = $connection->executeQuery(
                    "SELECT id, project_id, task_id, work_date, slot, original_name, mime_type,
                            file_size AS size_bytes, uploaded_at AS created_at
                     FROM fw_task_field_photos
                     WHERE project_id = ? AND task_id IN ($placeholders) AND deleted_at IS NULL
                     ORDER BY work_date DESC, id DESC",
                    $params
                )->fetchAllAssociative();
            } catch (\Throwable $e) {
                $this->logger->warning('Field photos unavailable for contractor portal', ['error' => $e->getMessage()]);
            }

            $plans = [];
            try {
                $plans = $connection->executeQuery(
                    'SELECT f.id, f.original_name, f.file_name, f.mime_type, f.file_size, f.folder_id,
                            fol.name AS folder_name
                     FROM fw_plan_files f
                     INNER JOIN fw_plan_folders fol ON fol.id = f.folder_id
                     WHERE fol.project_id = ?
                     ORDER BY fol.name ASC, f.original_name ASC',
                    [$projectId]
                )->fetchAllAssociative();
            } catch (\Throwable $e) {
                $this->logger->warning('Plans unavailable for contractor portal', ['error' => $e->getMessage()]);
            }

            $projectName = $primary['project_name'] ?? null;

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Workspace loaded',
                'data' => [
                    'project' => [
                        'id' => $projectId,
                        'name' => $projectName,
                    ],
                    'task' => $primary,
                    'tasks' => $tasks,
                    'calendar' => array_map(static function (array $t): array {
                        return [
                            'task_id' => (int) $t['id'],
                            'name' => $t['name'],
                            'status' => $t['status'] ?? null,
                            'start_planned' => $t['start_planned'] ?? null,
                            'end_planned' => $t['end_planned'] ?? null,
                            'start_time' => $t['start_time'] ?? null,
                            'end_time' => $t['end_time'] ?? null,
                            'category' => $t['category'] ?? null,
                        ];
                    }, $tasks),
                    'plans' => array_map(static function (array $p): array {
                        return [
                            'id' => (int) $p['id'],
                            'original_name' => $p['original_name'] ?? null,
                            'mime_type' => $p['mime_type'] ?? null,
                            'file_size' => isset($p['file_size']) ? (int) $p['file_size'] : null,
                            'folder_id' => isset($p['folder_id']) ? (int) $p['folder_id'] : null,
                            'folder_name' => $p['folder_name'] ?? null,
                        ];
                    }, $plans),
                    'contractor' => $contractor,
                    'field_photos' => array_map(static function (array $p): array {
                        return [
                            'id' => (int) $p['id'],
                            'task_id' => isset($p['task_id']) ? (int) $p['task_id'] : null,
                            'work_date' => $p['work_date'] ?? null,
                            'slot' => $p['slot'] ?? null,
                            'original_name' => $p['original_name'] ?? null,
                            'mime_type' => $p['mime_type'] ?? null,
                            'size_bytes' => isset($p['size_bytes']) ? (int) $p['size_bytes'] : null,
                            'created_at' => $p['created_at'] ?? null,
                        ];
                    }, $photos),
                    'key_expires_at' => $session['key_expires_at'] ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Contractor workspace failed', ['error' => $e->getMessage()]);
            Flight::json(['error_code' => 500, 'status' => 'error', 'message' => $e->getMessage(), 'data' => null], 500);
        }
    }

    /**
     * GET /api/v1/contractor-portal/field-photos/{photoId}/download
     */
    public function downloadFieldPhoto(int $photoId): void
    {
        $session = $this->denyIfNotContractor();
        if ($session === null) {
            return;
        }

        try {
            $projectId = (int) ($session['project_id'] ?? 0);
            $taskId = (int) ($session['task_id'] ?? 0);
            $contractorId = isset($session['contractor_id']) && (int) $session['contractor_id'] > 0
                ? (int) $session['contractor_id']
                : null;
            $connection = $this->database->getConnection();

            $allowedTaskIds = array_map(
                static fn (array $t): int => (int) $t['id'],
                $this->loadAssignedTasks($connection, $projectId, $contractorId, $taskId)
            );
            if ($allowedTaskIds === []) {
                Flight::json(['error_code' => 404, 'status' => 'error', 'message' => 'Photo not found', 'data' => null], 404);

                return;
            }

            $placeholders = implode(',', array_fill(0, count($allowedTaskIds), '?'));
            $params = array_merge([$photoId, $projectId], $allowedTaskIds);
            $row = $connection->executeQuery(
                "SELECT id, project_id, task_id, file_name, original_name, mime_type
                 FROM fw_task_field_photos
                 WHERE id = ? AND project_id = ? AND task_id IN ($placeholders) AND deleted_at IS NULL
                 LIMIT 1",
                $params
            )->fetchAssociative();

            if (!$row) {
                Flight::json(['error_code' => 404, 'status' => 'error', 'message' => 'Photo not found', 'data' => null], 404);

                return;
            }

            $relative = ltrim((string) ($row['file_name'] ?? ''), '/');
            $fullPath = dirname(__DIR__, 2) . '/public/' . $relative;
            if ($relative === '' || !is_file($fullPath)) {
                Flight::json(['error_code' => 404, 'status' => 'error', 'message' => 'File missing on disk', 'data' => null], 404);

                return;
            }

            $mime = (string) ($row['mime_type'] ?? 'application/octet-stream');
            $name = (string) ($row['original_name'] ?? ('photo-' . $photoId));
            header('Content-Type: ' . $mime);
            header('Content-Disposition: inline; filename="' . rawurlencode($name) . '"');
            header('Content-Length: ' . (string) filesize($fullPath));
            readfile($fullPath);
            exit;
        } catch (\Throwable $e) {
            $this->logger->error('Contractor photo download failed', ['error' => $e->getMessage()]);
            Flight::json(['error_code' => 500, 'status' => 'error', 'message' => $e->getMessage(), 'data' => null], 500);
        }
    }

    /**
     * GET /api/v1/contractor-portal/plans/{fileId}/download
     */
    public function downloadPlanFile(int $fileId): void
    {
        $session = $this->denyIfNotContractor();
        if ($session === null) {
            return;
        }

        try {
            $projectId = (int) ($session['project_id'] ?? 0);
            $connection = $this->database->getConnection();
            $file = $connection->executeQuery(
                'SELECT f.id, f.file_name, f.original_name, f.file_path, f.file_size, f.mime_type, fol.project_id
                 FROM fw_plan_files f
                 INNER JOIN fw_plan_folders fol ON fol.id = f.folder_id
                 WHERE f.id = ? AND fol.project_id = ?
                 LIMIT 1',
                [$fileId, $projectId]
            )->fetchAssociative();

            if (!$file) {
                Flight::json(['error_code' => 404, 'status' => 'error', 'message' => 'File not found', 'data' => null], 404);

                return;
            }

            $filePath = (string) ($file['file_path'] ?? '');
            $fullPath = dirname(__DIR__, 2) . '/public' . $filePath;
            if ($filePath === '' || !is_file($fullPath)) {
                Flight::json(['error_code' => 404, 'status' => 'error', 'message' => 'File missing on disk', 'data' => null], 404);

                return;
            }

            $mime = (string) ($file['mime_type'] ?? 'application/octet-stream');
            $name = (string) ($file['original_name'] ?? ('plan-' . $fileId));
            $action = $_GET['action'] ?? 'download';
            $isPreview = $action === 'preview';

            header('Content-Type: ' . $mime);
            header('Content-Length: ' . (string) filesize($fullPath));
            if ($isPreview) {
                header('Content-Disposition: inline; filename="' . rawurlencode($name) . '"');
            } else {
                header('Content-Disposition: attachment; filename="' . rawurlencode($name) . '"');
            }
            readfile($fullPath);
            exit;
        } catch (\Throwable $e) {
            $this->logger->error('Contractor plan download failed', ['error' => $e->getMessage()]);
            Flight::json(['error_code' => 500, 'status' => 'error', 'message' => $e->getMessage(), 'data' => null], 500);
        }
    }
}
