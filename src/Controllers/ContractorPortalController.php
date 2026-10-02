<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use Flight;
use Monolog\Logger;

/**
 * Read-only contractor portal (session from temporary access key JWT).
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
            $connection = $this->database->getConnection();

            $task = $connection->executeQuery(
                'SELECT t.id, t.project_id, t.name, t.category, t.address, t.start_planned, t.end_planned,
                        t.start_time, t.end_time, t.status, t.progress_pct, t.notes, t.milestone,
                        t.executor_type, t.contractor_id,
                        p.prj_name AS project_name
                 FROM fw_prj_tasks t
                 LEFT JOIN fw_projects p ON p.id = t.project_id
                 WHERE t.id = ? AND t.project_id = ?
                 LIMIT 1',
                [$taskId, $projectId]
            )->fetchAssociative();

            if (!$task) {
                Flight::json(['error_code' => 404, 'status' => 'error', 'message' => 'Task not found', 'data' => null], 404);

                return;
            }

            $contractor = null;
            if (!empty($task['contractor_id'])) {
                $contractor = $connection->executeQuery(
                    'SELECT id, name, company, phone, email, trade FROM fw_contractors WHERE id = ? LIMIT 1',
                    [(int) $task['contractor_id']]
                )->fetchAssociative() ?: null;
            }

            $photos = [];
            try {
                $photos = $connection->executeQuery(
                    'SELECT id, project_id, task_id, work_date, slot, original_name, mime_type, size_bytes, created_at
                     FROM fw_task_field_photos
                     WHERE project_id = ? AND task_id = ?
                     ORDER BY work_date DESC, id DESC',
                    [$projectId, $taskId]
                )->fetchAllAssociative();
            } catch (\Throwable $e) {
                $this->logger->warning('Field photos unavailable for contractor portal', ['error' => $e->getMessage()]);
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Workspace loaded',
                'data' => [
                    'task' => [
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
                    ],
                    'contractor' => $contractor,
                    'field_photos' => array_map(static function (array $p): array {
                        return [
                            'id' => (int) $p['id'],
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
            $connection = $this->database->getConnection();
            $row = $connection->executeQuery(
                'SELECT id, project_id, task_id, storage_path, original_name, mime_type
                 FROM fw_task_field_photos
                 WHERE id = ? AND project_id = ? AND task_id = ?
                 LIMIT 1',
                [$photoId, $projectId, $taskId]
            )->fetchAssociative();

            if (!$row) {
                Flight::json(['error_code' => 404, 'status' => 'error', 'message' => 'Photo not found', 'data' => null], 404);

                return;
            }

            $relative = ltrim((string) ($row['storage_path'] ?? ''), '/');
            $fullPath = dirname(__DIR__, 2) . '/public/' . $relative;
            if (!is_file($fullPath)) {
                // fallback common layout
                $alt = dirname(__DIR__, 2) . '/' . $relative;
                $fullPath = is_file($alt) ? $alt : $fullPath;
            }
            if (!is_file($fullPath)) {
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
}
