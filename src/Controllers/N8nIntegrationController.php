<?php

namespace App\Controllers;

use App\Services\EventLoggingService;
use Flight;
use Monolog\Logger;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="N8N Integration",
 *     description="Endpoints for n8n workflow integration"
 * )
 */
class N8nIntegrationController
{
    private Logger $logger;
    private EventLoggingService $eventLoggingService;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->eventLoggingService = new EventLoggingService($logger);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/n8n/webhook/manual-trigger",
     *     summary="Manual trigger webhook for n8n workflows",
     *     tags={"N8N Integration"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="trigger_type", type="string", example="button_click", description="Type of manual trigger"),
     *             @OA\Property(property="entity_type", type="string", example="task", description="Entity type affected"),
     *             @OA\Property(property="entity_id", type="integer", example=123, description="Entity ID"),
     *             @OA\Property(property="action", type="string", example="status_change", description="Action performed"),
     *             @OA\Property(property="data", type="object", description="Additional data for the trigger"),
     *             @OA\Property(property="user_id", type="integer", example=456, description="User who triggered the action"),
     *             @OA\Property(property="workflow_id", type="string", example="workflow_123", description="N8N workflow ID to trigger")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Webhook processed successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Webhook processed successfully"),
     *             @OA\Property(property="data", type="object", @OA\Property(property="event_log_id", type="integer", example=789))
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid request data"
     *     )
     * )
     */
    public function manualTriggerWebhook(): void
    {
        try {
            $request = Flight::request();
            $data = json_decode($request->getBody(), true);

            // Validate required fields
            $requiredFields = ['trigger_type', 'entity_type', 'entity_id', 'action'];
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

            // Determine event type based on trigger type and action
            $eventType = $this->determineEventType($data['trigger_type'], $data['action']);
            
            // Log the manual trigger event
            $eventLogId = $this->eventLoggingService->logEvent(
                entityType: $data['entity_type'],
                entityId: (int)$data['entity_id'],
                eventType: $eventType,
                beforeData: $data['before_data'] ?? [],
                afterData: $data['after_data'] ?? [],
                changedFields: $data['changed_fields'] ?? [],
                options: [
                    'comment' => "Manual trigger: {$data['trigger_type']} - {$data['action']}",
                    'actor_type' => 'user',
                    'actor_id' => $data['user_id'] ?? null,
                    'correlation_id' => $data['correlation_id'] ?? null,
                ]
            );

            if (!$eventLogId) {
                Flight::json([
                    'error_code' => 500,
                    'status' => 'error',
                    'message' => 'Failed to log manual trigger event'
                ], 500);
                return;
            }

            // Prepare response data for n8n
            $responseData = [
                'event_log_id' => $eventLogId,
                'trigger_type' => $data['trigger_type'],
                'entity_type' => $data['entity_type'],
                'entity_id' => $data['entity_id'],
                'action' => $data['action'],
                'event_type' => $eventType,
                'timestamp' => date('c'),
                'workflow_id' => $data['workflow_id'] ?? null,
                'correlation_id' => $this->eventLoggingService->getCorrelationId($eventLogId)
            ];

            $this->logger->info('Manual trigger webhook processed', [
                'event_log_id' => $eventLogId,
                'trigger_type' => $data['trigger_type'],
                'entity_type' => $data['entity_type'],
                'entity_id' => $data['entity_id']
            ]);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Webhook processed successfully',
                'data' => $responseData
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to process manual trigger webhook', ['error' => $e->getMessage()]);
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to process webhook'
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/n8n/scheduled/data-collection",
     *     summary="Scheduled data collection for n8n workflows",
     *     tags={"N8N Integration"},
     *     @OA\Parameter(
     *         name="report_type",
     *         in="query",
     *         description="Type of report to generate",
     *         required=true,
     *         @OA\Schema(type="string", enum={"daily_summary", "weekly_report", "monthly_report", "task_status", "project_progress", "user_activity"})
     *     ),
     *     @OA\Parameter(
     *         name="date_from",
     *         in="query",
     *         description="Start date for data collection (YYYY-MM-DD)",
     *         required=false,
     *         @OA\Schema(type="string", format="date", example="2025-09-01")
     *     ),
     *     @OA\Parameter(
     *         name="date_to",
     *         in="query",
     *         description="End date for data collection (YYYY-MM-DD)",
     *         required=false,
     *         @OA\Schema(type="string", format="date", example="2025-09-10")
     *     ),
     *     @OA\Parameter(
     *         name="manager_level",
     *         in="query",
     *         description="Manager level for report (team, department, company)",
     *         required=false,
     *         @OA\Schema(type="string", enum={"team", "department", "company"})
     *     ),
     *     @OA\Parameter(
     *         name="format",
     *         in="query",
     *         description="Output format",
     *         required=false,
     *         @OA\Schema(type="string", enum={"json", "csv", "pdf"}, default="json")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Data collected successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Data collected successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="report_type", type="string", example="daily_summary"),
     *                 @OA\Property(property="date_range", type="object"),
     *                 @OA\Property(property="summary", type="object"),
     *                 @OA\Property(property="details", type="array", @OA\Items(type="object")),
     *                 @OA\Property(property="generated_at", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid parameters"
     *     )
     * )
     */
    public function scheduledDataCollection(): void
    {
        try {
            $request = Flight::request();
            $reportType = $request->query['report_type'] ?? null;
            $dateFrom = $request->query['date_from'] ?? date('Y-m-d', strtotime('-1 day'));
            $dateTo = $request->query['date_to'] ?? date('Y-m-d');
            $managerLevel = $request->query['manager_level'] ?? 'team';
            $format = $request->query['format'] ?? 'json';

            if (!$reportType) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'report_type parameter is required'
                ], 400);
                return;
            }

            // Collect data based on report type
            $data = $this->collectDataForReport($reportType, $dateFrom, $dateTo, $managerLevel);

            // Log the scheduled data collection event
            $this->eventLoggingService->logEvent(
                entityType: 'system',
                entityId: 0,
                eventType: 'SCHEDULED_REPORT_GENERATED',
                beforeData: [],
                afterData: $data,
                changedFields: ['report_data'],
                options: [
                    'comment' => "Scheduled report generated: $reportType for $managerLevel level",
                    'actor_type' => 'system',
                    'actor_id' => null,
                ]
            );

            $this->logger->info('Scheduled data collection completed', [
                'report_type' => $reportType,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'manager_level' => $managerLevel
            ]);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Data collected successfully',
                'data' => [
                    'report_type' => $reportType,
                    'date_range' => [
                        'from' => $dateFrom,
                        'to' => $dateTo
                    ],
                    'manager_level' => $managerLevel,
                    'format' => $format,
                    'summary' => $data['summary'] ?? [],
                    'details' => $data['details'] ?? [],
                    'generated_at' => date('c')
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to collect scheduled data', ['error' => $e->getMessage()]);
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to collect data'
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/n8n/workflow/status",
     *     summary="Get workflow execution status",
     *     tags={"N8N Integration"},
     *     @OA\Parameter(
     *         name="correlation_id",
     *         in="query",
     *         description="Correlation ID to track workflow execution",
     *         required=false,
     *         @OA\Schema(type="string", example="550e8400-e29b-41d4-a716-446655440000")
     *     ),
     *     @OA\Parameter(
     *         name="workflow_id",
     *         in="query",
     *         description="N8N workflow ID",
     *         required=false,
     *         @OA\Schema(type="string", example="workflow_123")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Workflow status retrieved successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Workflow status retrieved successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="workflow_id", type="string"),
     *                 @OA\Property(property="correlation_id", type="string"),
     *                 @OA\Property(property="status", type="string", enum={"pending", "running", "completed", "failed"}),
     *                 @OA\Property(property="events", type="array", @OA\Items(type="object"))
     *             )
     *         )
     *     )
     * )
     */
    public function getWorkflowStatus(): void
    {
        try {
            $request = Flight::request();
            $correlationId = $request->query['correlation_id'] ?? null;
            $workflowId = $request->query['workflow_id'] ?? null;

            if (!$correlationId && !$workflowId) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Either correlation_id or workflow_id is required'
                ], 400);
                return;
            }

            // Get events related to the workflow
            $filters = [];
            if ($correlationId) {
                $filters['correlation_id'] = $correlationId;
            }

            $events = $this->eventLoggingService->getEventLogs($filters, 100, 0);

            // Determine workflow status based on events
            $status = $this->determineWorkflowStatus($events['logs']);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Workflow status retrieved successfully',
                'data' => [
                    'workflow_id' => $workflowId,
                    'correlation_id' => $correlationId,
                    'status' => $status,
                    'events' => $events['logs'],
                    'total_events' => $events['total']
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to get workflow status', ['error' => $e->getMessage()]);
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to get workflow status'
            ], 500);
        }
    }

    /**
     * Determine event type based on trigger type and action
     */
    private function determineEventType(string $triggerType, string $action): string
    {
        $mapping = [
            'button_click' => [
                'status_change' => 'STATUS_CHANGED',
                'assign_task' => 'ASSIGNEES_CHANGED',
                'schedule_change' => 'SCHEDULE_CHANGED',
                'create_task' => 'TASK_CREATED',
                'delete_task' => 'TASK_DELETED',
                'publish_task' => 'TASK_PUBLISHED',
            ],
            'time_range_change' => [
                'schedule_update' => 'SCHEDULE_CHANGED',
                'deadline_change' => 'SCHEDULE_CHANGED',
            ],
            'manual_trigger' => [
                'report_generation' => 'REPORT_GENERATED',
                'notification_send' => 'NOTIFICATION_SENT',
                'data_export' => 'DATA_EXPORTED',
            ]
        ];

        return $mapping[$triggerType][$action] ?? 'DEFAULT';
    }

    /**
     * Collect data for scheduled reports
     */
    private function collectDataForReport(string $reportType, string $dateFrom, string $dateTo, string $managerLevel): array
    {
        $connection = \App\Database\Database::getConnection();
        
        switch ($reportType) {
            case 'daily_summary':
                return $this->getDailySummary($connection, $dateFrom, $dateTo, $managerLevel);
            
            case 'weekly_report':
                return $this->getWeeklyReport($connection, $dateFrom, $dateTo, $managerLevel);
            
            case 'monthly_report':
                return $this->getMonthlyReport($connection, $dateFrom, $dateTo, $managerLevel);
            
            case 'task_status':
                return $this->getTaskStatusReport($connection, $dateFrom, $dateTo, $managerLevel);
            
            case 'project_progress':
                return $this->getProjectProgressReport($connection, $dateFrom, $dateTo, $managerLevel);
            
            case 'user_activity':
                return $this->getUserActivityReport($connection, $dateFrom, $dateTo, $managerLevel);
            
            default:
                return ['error' => 'Unknown report type'];
        }
    }

    /**
     * Get daily summary data
     */
    private function getDailySummary($connection, string $dateFrom, string $dateTo, string $managerLevel): array
    {
        // Get event logs for the date range
        $result = $connection->executeQuery(
            "SELECT 
                event_type,
                COUNT(*) as count,
                severity,
                DATE(occurred_at) as date
             FROM fw_event_log 
             WHERE occurred_at BETWEEN ? AND ? 
             GROUP BY event_type, DATE(occurred_at), severity
             ORDER BY date DESC, count DESC",
            [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']
        );

        $summary = [];
        while ($row = $result->fetchAssociative()) {
            $summary[] = $row;
        }

        return [
            'summary' => [
                'total_events' => array_sum(array_column($summary, 'count')),
                'date_range' => ['from' => $dateFrom, 'to' => $dateTo],
                'manager_level' => $managerLevel
            ],
            'details' => $summary
        ];
    }

    /**
     * Get weekly report data
     */
    private function getWeeklyReport($connection, string $dateFrom, string $dateTo, string $managerLevel): array
    {
        // Similar to daily but with weekly aggregation
        return $this->getDailySummary($connection, $dateFrom, $dateTo, $managerLevel);
    }

    /**
     * Get monthly report data
     */
    private function getMonthlyReport($connection, string $dateFrom, string $dateTo, string $managerLevel): array
    {
        // Similar to daily but with monthly aggregation
        return $this->getDailySummary($connection, $dateFrom, $dateTo, $managerLevel);
    }

    /**
     * Get task status report
     */
    private function getTaskStatusReport($connection, string $dateFrom, string $dateTo, string $managerLevel): array
    {
        // Get task-related events
        $result = $connection->executeQuery(
            "SELECT 
                entity_id,
                event_type,
                before_data,
                after_data,
                occurred_at,
                actor_id
             FROM fw_event_log 
             WHERE entity_type = 'task' 
             AND occurred_at BETWEEN ? AND ?
             ORDER BY occurred_at DESC",
            [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']
        );

        $tasks = [];
        while ($row = $result->fetchAssociative()) {
            $row['before_data'] = $row['before_data'] ? json_decode($row['before_data'], true) : null;
            $row['after_data'] = $row['after_data'] ? json_decode($row['after_data'], true) : null;
            $tasks[] = $row;
        }

        return [
            'summary' => [
                'total_task_events' => count($tasks),
                'date_range' => ['from' => $dateFrom, 'to' => $dateTo],
                'manager_level' => $managerLevel
            ],
            'details' => $tasks
        ];
    }

    /**
     * Get project progress report
     */
    private function getProjectProgressReport($connection, string $dateFrom, string $dateTo, string $managerLevel): array
    {
        // Get project-related events
        $result = $connection->executeQuery(
            "SELECT 
                entity_id,
                event_type,
                before_data,
                after_data,
                occurred_at,
                actor_id
             FROM fw_event_log 
             WHERE entity_type = 'project' 
             AND occurred_at BETWEEN ? AND ?
             ORDER BY occurred_at DESC",
            [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']
        );

        $projects = [];
        while ($row = $result->fetchAssociative()) {
            $row['before_data'] = $row['before_data'] ? json_decode($row['before_data'], true) : null;
            $row['after_data'] = $row['after_data'] ? json_decode($row['after_data'], true) : null;
            $projects[] = $row;
        }

        return [
            'summary' => [
                'total_project_events' => count($projects),
                'date_range' => ['from' => $dateFrom, 'to' => $dateTo],
                'manager_level' => $managerLevel
            ],
            'details' => $projects
        ];
    }

    /**
     * Get user activity report
     */
    private function getUserActivityReport($connection, string $dateFrom, string $dateTo, string $managerLevel): array
    {
        // Get user activity events
        $result = $connection->executeQuery(
            "SELECT 
                actor_id,
                actor_type,
                event_type,
                COUNT(*) as activity_count,
                DATE(occurred_at) as date
             FROM fw_event_log 
             WHERE actor_type = 'user' 
             AND occurred_at BETWEEN ? AND ?
             GROUP BY actor_id, DATE(occurred_at), event_type
             ORDER BY date DESC, activity_count DESC",
            [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']
        );

        $activities = [];
        while ($row = $result->fetchAssociative()) {
            $activities[] = $row;
        }

        return [
            'summary' => [
                'total_user_activities' => array_sum(array_column($activities, 'activity_count')),
                'date_range' => ['from' => $dateFrom, 'to' => $dateTo],
                'manager_level' => $managerLevel
            ],
            'details' => $activities
        ];
    }

    /**
     * Determine workflow status based on events
     */
    private function determineWorkflowStatus(array $events): string
    {
        if (empty($events)) {
            return 'pending';
        }

        // Check for completion or error events
        foreach ($events as $event) {
            if ($event['event_type'] === 'WORKFLOW_COMPLETED') {
                return 'completed';
            }
            if ($event['event_type'] === 'WORKFLOW_FAILED') {
                return 'failed';
            }
        }

        // Check for recent activity
        $latestEvent = $events[0];
        $latestTime = strtotime($latestEvent['occurred_at']);
        $now = time();
        
        if (($now - $latestTime) < 300) { // 5 minutes
            return 'running';
        }

        return 'pending';
    }
}
