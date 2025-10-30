<?php

namespace App\Controllers;

use App\Database\Database;
use Doctrine\DBAL\Exception;
use Flight;
use Monolog\Logger;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Event Rules Management",
 *     description="Event rules and permissions management endpoints"
 * )
 */
class EventRulesController
{
    private Logger $logger;
    private Database $database;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        
        try {
            $this->database = new Database();
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize EventRulesController', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Получить доступные типы условий
     * 
     * @OA\Get(
     *     path="/api/v1/admin/event-rules/conditions",
     *     summary="Get available condition types",
     *     description="Get list of available condition types for event rules",
     *     tags={"Event Rules Management"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Available conditions retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="conditions", type="object")
     *             )
     *         )
     *     )
     * )
     */
    public function getAvailableConditions(): void
    {
        $this->logger->info('EventRulesController::getAvailableConditions called');
        
        try {
            $conditionsService = new \App\Services\EventConditionsService($this->database, $this->logger);
            $availableConditions = $conditionsService->getAvailableConditions();
            
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Available conditions retrieved successfully',
                'data' => [
                    'conditions' => $availableConditions
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to get available conditions', [
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve available conditions',
                'data' => null
            ], 500);
        }
    }

    /**
     * Получает доступные действия
     */
    public function getAvailableActions(): void
    {
        $this->logger->info('EventRulesController::getAvailableActions called');
        
        try {
            $conditionsService = new \App\Services\EventConditionsService($this->database, $this->logger);
            $availableActions = $conditionsService->getAvailableActions();
            
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Available actions retrieved successfully',
                'data' => [
                    'actions' => $availableActions
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to get available actions', [
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve available actions',
                'data' => null
            ], 500);
        }
    }

    /**
     * Получить все правила событий
     * 
     * @OA\Get(
     *     path="/api/v1/admin/event-rules",
     *     summary="Get all event rules",
     *     description="Retrieve all event rules with their configuration",
     *     tags={"Event Rules Management"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Event rules retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="rules", type="array", @OA\Items(
     *                     @OA\Property(property="event_type", type="string", example="TASK_CREATED"),
     *                     @OA\Property(property="enabled", type="boolean", example=true),
     *                     @OA\Property(property="actions", type="array", @OA\Items(type="string")),
     *                     @OA\Property(property="severity", type="string", enum={"critical", "important"}),
     *                     @OA\Property(property="comment", type="string")
     *                 ))
     *             )
     *         )
     *     )
     * )
     */
    public function getAllRules(): void
    {
        $this->logger->info('EventRulesController::getAllRules called');
        
        try {
            $connection = $this->database->getConnection();
            
            $result = $connection->executeQuery(
                "SELECT event_type, enabled, actions, severity, conditions, comment, execution_location, updated_at, updated_by 
                 FROM fw_event_rules 
                 ORDER BY event_type ASC"
            );
            
            $rules = [];
            while ($row = $result->fetchAssociative()) {
                $rules[] = [
                    'event_type' => $row['event_type'],
                    'enabled' => (bool)$row['enabled'],
                    'actions' => json_decode($row['actions'], true),
                    'severity' => $row['severity'],
                    'conditions' => $row['conditions'] ? json_decode($row['conditions'], true) : null,
                    'comment' => $row['comment'],
                    'execution_location' => $row['execution_location'],
                    'updated_at' => $row['updated_at'],
                    'updated_by' => $row['updated_by'] ? (int)$row['updated_by'] : null
                ];
            }
            
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Event rules retrieved successfully',
                'data' => [
                    'rules' => $rules
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to get event rules', [
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve event rules',
                'data' => null
            ], 500);
        }
    }

    /**
     * Получить конкретное правило события
     * 
     * @OA\Get(
     *     path="/api/v1/admin/event-rules/{event_type}",
     *     summary="Get specific event rule",
     *     description="Retrieve a specific event rule by event type",
     *     tags={"Event Rules Management"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="event_type",
     *         in="path",
     *         description="Event type identifier",
     *         required=true,
     *         @OA\Schema(type="string", example="TASK_CREATED")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Event rule retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     )
     * )
     */
    public function getRule(string $eventType): void
    {
        $this->logger->info('EventRulesController::getRule called', ['event_type' => $eventType]);
        
        try {
            $connection = $this->database->getConnection();
            
            $result = $connection->executeQuery(
                "SELECT event_type, enabled, actions, severity, conditions, comment, execution_location, updated_at, updated_by 
                 FROM fw_event_rules 
                 WHERE event_type = ?",
                [$eventType]
            );
            
            $rule = $result->fetchAssociative();
            
            if (!$rule) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Event rule not found',
                    'data' => null
                ], 404);
                return;
            }
            
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Event rule retrieved successfully',
                'data' => [
                    'rule' => [
                        'event_type' => $rule['event_type'],
                        'enabled' => (bool)$rule['enabled'],
                        'actions' => json_decode($rule['actions'], true),
                        'severity' => $rule['severity'],
                        'conditions' => $rule['conditions'] ? json_decode($rule['conditions'], true) : null,
                        'comment' => $rule['comment'],
                        'execution_location' => $rule['execution_location'],
                        'updated_at' => $rule['updated_at'],
                        'updated_by' => $rule['updated_by'] ? (int)$rule['updated_by'] : null
                    ]
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to get event rule', [
                'event_type' => $eventType,
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve event rule',
                'data' => null
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/admin/event-rules",
     *     summary="Create new event rule",
     *     tags={"Event Rules Management"},
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent()),
     *     @OA\Response(response=201, description="Created")
     * )
     */
    public function createRule(): void
    {
        $this->logger->info('EventRulesController::createRule called');
        
        try {
            $request = Flight::request();
            $data = json_decode($request->getBody(), true);
            
            // Валидация
            $errors = $this->validateRuleDataWithConflicts($data);
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
                "INSERT INTO fw_event_rules (event_type, enabled, actions, severity, conditions, comment, execution_location, updated_by) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $data['event_type'],
                    $data['enabled'] ? 1 : 0,
                    json_encode($data['actions'], JSON_UNESCAPED_UNICODE),
                    $data['severity'],
                    isset($data['conditions']) && $data['conditions'] ? json_encode($data['conditions'], JSON_UNESCAPED_UNICODE) : null,
                    $data['comment'],
                    $data['execution_location'] ?? null,
                    $userId
                ]
            );
            
            // Получаем созданное правило
            $result = $connection->executeQuery(
                "SELECT event_type, enabled, actions, severity, conditions, comment, execution_location, updated_at, updated_by 
                 FROM fw_event_rules 
                 WHERE event_type = ?",
                [$data['event_type']]
            );
            
            $rule = $result->fetchAssociative();
            
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Event rule created successfully',
                'data' => [
                    'rule' => [
                        'event_type' => $rule['event_type'],
                        'enabled' => (bool)$rule['enabled'],
                        'actions' => json_decode($rule['actions'], true),
                        'severity' => $rule['severity'],
                        'conditions' => $rule['conditions'] ? json_decode($rule['conditions'], true) : null,
                        'comment' => $rule['comment'],
                        'execution_location' => $rule['execution_location'],
                        'updated_at' => $rule['updated_at'],
                        'updated_by' => $rule['updated_by'] ? (int)$rule['updated_by'] : null
                    ]
                ]
            ], 201);

        } catch (Exception $e) {
            $this->logger->error('Failed to create event rule', [
                'error' => $e->getMessage(),
                'data' => $data ?? null
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to create event rule',
                'data' => null
            ], 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/v1/admin/event-rules/{event_type}",
     *     summary="Update event rule",
     *     tags={"Event Rules Management"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(name="event_type", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(required=true, @OA\JsonContent()),
     *     @OA\Response(response=200, description="Updated")
     * )
     */
    public function updateRule(string $eventType): void
    {
        $this->logger->info('EventRulesController::updateRule called', ['event_type' => $eventType]);
        
        try {
            $request = Flight::request();
            $data = json_decode($request->getBody(), true) ?? [];

            $connection = $this->database->getConnection();

            // Текущее правило (для частичных обновлений)
            $existingStmt = $connection->executeQuery(
                "SELECT event_type, enabled, actions, severity, conditions, comment, execution_location FROM fw_event_rules WHERE event_type = ?",
                [$eventType]
            );
            $existing = $existingStmt->fetchAssociative();

            if (!$existing) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Event rule not found',
                    'data' => null
                ], 404);
                return;
            }

            // Сборка merged данных: если поле не пришло — берем из существующего
            $merged = [
                'enabled' => array_key_exists('enabled', $data) ? (bool)$data['enabled'] : (bool)$existing['enabled'],
                'actions' => array_key_exists('actions', $data) ? $data['actions'] : json_decode($existing['actions'] ?? '[]', true),
                'severity' => $data['severity'] ?? $existing['severity'],
                'conditions' => array_key_exists('conditions', $data)
                    ? ($data['conditions'] ?? null)
                    : ($existing['conditions'] ? json_decode($existing['conditions'], true) : null),
                'comment' => array_key_exists('comment', $data) ? ($data['comment'] ?? '') : ($existing['comment'] ?? ''),
                'execution_location' => array_key_exists('execution_location', $data) ? ($data['execution_location'] ?? null) : ($existing['execution_location'] ?? null)
            ];

            // Валидация (event_type не обязателен в теле); валидируем merged
            $errors = $this->validateRuleDataWithConflicts($merged, false);
            if (!empty($errors)) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Validation failed',
                    'data' => ['errors' => $errors]
                ], 400);
                return;
            }
            
            // Проверяем, существует ли правило
            $exists = $connection->executeQuery(
                "SELECT COUNT(*) FROM fw_event_rules WHERE event_type = ?",
                [$eventType]
            )->fetchOne();
            
            if (!$exists) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Event rule not found',
                    'data' => null
                ], 404);
                return;
            }
            
            // Получаем ID текущего пользователя из токена
            $userId = $this->getCurrentUserId();
            
            // Если приходит новый event_type — переименовываем с проверками
            $newEventType = $data['event_type'] ?? $eventType;
            if ($newEventType !== $eventType) {
                if (!is_string($newEventType) || !preg_match('/^[A-Z_]+$/', $newEventType)) {
                    Flight::json([
                        'error_code' => 400,
                        'status' => 'error',
                        'message' => 'Validation failed',
                        'data' => ['errors' => ['New event_type must contain only uppercase letters and underscores']]
                    ], 400);
                    return;
                }
                $existsNew = $connection->executeQuery(
                    "SELECT COUNT(*) FROM fw_event_rules WHERE event_type = ?",
                    [$newEventType]
                )->fetchOne();
                if ($existsNew) {
                    Flight::json([
                        'error_code' => 400,
                        'status' => 'error',
                        'message' => 'Validation failed',
                        'data' => ['errors' => ["Event type '{$newEventType}' already exists"]]
                    ], 400);
                    return;
                }
            }

            $connection->executeStatement(
                "UPDATE fw_event_rules 
                 SET event_type = ?, enabled = ?, actions = ?, severity = ?, conditions = ?, comment = ?, execution_location = ?, updated_by = ?
                 WHERE event_type = ?",
                [
                    $newEventType,
                    $merged['enabled'] ? 1 : 0,
                    json_encode($merged['actions'], JSON_UNESCAPED_UNICODE),
                    $merged['severity'],
                    isset($merged['conditions']) && $merged['conditions'] ? json_encode($merged['conditions'], JSON_UNESCAPED_UNICODE) : null,
                    $merged['comment'],
                    $merged['execution_location'] ?? null,
                    $userId,
                    $eventType
                ]
            );
            
            // Получаем обновленное правило
            $result = $connection->executeQuery(
                "SELECT event_type, enabled, actions, severity, conditions, comment, execution_location, updated_at, updated_by 
                 FROM fw_event_rules 
                 WHERE event_type = ?",
                [$newEventType]
            );
            
            $rule = $result->fetchAssociative();
            
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Event rule updated successfully',
                'data' => [
                    'rule' => [
                        'event_type' => $rule['event_type'],
                        'enabled' => (bool)$rule['enabled'],
                        'actions' => json_decode($rule['actions'], true),
                        'severity' => $rule['severity'],
                        'conditions' => $rule['conditions'] ? json_decode($rule['conditions'], true) : null,
                        'comment' => $rule['comment'],
                        'execution_location' => $rule['execution_location'],
                        'updated_at' => $rule['updated_at'],
                        'updated_by' => $rule['updated_by'] ? (int)$rule['updated_by'] : null
                    ]
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to update event rule', [
                'event_type' => $eventType,
                'error' => $e->getMessage(),
                'data' => $data ?? null
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to update event rule',
                'data' => null
            ], 500);
        }
    }

    /**
     * Удалить правило события
     * 
     * @OA\Delete(
     *     path="/api/v1/admin/event-rules/{event_type}",
     *     summary="Delete event rule",
     *     description="Delete an event rule",
     *     tags={"Event Rules Management"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="event_type",
     *         in="path",
     *         description="Event type identifier",
     *         required=true,
     *         @OA\Schema(type="string", example="TASK_CREATED")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Event rule deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     )
     * )
     */
    public function deleteRule(string $eventType): void
    {
        $this->logger->info('EventRulesController::deleteRule called', ['event_type' => $eventType]);
        
        try {
            $connection = $this->database->getConnection();
            
            // Проверяем, существует ли правило
            $exists = $connection->executeQuery(
                "SELECT COUNT(*) FROM fw_event_rules WHERE event_type = ?",
                [$eventType]
            )->fetchOne();
            
            if (!$exists) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Event rule not found',
                    'data' => null
                ], 404);
                return;
            }
            
            $connection->executeStatement(
                "DELETE FROM fw_event_rules WHERE event_type = ?",
                [$eventType]
            );
            
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Event rule deleted successfully',
                'data' => null
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to delete event rule', [
                'event_type' => $eventType,
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to delete event rule',
                'data' => null
            ], 500);
        }
    }

    /**
     * Валидирует данные правила события с проверкой конфликтов
     */
    private function validateRuleDataWithConflicts(array $data, bool $includeEventType = true): array
    {
        $errors = [];
        
        // Базовая валидация
        $errors = array_merge($errors, $this->validateRuleData($data, $includeEventType));
        
        // Проверка конфликтов между полями
        if (isset($data['conditions']) && is_array($data['conditions'])) {
            $conflictErrors = $this->checkFieldConflicts($data);
            $errors = array_merge($errors, $conflictErrors);
        }
        
        return $errors;
    }

    /**
     * Проверяет конфликты между полями правила
     */
    private function checkFieldConflicts(array $data): array
    {
        $errors = [];
        $conditions = $data['conditions'];
        
        // Проверка обязательных условий для действия notify (поддержка новой структуры actions как объектов)
        if (isset($data['actions']) && is_array($data['actions'])) {
            $hasNotifyAction = false;
            foreach ($data['actions'] as $action) {
                if ((is_string($action) && $action === 'notify') || (is_array($action) && ($action['type'] ?? null) === 'notify')) {
                    $hasNotifyAction = true;
                    break;
                }
            }

            if ($hasNotifyAction) {
                $notifyRoles = $conditions['notify_roles']['value'] ?? ($conditions['notify_roles'] ?? null);
                if (!is_array($notifyRoles) || count($notifyRoles) === 0) {
                    $errors[] = "Action 'notify' requires 'notify_roles' condition to specify who to notify";
                }
            }
        }
        
        
        return $errors;
    }

    /**
     * Валидирует данные правила события
     */
    private function validateRuleData(array $data, bool $includeEventType = true): array
    {
        $errors = [];
        
        if ($includeEventType) {
            if (empty($data['event_type'])) {
                $errors[] = 'Event type is required';
            } elseif (!preg_match('/^[A-Z_]+$/', $data['event_type'])) {
                $errors[] = 'Event type must contain only uppercase letters and underscores';
            }
        }
        
        if (!isset($data['enabled'])) {
            $errors[] = 'Enabled status is required';
        } elseif (!is_bool($data['enabled'])) {
            $errors[] = 'Enabled must be a boolean value';
        }
        
        if (empty($data['actions']) || !is_array($data['actions'])) {
            $errors[] = 'Actions must be a non-empty array';
        } else {
            $conditionsService = new \App\Services\EventConditionsService($this->database, $this->logger);
            $availableActions = $conditionsService->getAvailableActions();
            $allowedActions = array_keys($availableActions);
            
            foreach ($data['actions'] as $action) {
                // Поддержка старого формата: массив строк
                if (is_string($action)) {
                    if (!in_array($action, $allowedActions)) {
                        $errors[] = "Action '{$action}' is not allowed. Allowed actions: " . implode(', ', $allowedActions);
                    }
                    continue;
                }

                // Новый формат: объект действия
                if (!is_array($action)) {
                    $errors[] = 'Each action must be a string or an object';
                    continue;
                }

                $type = $action['type'] ?? null;
                if (!$type || !is_string($type)) {
                    $errors[] = 'Action.type is required and must be a string';
                    continue;
                }
                if (!in_array($type, $allowedActions)) {
                    $errors[] = "Action '{$type}' is not allowed. Allowed actions: " . implode(', ', $allowedActions);
                    continue;
                }

                // Специфическая валидация для типа notify
                if ($type === 'notify') {
                    if (empty($action['channels']) || !is_array($action['channels'])) {
                        $errors[] = "Action 'notify' requires 'channels' as a non-empty array";
                    } else {
                        // Валидация channel_templates соответствия email/sms
                        $channelTemplates = $action['channel_templates'] ?? [];
                        if (!is_array($channelTemplates)) {
                            $errors[] = "Action 'notify' field 'channel_templates' must be an object";
                        } else {
                            if (in_array('email', $action['channels'] ?? [], true) && isset($channelTemplates['email']) && !is_numeric($channelTemplates['email'])) {
                                $errors[] = "Action 'notify' channel_templates.email must be a numeric template id";
                            }
                            if (in_array('sms', $action['channels'] ?? [], true) && isset($channelTemplates['sms']) && !is_numeric($channelTemplates['sms'])) {
                                $errors[] = "Action 'notify' channel_templates.sms must be a numeric template id";
                            }
                        }
                    }
                }
            }
        }
        
        if (empty($data['severity'])) {
            $errors[] = 'Severity is required';
        } elseif (!in_array($data['severity'], ['critical', 'important'])) {
            $errors[] = 'Severity must be either "critical" or "important"';
        }
        
        if (isset($data['conditions']) && $data['conditions'] !== null && !is_array($data['conditions'])) {
            $errors[] = 'Conditions must be an array or null';
        }
        
        if (isset($data['comment']) && strlen($data['comment']) > 255) {
            $errors[] = 'Comment must not exceed 255 characters';
        }
        
        if (isset($data['execution_location']) && $data['execution_location'] !== null && !in_array($data['execution_location'], ['server', 'auto'])) {
            $errors[] = 'Execution location must be either "server", "auto", or null';
        }
        
        if (isset($data['execution_location']) && strlen($data['execution_location']) > 20) {
            $errors[] = 'Execution location must not exceed 20 characters';
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
            
            if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
                return null;
            }
            
            $token = substr($authHeader, 7);
            $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], explode('.', $token)[1])), true);
            
            return $payload['user_id'] ?? null;
        } catch (\Exception $e) {
            $this->logger->warning('Failed to extract user ID from token', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}