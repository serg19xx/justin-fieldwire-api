<?php

namespace App\Controllers;

use App\Database\Database;
use App\Services\ProjectNotificationService;
use Doctrine\DBAL\Exception;
use Flight;
use Monolog\Logger;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Event Processing",
 *     description="Event processing and notification endpoints"
 * )
 */
class EventProcessingController
{
    private Logger $logger;
    private Database $database;
    private ProjectNotificationService $notificationService;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        
        try {
            $this->database = new Database();
            $this->notificationService = new ProjectNotificationService($this->database, $this->logger);
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize EventProcessingController', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Обрабатывает pending события из outbox
     * 
     * @OA\Post(
     *     path="/api/v1/events/process",
     *     summary="Process pending events",
     *     description="Process all pending events from the outbox queue",
     *     tags={"Event Processing"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         description="Maximum number of events to process",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, maximum=1000, default=100)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Events processed successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Events processed successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="processed_count", type="integer", example=5),
     *                 @OA\Property(property="success_count", type="integer", example=4),
     *                 @OA\Property(property="error_count", type="integer", example=1)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=401),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Unauthorized"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     )
     * )
     */
    public function processEvents(): void
    {
        $this->logger->info('EventProcessingController::processEvents called');
        
        try {
            $request = Flight::request();
            $limit = (int)($request->query['limit'] ?? 100);
            
            // Ограничиваем лимит для безопасности
            $limit = min($limit, 1000);
            
            // Обрабатываем события
            $this->notificationService->processPendingEvents($limit);
            
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Events processed successfully',
                'data' => [
                    'processed_count' => $limit,
                    'limit' => $limit
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to process events', [
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to process events',
                'data' => null
            ], 500);
        }
    }

    /**
     * Генерирует ежедневный отчет по проектам
     * 
     * @OA\Post(
     *     path="/api/v1/reports/daily",
     *     summary="Generate daily project report",
     *     description="Generate and send daily project report",
     *     tags={"Event Processing"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="date",
     *         in="query",
     *         description="Report date (YYYY-MM-DD). Defaults to today",
     *         required=false,
     *         @OA\Schema(type="string", format="date", example="2025-10-26")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Daily report generated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Daily report generated successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="report_date", type="string", format="date", example="2025-10-26"),
     *                 @OA\Property(property="total_projects", type="integer", example=5),
     *                 @OA\Property(property="report_sent", type="boolean", example=true)
     *             )
     *         )
     *     )
     * )
     */
    public function generateDailyReport(): void
    {
        $this->logger->info('EventProcessingController::generateDailyReport called');
        
        try {
            $request = Flight::request();
            $date = $request->query['date'] ?? date('Y-m-d');
            
            // Валидируем дату
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Invalid date format. Use YYYY-MM-DD',
                    'data' => null
                ], 400);
                return;
            }
            
            // Генерируем отчет
            $this->notificationService->generateDailyReport(['date' => $date]);
            
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Daily report generated successfully',
                'data' => [
                    'report_date' => $date,
                    'generated_at' => date('Y-m-d H:i:s')
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to generate daily report', [
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to generate daily report',
                'data' => null
            ], 500);
        }
    }

    /**
     * Получает статистику событий
     * 
     * @OA\Get(
     *     path="/api/v1/events/stats",
     *     summary="Get event statistics",
     *     description="Get statistics about events and notifications",
     *     tags={"Event Processing"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Event statistics retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Event statistics retrieved successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="pending_events", type="integer", example=5),
     *                 @OA\Property(property="total_events_today", type="integer", example=25),
     *                 @OA\Property(property="failed_events", type="integer", example=2),
     *                 @OA\Property(property="last_processed", type="string", format="date-time", example="2025-10-26T10:30:00Z")
     *             )
     *         )
     *     )
     * )
     */
    public function getEventStats(): void
    {
        $this->logger->info('EventProcessingController::getEventStats called');
        
        try {
            $connection = $this->database->getConnection();
            
            // Pending события
            $pendingEvents = $connection->executeQuery(
                "SELECT COUNT(*) FROM fw_event_outbox WHERE status = 'pending'"
            )->fetchOne();
            
            // События за сегодня
            $todayEvents = $connection->executeQuery(
                "SELECT COUNT(*) FROM fw_event_log WHERE DATE(occurred_at) = CURDATE()"
            )->fetchOne();
            
            // Неудачные события
            $failedEvents = $connection->executeQuery(
                "SELECT COUNT(*) FROM fw_event_outbox WHERE status = 'error'"
            )->fetchOne();
            
            // Последнее обработанное событие
            $lastProcessed = $connection->executeQuery(
                "SELECT MAX(updated_at) FROM fw_event_outbox WHERE status = 'sent'"
            )->fetchOne();
            
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Event statistics retrieved successfully',
                'data' => [
                    'pending_events' => (int)$pendingEvents,
                    'total_events_today' => (int)$todayEvents,
                    'failed_events' => (int)$failedEvents,
                    'last_processed' => $lastProcessed
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to get event stats', [
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to get event statistics',
                'data' => null
            ], 500);
        }
    }
}
