<?php

namespace App\Controllers;

use App\Database\Database;
use Flight;
use Exception;
use Monolog\Logger;

/**
 * Task Templates API (table: fw_task_templates, existing schema).
 * Used on Task Templates page and when creating tasks "From templates".
 * Base path: /api/v1/task-templates
 */
class TaskTemplateController
{
    private Logger $logger;
    private Database $database;

    private const ALLOWED_MILESTONES = ['inspection', 'visit', 'meeting', 'review', 'delivery', 'approval', 'other'];
    private const ALLOWED_STATUSES = [
        'planned', 'scheduled', 'scheduled_accepted', 'in_progress', 'partially_completed',
        'delayed_due_to_issue', 'ready_for_inspection', 'completed'
    ];

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        try {
            $this->database = new Database();
        } catch (Exception $e) {
            $this->logger->error('Failed to initialize TaskTemplateController database', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function getCurrentUserId(): ?int
    {
        $user = Flight::get('current_user');
        return $user && isset($user['id']) ? (int) $user['id'] : null;
    }

    private function getRoleCode(): ?string
    {
        $userId = $this->getCurrentUserId();
        if (!$userId) {
            return null;
        }
        try {
            $conn = $this->database->getConnection();
            $result = $conn->executeQuery(
                'SELECT role_code FROM fw_v_users WHERE id = ? AND archived_at IS NULL',
                [$userId]
            );
            $row = $result->fetchAssociative();
            return $row ? ($row['role_code'] ?? null) : null;
        } catch (Exception $e) {
            $this->logger->error('Failed to get user role', ['user_id' => $userId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Require admin or project_manager. Sends 403 and returns false if not allowed.
     */
    private function requireWriteRole(): bool
    {
        $role = $this->getRoleCode();
        if (!in_array($role, ['admin', 'project_manager'], true)) {
            Flight::json([
                'status' => 'error',
                'message' => 'Access denied',
                'data' => null
            ], 403);
            return false;
        }
        return true;
    }

    private function formatTemplateRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'description' => $row['description'],
            'category' => $row['category'],
            'duration_days' => $row['duration_days'] !== null ? (int) $row['duration_days'] : null,
            'start_offset_days' => $row['start_offset_days'] !== null ? (int) $row['start_offset_days'] : null,
            'end_offset_days' => $row['end_offset_days'] !== null ? (int) $row['end_offset_days'] : null,
            'milestone' => $row['milestone'],
            'status' => $row['status'],
            'notes' => $row['notes'],
            'wbs_path' => $row['wbs_path'],
            'task_order' => $row['task_order'] !== null ? (int) $row['task_order'] : null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    /**
     * Validate template payload for create. Returns array of field => [messages].
     */
    private function validateTemplate(array $template, bool $isCreate): array
    {
        $errors = [];

        if ($isCreate && (!array_key_exists('name', $template) || trim((string)($template['name'] ?? '')) === '')) {
            $errors['name'] = ['Name is required'];
        }

        if (array_key_exists('name', $template)) {
            $name = $template['name'];
            if ($name === '' || $name === null) {
                $errors['name'] = array_merge($errors['name'] ?? [], ['Name is required']);
            } elseif (strlen($name) > 255) {
                $errors['name'] = array_merge($errors['name'] ?? [], ['Name must not exceed 255 characters']);
            }
        }

        if (array_key_exists('category', $template) && $template['category'] !== null && strlen($template['category']) > 100) {
            $errors['category'] = ['Category must not exceed 100 characters'];
        }

        if (array_key_exists('duration_days', $template) && $template['duration_days'] !== null) {
            $v = $template['duration_days'];
            if (!is_numeric($v) || (int) $v < 0) {
                $errors['duration_days'] = ['duration_days must be a non-negative integer'];
            }
        }

        if (array_key_exists('start_offset_days', $template) && $template['start_offset_days'] !== null && !is_numeric($template['start_offset_days'])) {
            $errors['start_offset_days'] = ['start_offset_days must be an integer'];
        }
        if (array_key_exists('end_offset_days', $template) && $template['end_offset_days'] !== null && !is_numeric($template['end_offset_days'])) {
            $errors['end_offset_days'] = ['end_offset_days must be an integer'];
        }

        if (array_key_exists('milestone', $template) && $template['milestone'] !== null && $template['milestone'] !== '') {
            if (!in_array($template['milestone'], self::ALLOWED_MILESTONES, true)) {
                $errors['milestone'] = ['Invalid milestone value'];
            }
        }

        if (array_key_exists('status', $template) && $template['status'] !== null) {
            if (!in_array($template['status'], self::ALLOWED_STATUSES, true)) {
                $errors['status'] = ['Invalid status value'];
            }
        }

        if (array_key_exists('wbs_path', $template) && $template['wbs_path'] !== null && strlen($template['wbs_path']) > 100) {
            $errors['wbs_path'] = ['wbs_path must not exceed 100 characters'];
        }

        if (array_key_exists('task_order', $template) && $template['task_order'] !== null) {
            $v = $template['task_order'];
            if (!is_numeric($v) || (int) $v < 0) {
                $errors['task_order'] = ['task_order must be a non-negative integer'];
            }
        }

        return $errors;
    }

    /**
     * Get request body as array. Supports both Flight parsed body (->data) and raw JSON (getBody()).
     */
    private function getRequestBody(): array
    {
        $request = Flight::request();
        $body = $request->data;
        if (is_array($body)) {
            return $body;
        }
        if (is_object($body)) {
            return json_decode(json_encode($body), true) ?? [];
        }
        $raw = $request->getBody();
        if (!is_string($raw)) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * GET /api/v1/task-templates — List all task templates.
     */
    public function index(): void
    {
        try {
            $conn = $this->database->getConnection();
            $result = $conn->executeQuery(
                "SELECT id, name, description, category, duration_days, start_offset_days, end_offset_days,
                        milestone, status, notes, wbs_path, task_order, created_at, updated_at
                 FROM fw_task_templates
                 ORDER BY task_order ASC, id ASC"
            );
            $rows = $result->fetchAllAssociative();
            $templates = array_map([$this, 'formatTemplateRow'], $rows);

            Flight::json([
                'status' => 'success',
                'message' => 'Task templates retrieved successfully',
                'data' => ['templates' => $templates]
            ]);
        } catch (Exception $e) {
            $this->logger->error('Error listing task templates: ' . $e->getMessage());
            Flight::json([
                'status' => 'error',
                'message' => 'Failed to retrieve task templates',
                'data' => null
            ], 500);
        }
    }

    /**
     * GET /api/v1/task-templates/:id — Get one task template by ID.
     */
    public function get(int $id): void
    {
        try {
            $conn = $this->database->getConnection();
            $result = $conn->executeQuery(
                "SELECT id, name, description, category, duration_days, start_offset_days, end_offset_days,
                        milestone, status, notes, wbs_path, task_order, created_at, updated_at
                 FROM fw_task_templates WHERE id = ?",
                [$id]
            );
            $row = $result->fetchAssociative();
            if (!$row) {
                Flight::json([
                    'status' => 'error',
                    'message' => 'Template not found',
                    'data' => null
                ], 404);
                return;
            }

            Flight::json([
                'status' => 'success',
                'message' => 'Task template retrieved successfully',
                'data' => ['template' => $this->formatTemplateRow($row)]
            ]);
        } catch (Exception $e) {
            $this->logger->error('Error getting task template: ' . $e->getMessage());
            Flight::json([
                'status' => 'error',
                'message' => 'Failed to retrieve task template',
                'data' => null
            ], 500);
        }
    }

    /**
     * POST /api/v1/task-templates — Create task template. Requires admin or project_manager.
     */
    public function create(): void
    {
        if (!$this->requireWriteRole()) {
            return;
        }

        try {
            $body = $this->getRequestBody();
            $template = $body['template'] ?? $body;

            if (empty($template) && empty($body)) {
                Flight::json([
                    'status' => 'error',
                    'message' => 'Request body is empty or invalid JSON',
                    'data' => ['errors' => ['template' => ['Request body must be JSON with a template object']]]
                ], 400);
                return;
            }

            $errors = $this->validateTemplate($template, true);
            if (!empty($errors)) {
                Flight::json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'data' => ['errors' => $errors]
                ], 422);
                return;
            }

            $name = $template['name'];
            $description = $template['description'] ?? null;
            $category = $template['category'] ?? null;
            $durationDays = isset($template['duration_days']) ? (int) $template['duration_days'] : null;
            $startOffsetDays = array_key_exists('start_offset_days', $template) ? ($template['start_offset_days'] === null ? null : (int) $template['start_offset_days']) : null;
            $endOffsetDays = array_key_exists('end_offset_days', $template) ? ($template['end_offset_days'] === null ? null : (int) $template['end_offset_days']) : null;
            $milestone = isset($template['milestone']) && $template['milestone'] !== '' ? $template['milestone'] : null;
            $status = $template['status'] ?? 'planned';
            $notes = $template['notes'] ?? null;
            $wbsPath = $template['wbs_path'] ?? null;
            $taskOrder = isset($template['task_order']) ? (int) $template['task_order'] : null;

            $conn = $this->database->getConnection();
            $conn->executeStatement(
                "INSERT INTO fw_task_templates (name, description, category, duration_days, start_offset_days, end_offset_days, milestone, status, notes, wbs_path, task_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$name, $description, $category, $durationDays, $startOffsetDays, $endOffsetDays, $milestone, $status, $notes, $wbsPath, $taskOrder]
            );
            $id = (int) $conn->lastInsertId();

            $result = $conn->executeQuery(
                "SELECT id, name, description, category, duration_days, start_offset_days, end_offset_days,
                        milestone, status, notes, wbs_path, task_order, created_at, updated_at
                 FROM fw_task_templates WHERE id = ?",
                [$id]
            );
            $row = $result->fetchAssociative();

            Flight::json([
                'status' => 'success',
                'message' => 'Task template created successfully',
                'data' => ['template' => $this->formatTemplateRow($row)]
            ], 201);
        } catch (Exception $e) {
            $this->logger->error('Error creating task template: ' . $e->getMessage());
            Flight::json([
                'status' => 'error',
                'message' => 'Failed to create task template',
                'data' => null
            ], 500);
        }
    }

    /**
     * PUT /api/v1/task-templates/:id — Update task template. Requires admin or project_manager.
     */
    public function update(int $id): void
    {
        if (!$this->requireWriteRole()) {
            return;
        }

        try {
            $conn = $this->database->getConnection();
            $check = $conn->executeQuery('SELECT id FROM fw_task_templates WHERE id = ?', [$id]);
            if (!$check->fetchAssociative()) {
                Flight::json([
                    'status' => 'error',
                    'message' => 'Template not found',
                    'data' => null
                ], 404);
                return;
            }

            $body = $this->getRequestBody();
            $template = $body['template'] ?? $body;

            $errors = $this->validateTemplate($template, false);
            if (!empty($errors)) {
                Flight::json([
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'data' => ['errors' => $errors]
                ], 422);
                return;
            }

            $allowed = ['name', 'description', 'category', 'duration_days', 'start_offset_days', 'end_offset_days', 'milestone', 'status', 'notes', 'wbs_path', 'task_order'];
            $updates = [];
            $params = [];

            foreach ($allowed as $field) {
                if (!array_key_exists($field, $template)) {
                    continue;
                }
                $v = $template[$field];
                if ($field === 'milestone' && ($v === '' || $v === null)) {
                    $updates[] = 'milestone = ?';
                    $params[] = null;
                } elseif (in_array($field, ['start_offset_days', 'end_offset_days'], true)) {
                    $updates[] = "{$field} = ?";
                    $params[] = $v === null ? null : (int) $v;
                } elseif (in_array($field, ['duration_days', 'task_order'], true)) {
                    $updates[] = "{$field} = ?";
                    $params[] = $v === null ? null : (int) $v;
                } else {
                    $updates[] = "{$field} = ?";
                    $params[] = $v;
                }
            }

            if (empty($updates)) {
                $result = $conn->executeQuery(
                    "SELECT id, name, description, category, duration_days, start_offset_days, end_offset_days,
                            milestone, status, notes, wbs_path, task_order, created_at, updated_at
                     FROM fw_task_templates WHERE id = ?",
                    [$id]
                );
                $row = $result->fetchAssociative();
                Flight::json([
                    'status' => 'success',
                    'message' => 'Task template updated successfully',
                    'data' => ['template' => $this->formatTemplateRow($row)]
                ]);
                return;
            }

            $params[] = $id;
            $sql = 'UPDATE fw_task_templates SET ' . implode(', ', $updates) . ' WHERE id = ?';
            $conn->executeStatement($sql, $params);

            $result = $conn->executeQuery(
                "SELECT id, name, description, category, duration_days, start_offset_days, end_offset_days,
                        milestone, status, notes, wbs_path, task_order, created_at, updated_at
                 FROM fw_task_templates WHERE id = ?",
                [$id]
            );
            $row = $result->fetchAssociative();

            Flight::json([
                'status' => 'success',
                'message' => 'Task template updated successfully',
                'data' => ['template' => $this->formatTemplateRow($row)]
            ]);
        } catch (Exception $e) {
            $this->logger->error('Error updating task template: ' . $e->getMessage());
            Flight::json([
                'status' => 'error',
                'message' => 'Failed to update task template',
                'data' => null
            ], 500);
        }
    }

    /**
     * DELETE /api/v1/task-templates/:id — Delete task template. Requires admin or project_manager.
     */
    public function delete(int $id): void
    {
        if (!$this->requireWriteRole()) {
            return;
        }

        try {
            $conn = $this->database->getConnection();
            $check = $conn->executeQuery('SELECT id FROM fw_task_templates WHERE id = ?', [$id]);
            if (!$check->fetchAssociative()) {
                Flight::json([
                    'status' => 'error',
                    'message' => 'Template not found',
                    'data' => null
                ], 404);
                return;
            }

            $conn->executeStatement('DELETE FROM fw_task_templates WHERE id = ?', [$id]);

            Flight::json([
                'status' => 'success',
                'message' => 'Task template deleted successfully'
            ], 200);
        } catch (Exception $e) {
            $this->logger->error('Error deleting task template: ' . $e->getMessage());
            Flight::json([
                'status' => 'error',
                'message' => 'Failed to delete task template',
                'data' => null
            ], 500);
        }
    }
}
