<?php

namespace App\Controllers;

use App\Services\EventLoggingService;
use Flight;
use Monolog\Logger;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Event Logs",
 *     description="Event logging and audit trail management endpoints"
 * )
 */
class EventLogController
{
    private Logger $logger;
    private EventLoggingService $eventLoggingService;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->eventLoggingService = new EventLoggingService($logger);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/event-logs",
     *     summary="Get event logs with filtering",
     *     tags={"Event Logs"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="entity_type",
     *         in="query",
     *         description="Filter by entity type",
     *         required=false,
     *         @OA\Schema(type="string", example="task")
     *     ),
     *     @OA\Parameter(
     *         name="entity_id",
     *         in="query",
     *         description="Filter by entity ID",
     *         required=false,
     *         @OA\Schema(type="integer", example=123)
     *     ),
     *     @OA\Parameter(
     *         name="event_type",
     *         in="query",
     *         description="Filter by event type",
     *         required=false,
     *         @OA\Schema(type="string", example="STATUS_CHANGED")
     *     ),
     *     @OA\Parameter(
     *         name="severity",
     *         in="query",
     *         description="Filter by severity",
     *         required=false,
     *         @OA\Schema(type="string", enum={"critical", "important"})
     *     ),
     *     @OA\Parameter(
     *         name="actor_type",
     *         in="query",
     *         description="Filter by actor type",
     *         required=false,
     *         @OA\Schema(type="string", enum={"user", "system", "api"})
     *     ),
     *     @OA\Parameter(
     *         name="date_from",
     *         in="query",
     *         description="Filter from date (YYYY-MM-DD)",
     *         required=false,
     *         @OA\Schema(type="string", format="date", example="2025-01-01")
     *     ),
     *     @OA\Parameter(
     *         name="date_to",
     *         in="query",
     *         description="Filter to date (YYYY-MM-DD)",
     *         required=false,
     *         @OA\Schema(type="string", format="date", example="2025-12-31")
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         description="Number of records to return",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, maximum=100, default=50)
     *     ),
     *     @OA\Parameter(
     *         name="offset",
     *         in="query",
     *         description="Number of records to skip",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=0, default=0)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Event logs retrieved successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Event logs retrieved successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="logs", type="array", @OA\Items(ref="#/components/schemas/EventLog")),
     *                 @OA\Property(property="total", type="integer", example=150),
     *                 @OA\Property(property="limit", type="integer", example=50),
     *                 @OA\Property(property="offset", type="integer", example=0)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - Invalid or missing token"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */
    public function getEventLogs(): void
    {
        try {
            $user = Flight::get('current_user');
            if (!$this->canAccessAudit($user)) {
                Flight::json([
                    'error_code' => 403,
                    'status' => 'error',
                    'message' => 'Forbidden',
                ], 403);
                return;
            }

            $filters = [
                'entity_type' => Flight::request()->query['entity_type'] ?? null,
                'entity_id' => Flight::request()->query['entity_id'] ?? null,
                'event_type' => Flight::request()->query['event_type'] ?? null,
                'severity' => Flight::request()->query['severity'] ?? null,
                'actor_type' => Flight::request()->query['actor_type'] ?? null,
                'date_from' => Flight::request()->query['date_from'] ?? null,
                'date_to' => Flight::request()->query['date_to'] ?? null,
                'project_id' => Flight::request()->query['project_id'] ?? null,
                'q' => Flight::request()->query['q'] ?? null,
            ];

            // Remove empty filters
            $filters = array_filter($filters, function ($value) {
                return $value !== null && $value !== '';
            });

            $limit = min((int) (Flight::request()->query['limit'] ?? 50), 100);
            $offset = max((int) (Flight::request()->query['offset'] ?? 0), 0);

            $result = $this->eventLoggingService->getEventLogs(
                $filters,
                $limit,
                $offset,
                $this->allowedProjectIdsForAudit($user)
            );

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Event logs retrieved successfully',
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get event logs', ['error' => $e->getMessage()]);
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve event logs',
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/event-logs/{id}",
     *     summary="Get specific event log by ID",
     *     tags={"Event Logs"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Event log ID",
     *         required=true,
     *         @OA\Schema(type="integer", example=123)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Event log retrieved successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Event log retrieved successfully"),
     *             @OA\Property(property="data", ref="#/components/schemas/EventLog")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Event log not found"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - Invalid or missing token"
     *     )
     * )
     */
    public function getEventLog(string $id): void
    {
        try {
            $user = Flight::get('current_user');
            if (!$this->canAccessAudit($user)) {
                Flight::json([
                    'error_code' => 403,
                    'status' => 'error',
                    'message' => 'Forbidden',
                ], 403);
                return;
            }

            $log = $this->eventLoggingService->getEventLogById(
                (int) $id,
                $this->allowedProjectIdsForAudit($user)
            );

            if (!$log) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Event log not found',
                ], 404);
                return;
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Event log retrieved successfully',
                'data' => $log,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get event log', ['error' => $e->getMessage(), 'id' => $id]);
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve event log',
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/event-logs/outbox/pending",
     *     summary="Get pending outbox events for processing",
     *     tags={"Event Logs"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         description="Number of events to return",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, maximum=100, default=100)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Pending outbox events retrieved successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Pending outbox events retrieved successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(ref="#/components/schemas/OutboxEvent")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - Invalid or missing token"
     *     )
     * )
     */
    public function getPendingOutboxEvents(): void
    {
        try {
            $limit = min((int)(Flight::request()->query['limit'] ?? 100), 100);
            $events = $this->eventLoggingService->getPendingOutboxEvents($limit);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Pending outbox events retrieved successfully',
                'data' => $events
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to get pending outbox events', ['error' => $e->getMessage()]);
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve pending outbox events'
            ], 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/v1/event-logs/outbox/{id}/status",
     *     summary="Update outbox event status",
     *     tags={"Event Logs"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Outbox event ID",
     *         required=true,
     *         @OA\Schema(type="integer", example=123)
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="status", type="string", enum={"sent", "error"}, example="sent"),
     *             @OA\Property(property="error", type="string", description="Error message if status is 'error'", example="Connection timeout")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Outbox event status updated successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Outbox event status updated successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid request data"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - Invalid or missing token"
     *     )
     * )
     */
    public function updateOutboxEventStatus(string $id): void
    {
        try {
            $request = Flight::request();
            $data = json_decode($request->getBody(), true);

            if (!$data || !isset($data['status'])) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Status is required'
                ], 400);
                return;
            }

            $status = $data['status'];
            if (!in_array($status, ['sent', 'error'])) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Invalid status. Must be "sent" or "error"'
                ], 400);
                return;
            }

            $error = $data['error'] ?? null;
            $success = $this->eventLoggingService->updateOutboxEventStatus($id, $status, $error);

            if (!$success) {
                Flight::json([
                    'error_code' => 500,
                    'status' => 'error',
                    'message' => 'Failed to update outbox event status'
                ], 500);
                return;
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Outbox event status updated successfully'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to update outbox event status', [
                'error' => $e->getMessage(),
                'id' => $id
            ]);
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to update outbox event status'
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/event-logs",
     *     summary="Create a new event log entry",
     *     tags={"Event Logs"},
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="entity_type", type="string", example="task"),
     *             @OA\Property(property="entity_id", type="integer", example=123),
     *             @OA\Property(property="event_type", type="string", example="STATUS_CHANGED"),
     *             @OA\Property(property="before_data", type="object", description="Data before change"),
     *             @OA\Property(property="after_data", type="object", description="Data after change"),
     *             @OA\Property(property="changed_fields", type="array", @OA\Items(type="string"), example={"status", "updated_at"}),
     *             @OA\Property(property="comment", type="string", example="Status changed from pending to completed"),
     *             @OA\Property(property="actor_type", type="string", enum={"user", "system", "api"}, example="user"),
     *             @OA\Property(property="actor_id", type="integer", example=456),
     *             @OA\Property(property="entity_version", type="integer", example=1)
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Event log created successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Event log created successfully"),
     *             @OA\Property(property="data", type="object", @OA\Property(property="event_log_id", type="integer", example=789))
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid request data"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - Invalid or missing token"
     *     )
     * )
     */
    public function createEventLog(): void
    {
        try {
            $request = Flight::request();
            $data = json_decode($request->getBody(), true);

            // Validate required fields
            $requiredFields = ['entity_type', 'entity_id', 'event_type'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    Flight::json([
                        'error_code' => 400,
                        'status' => 'error',
                        'message' => "Field '$field' is required"
                    ], 400);
                    return;
                }
            }

            $eventLogId = $this->eventLoggingService->logEvent(
                $data['entity_type'],
                (int)$data['entity_id'],
                $data['event_type'],
                $data['before_data'] ?? [],
                $data['after_data'] ?? [],
                $data['changed_fields'] ?? [],
                [
                    'comment' => $data['comment'] ?? null,
                    'actor_type' => $data['actor_type'] ?? 'user',
                    'actor_id' => $data['actor_id'] ?? null,
                    'entity_version' => $data['entity_version'] ?? null,
                ]
            );

            if (!$eventLogId) {
                Flight::json([
                    'error_code' => 500,
                    'status' => 'error',
                    'message' => 'Failed to create event log'
                ], 500);
                return;
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Event log created successfully',
                'data' => ['event_log_id' => $eventLogId]
            ], 201);

        } catch (\Exception $e) {
            $this->logger->error('Failed to create event log', ['error' => $e->getMessage()]);
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to create event log'
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/event-rules",
     *     summary="Get event rules configuration",
     *     tags={"Event Logs"},
     *     @OA\Response(
     *         response=200,
     *         description="Event rules retrieved successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\AdditionalProperties(
     *                 type="array",
     *                 @OA\Items(type="string")
     *             ),
     *             example={"user_registered": ["send_welcome_email", "create_profile"], "task_completed": ["notify_manager", "update_project_status"]}
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */
    public function getEventRules(): void
    {
        try {
            $connection = \App\Database\Database::getConnection();
            $result = $connection->executeQuery("SELECT event_type, actions, enabled FROM fw_event_rules");
            $rules = [];
            
            while ($row = $result->fetchAssociative()) {
                if ((int)$row['enabled'] !== 1) continue;
                $rules[$row['event_type']] = json_decode($row['actions'], true);
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Event rules retrieved successfully',
                'data' => $rules
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Error fetching event rules', ['error' => $e->getMessage()]);
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to fetch event rules'
            ], 500);
        }
    }

    /**
     * @param array<string, mixed>|null $user
     */
    private function canAccessAudit(?array $user): bool
    {
        if ($user === null) {
            return false;
        }
        $role = $user['role_code'] ?? null;
        return $role === 'admin' || $role === 'project_manager';
    }

    /**
     * null = all projects (admin); list = PM scoped projects.
     *
     * @param array<string, mixed>|null $user
     * @return list<int>|null
     */
    private function allowedProjectIdsForAudit(?array $user): ?array
    {
        if (($user['role_code'] ?? null) === 'admin') {
            return null;
        }

        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return [];
        }

        try {
            $connection = \App\Database\Database::getConnection();
            $hasForeman = (bool) $connection->fetchOne(
                "SHOW COLUMNS FROM fw_projects LIKE 'project_foreman_id'"
            );
            $sql = 'SELECT id FROM fw_projects WHERE prj_manager = ?';
            $params = [$userId];
            if ($hasForeman) {
                $sql .= ' OR project_foreman_id = ?';
                $params[] = $userId;
            }
            $ids = $connection->fetchFirstColumn($sql, $params);
            return array_map(static fn ($v): int => (int) $v, $ids);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to resolve allowed projects for event log audit', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
