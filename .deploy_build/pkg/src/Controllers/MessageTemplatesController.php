<?php

namespace App\Controllers;

use App\Database\Database;
use Monolog\Logger;
use Flight;

/**
 * Контроллер для управления шаблонами сообщений
 */
class MessageTemplatesController
{
    private Database $database;
    private Logger $logger;

    public function __construct(Database $database, Logger $logger)
    {
        $this->database = $database;
        $this->logger = $logger;
    }

    /**
     * Получить все шаблоны сообщений
     * 
     * @OA\Get(
     *     path="/api/v1/admin/message-templates",
     *     summary="Get all message templates",
     *     tags={"Message Templates"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="category",
     *         in="query",
     *         description="Filter by category (system, custom)",
     *         required=false,
     *         @OA\Schema(type="string", enum={"system", "custom"})
     *     ),
     *     @OA\Parameter(
     *         name="type",
     *         in="query",
     *         description="Filter by type (sms, email)",
     *         required=false,
     *         @OA\Schema(type="string", enum={"sms", "email"})
     *     ),
     *     @OA\Parameter(
     *         name="event_type",
     *         in="query",
     *         description="Deprecated. No longer supported.",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Success",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Message templates retrieved successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="templates", type="array", @OA\Items(ref="#/components/schemas/MessageTemplate"))
     *             )
     *         )
     *     )
     * )
     */
    public function getAllTemplates(): void
    {
        $this->logger->info('MessageTemplatesController::getAllTemplates called');
        
        try {
            $connection = $this->database->getConnection();
            
            // Получаем параметры фильтрации
            $category = Flight::request()->query['category'] ?? null;
            $type = Flight::request()->query['type'] ?? null;
            // Deprecated: event_type is no longer part of schema
            $eventType = null;
            
            $whereConditions = [];
            $params = [];
            
            if ($category) {
                $whereConditions[] = "category = ?";
                $params[] = $category;
            }
            
            if ($type) {
                $whereConditions[] = "type = ?";
                $params[] = $type;
            }
            
            // event_type removed from schema — ignore filter if provided
            
            $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';
            
            $result = $connection->executeQuery(
                "SELECT id, name, type, category, subject, body, variables, 
                        COALESCE(is_editable, TRUE) as is_editable, 
                        is_active, created_by, created_at, updated_at 
                 FROM fw_message_templates 
                 {$whereClause}
                 ORDER BY category ASC, type ASC, name ASC",
                $params
            );
            
            $templates = [];
            while ($row = $result->fetchAssociative()) {
                $templates[] = [
                    'id' => (int)$row['id'],
                    'name' => $row['name'],
                    'type' => $row['type'],
                    'category' => $row['category'],
                    // 'event_type' removed
                    'subject' => $row['subject'],
                    'body' => $row['body'],
                    'variables' => $row['variables'] ? json_decode($row['variables'], true) : null,
                    'is_editable' => (bool)$row['is_editable'],
                    'is_active' => (bool)$row['is_active'],
                    'created_by' => $row['created_by'] ? (int)$row['created_by'] : null,
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at']
                ];
            }
            
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Message templates retrieved successfully',
                'data' => [
                    'templates' => $templates
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to get message templates', [
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve message templates',
                'data' => null
            ], 500);
        }
    }

    /**
     * Получить конкретный шаблон
     * 
     * @OA\Get(
     *     path="/api/v1/admin/message-templates/{id}",
     *     summary="Get message template by ID",
     *     tags={"Message Templates"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Template ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Success",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Message template retrieved successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="template", ref="#/components/schemas/MessageTemplate")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Template not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=404),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Message template not found"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     )
     * )
     */
    public function getTemplate(int $id): void
    {
        $this->logger->info('MessageTemplatesController::getTemplate called', ['id' => $id]);
        
        try {
            $connection = $this->database->getConnection();
            
            $result = $connection->executeQuery(
                "SELECT id, name, type, category, subject, body, variables, is_active, created_by, created_at, updated_at 
                 FROM fw_message_templates 
                 WHERE id = ?",
                [$id]
            );
            
            $template = $result->fetchAssociative();
            
            if (!$template) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Message template not found',
                    'data' => null
                ], 404);
                return;
            }
            
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Message template retrieved successfully',
                'data' => [
                    'template' => [
                        'id' => (int)$template['id'],
                        'name' => $template['name'],
                        'type' => $template['type'],
                        'category' => $template['category'],
                        // 'event_type' removed
                        'subject' => $template['subject'],
                        'body' => $template['body'],
                        'variables' => $template['variables'] ? json_decode($template['variables'], true) : null,
                        'is_active' => (bool)$template['is_active'],
                        'created_by' => $template['created_by'] ? (int)$template['created_by'] : null,
                        'created_at' => $template['created_at'],
                        'updated_at' => $template['updated_at']
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to get message template', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve message template',
                'data' => null
            ], 500);
        }
    }

    /**
     * Создать новый шаблон
     * 
     * @OA\Post(
     *     path="/api/v1/admin/message-templates",
     *     summary="Create message template",
     *     tags={"Message Templates"},
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", example="Custom Project Notification"),
     *             @OA\Property(property="type", type="string", enum={"sms", "email"}, example="email"),
     *             @OA\Property(property="category", type="string", enum={"system", "custom"}, example="custom"),
     *             @OA\Property(property="event_type", type="string", example="PROJECT_CREATED"),
     *             @OA\Property(property="subject", type="string", example="New Project: {{project_name}}"),
     *             @OA\Property(property="body", type="string", example="<h2>New Project</h2><p>{{project_name}}</p>"),
     *             @OA\Property(property="variables", type="object", example={"project_name": "Project name"}),
     *             @OA\Property(property="is_active", type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Template created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Message template created successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="template", ref="#/components/schemas/MessageTemplate")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation failed",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=400),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Validation failed"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *             )
     *         )
     *     )
     * )
     */
    public function createTemplate(): void
    {
        $this->logger->info('MessageTemplatesController::createTemplate called');
        
        try {
            $request = Flight::request();
            $data = json_decode($request->getBody(), true);
            
            // Валидация
            $errors = $this->validateTemplateData($data);
            if (!empty($errors)) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'data' => ['errors' => $errors]
                ], 400);
                return;
            }
            
            $connection = $this->database->getConnection();
            
            // Получаем ID текущего пользователя из токена
            $userId = $this->getCurrentUserId();
            
            $connection->executeStatement(
                "INSERT INTO fw_message_templates (name, type, category, subject, body, variables, is_active, created_by) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $data['name'],
                    $data['type'],
                    $data['category'] ?? 'custom',
                    $data['subject'] ?? null,
                    $data['body'],
                    array_key_exists('variables', $data) ? json_encode($data['variables'] ?? null, JSON_UNESCAPED_UNICODE) : null,
                    $data['is_active'] ?? true,
                    $userId
                ]
            );
            
            // Получаем созданный шаблон
            $result = $connection->executeQuery(
                "SELECT id, name, type, category, subject, body, variables, is_active, created_by, created_at, updated_at 
                 FROM fw_message_templates 
                 WHERE id = LAST_INSERT_ID()"
            );
            
            $template = $result->fetchAssociative();
            
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Message template created successfully',
                'data' => [
                    'template' => [
                        'id' => (int)$template['id'],
                        'name' => $template['name'],
                        'type' => $template['type'],
                        'category' => $template['category'],
                        // 'event_type' removed
                        'subject' => $template['subject'],
                        'body' => $template['body'],
                        'variables' => $template['variables'] ? json_decode($template['variables'], true) : null,
                        'is_active' => (bool)$template['is_active'],
                        'created_by' => $template['created_by'] ? (int)$template['created_by'] : null,
                        'created_at' => $template['created_at'],
                        'updated_at' => $template['updated_at']
                    ]
                ]
            ], 201);

        } catch (\Exception $e) {
            $this->logger->error('Failed to create message template', [
                'error' => $e->getMessage(),
                'data' => $data ?? null
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to create message template',
                'data' => null
            ], 500);
        }
    }

    /**
     * Обновить шаблон
     * 
     * @OA\Put(
     *     path="/api/v1/admin/message-templates/{id}",
     *     summary="Update message template",
     *     tags={"Message Templates"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Template ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", example="Updated Template"),
     *             @OA\Property(property="subject", type="string", example="Updated Subject"),
     *             @OA\Property(property="body", type="string", example="Updated body content"),
     *             @OA\Property(property="variables", type="object"),
     *             @OA\Property(property="is_active", type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Template updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Message template updated successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="template", ref="#/components/schemas/MessageTemplate")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Template not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=404),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Message template not found"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     )
     * )
     */
    public function updateTemplate(int $id): void
    {
        $this->logger->info('MessageTemplatesController::updateTemplate called', ['id' => $id]);
        
        try {
            $request = Flight::request();
            $data = json_decode($request->getBody(), true);
            
            // Валидация (не все поля обязательны для обновления)
            $errors = $this->validateTemplateData($data, false);
            if (!empty($errors)) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'data' => ['errors' => $errors]
                ], 400);
                return;
            }
            
            $connection = $this->database->getConnection();
            
            // Проверяем, существует ли шаблон
            $exists = $connection->executeQuery(
                "SELECT COUNT(*) FROM fw_message_templates WHERE id = ?",
                [$id]
            )->fetchOne();
            
            if (!$exists) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Message template not found',
                    'data' => null
                ], 404);
                return;
            }
            
            // Проверяем, можно ли редактировать (системные шаблоны можно редактировать)
            $template = $connection->executeQuery(
                "SELECT category FROM fw_message_templates WHERE id = ?",
                [$id]
            )->fetchAssociative();
            
            // Формируем запрос обновления
            $updateFields = [];
            $params = [];
            
            if (isset($data['name'])) {
                $updateFields[] = "name = ?";
                $params[] = $data['name'];
            }
            
            if (isset($data['subject'])) {
                $updateFields[] = "subject = ?";
                $params[] = $data['subject'];
            }
            
            if (isset($data['body'])) {
                $updateFields[] = "body = ?";
                $params[] = $data['body'];
            }
            
            if (array_key_exists('variables', $data)) {
                $updateFields[] = "variables = ?";
                $params[] = json_encode($data['variables'] ?? null, JSON_UNESCAPED_UNICODE);
            }
            
            if (isset($data['is_active'])) {
                $updateFields[] = "is_active = ?";
                $params[] = $data['is_active'];
            }
            
            if (empty($updateFields)) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'No fields to update',
                    'data' => null
                ], 400);
                return;
            }
            
            $params[] = $id;
            
            $connection->executeStatement(
                "UPDATE fw_message_templates 
                 SET " . implode(', ', $updateFields) . "
                 WHERE id = ?",
                $params
            );
            
            // Получаем обновленный шаблон
            $result = $connection->executeQuery(
                "SELECT id, name, type, category, subject, body, variables, is_active, created_by, created_at, updated_at 
                 FROM fw_message_templates 
                 WHERE id = ?",
                [$id]
            );
            
            $template = $result->fetchAssociative();
            
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Message template updated successfully',
                'data' => [
                    'template' => [
                        'id' => (int)$template['id'],
                        'name' => $template['name'],
                        'type' => $template['type'],
                        'category' => $template['category'],
                        // 'event_type' removed
                        'subject' => $template['subject'],
                        'body' => $template['body'],
                        'variables' => $template['variables'] ? json_decode($template['variables'], true) : null,
                        'is_active' => (bool)$template['is_active'],
                        'created_by' => $template['created_by'] ? (int)$template['created_by'] : null,
                        'created_at' => $template['created_at'],
                        'updated_at' => $template['updated_at']
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to update message template', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to update message template',
                'data' => null
            ], 500);
        }
    }

    /**
     * Удалить шаблон
     * 
     * @OA\Delete(
     *     path="/api/v1/admin/message-templates/{id}",
     *     summary="Delete message template",
     *     tags={"Message Templates"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Template ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Template deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Message template deleted successfully"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Template not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=404),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Message template not found"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     )
     * )
     */
    public function deleteTemplate(int $id): void
    {
        $this->logger->info('MessageTemplatesController::deleteTemplate called', ['id' => $id]);
        
        try {
            $connection = $this->database->getConnection();
            
            // Проверяем, существует ли шаблон
            $exists = $connection->executeQuery(
                "SELECT COUNT(*) FROM fw_message_templates WHERE id = ?",
                [$id]
            )->fetchOne();
            
            if (!$exists) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Message template not found',
                    'data' => null
                ], 404);
                return;
            }
            
            // Проверяем, можно ли удалить (системные шаблоны нельзя удалять)
            $template = $connection->executeQuery(
                "SELECT category FROM fw_message_templates WHERE id = ?",
                [$id]
            )->fetchAssociative();
            
            if ($template['category'] === 'system') {
                Flight::json([
                    'error_code' => 403,
                    'status' => 'error',
                    'message' => 'System templates cannot be deleted',
                    'data' => null
                ], 403);
                return;
            }
            
            $connection->executeStatement(
                "DELETE FROM fw_message_templates WHERE id = ?",
                [$id]
            );
            
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Message template deleted successfully',
                'data' => null
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to delete message template', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to delete message template',
                'data' => null
            ], 500);
        }
    }

    /**
     * Получить шаблоны по типу события
     * 
     * @OA\Get(
     *     path="/api/v1/admin/message-templates/by-event/{event_type}",
     *     summary="Deprecated: Get templates by event type",
     *     tags={"Message Templates"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="event_type",
     *         in="path",
     *         description="Event type",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Success",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Templates retrieved successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="templates", type="array", @OA\Items(ref="#/components/schemas/MessageTemplate"))
     *             )
     *         )
     *     )
     * )
     */
    public function getTemplatesByEvent(string $eventType): void
    {
        $this->logger->info('MessageTemplatesController::getTemplatesByEvent called (deprecated)', ['event_type' => $eventType]);
        Flight::json([
            'error_code' => 410,
            'status' => 'error',
            'message' => 'Endpoint deprecated. Use /api/v1/admin/message-templates?type={sms|email}',
            'data' => null
        ], 410);
    }

    /**
     * Валидирует данные шаблона
     */
    private function validateTemplateData(array $data, bool $isCreate = true): array
    {
        $errors = [];
        
        if ($isCreate) {
            if (empty($data['name'])) {
                $errors[] = 'Template name is required';
            } elseif (strlen($data['name']) > 255) {
                $errors[] = 'Template name must not exceed 255 characters';
            }
            
            if (empty($data['type'])) {
                $errors[] = 'Template type is required';
            } elseif (!in_array($data['type'], ['sms', 'email'])) {
                $errors[] = 'Template type must be either "sms" or "email"';
            }
        }
        
        if (isset($data['name']) && strlen($data['name']) > 255) {
            $errors[] = 'Template name must not exceed 255 characters';
        }
        
        if (isset($data['type']) && !in_array($data['type'], ['sms', 'email'])) {
            $errors[] = 'Template type must be either "sms" or "email"';
        }
        
        if (isset($data['category']) && !in_array($data['category'], ['system', 'custom'])) {
            $errors[] = 'Template category must be either "system" or "custom"';
        }
        
        if (isset($data['subject']) && strlen($data['subject']) > 255) {
            $errors[] = 'Subject must not exceed 255 characters';
        }
        
        if (isset($data['body']) && empty($data['body'])) {
            $errors[] = 'Template body is required';
        }
        
        if (isset($data['variables']) && $data['variables'] !== null && !is_array($data['variables'])) {
            $errors[] = 'Variables must be an array or null';
        }
        
        if (isset($data['is_active']) && !is_bool($data['is_active'])) {
            $errors[] = 'is_active must be a boolean value';
        }
        
        return $errors;
    }

    /**
     * Получает ID текущего пользователя из JWT токена
     */
    private function getCurrentUserId(): ?int
    {
        try {
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
            
            if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
                return null;
            }
            
            $token = substr($authHeader, 7);
            $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], explode('.', $token)[1])), true);
            
            return $payload['user_id'] ?? null;
        } catch (\Exception $e) {
            $this->logger->error('Failed to get current user ID', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
