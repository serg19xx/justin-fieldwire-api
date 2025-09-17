<?php

namespace App\Examples;

use App\Controllers\TaskController;
use App\Services\EventLoggingService;
use Monolog\Logger;

/**
 * Пример интеграции системы логирования в TaskController
 * Показывает, как добавлять вызовы логирования в существующие методы
 */
class TaskControllerExample extends TaskController
{
    private EventLoggingService $eventLoggingService;

    public function __construct(Logger $logger)
    {
        parent::__construct($logger);
        $this->eventLoggingService = new EventLoggingService($logger);
    }

    /**
     * Пример: Обновление статуса задачи
     */
    public function updateTaskStatus(int $taskId, string $newStatus): array
    {
        try {
            // 1. Получаем текущие данные задачи
            $currentTask = $this->getTaskById($taskId);
            if (!$currentTask) {
                throw new \Exception("Task not found");
            }

            $oldStatus = $currentTask['status'];

            // 2. Обновляем статус в базе данных
            $this->updateTaskInDatabase($taskId, ['status' => $newStatus]);

            // 3. Логируем изменение (автоматически создаст outbox события)
            $eventLogId = $this->eventLoggingService->logEvent(
                entityType: 'task',
                entityId: $taskId,
                eventType: 'STATUS_CHANGED',
                beforeData: ['status' => $oldStatus],
                afterData: ['status' => $newStatus],
                changedFields: ['status', 'updated_at'],
                options: [
                    'comment' => "Task status changed from {$oldStatus} to {$newStatus}",
                    'actor_type' => 'user',
                    'actor_id' => $this->getCurrentUserId(),
                    'entity_version' => $currentTask['version'] ?? 1
                ]
            );

            // 4. Дополнительная логика (если нужно)
            if ($newStatus === 'completed') {
                $this->handleTaskCompletion($taskId);
            }

            return [
                'success' => true,
                'task_id' => $taskId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'event_log_id' => $eventLogId
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to update task status', [
                'task_id' => $taskId,
                'new_status' => $newStatus,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Пример: Создание новой задачи
     */
    public function createTask(array $taskData): array
    {
        try {
            // 1. Создаем задачу в базе данных
            $taskId = $this->insertTaskToDatabase($taskData);

            // 2. Логируем создание задачи
            $eventLogId = $this->eventLoggingService->logEvent(
                entityType: 'task',
                entityId: $taskId,
                eventType: 'TASK_CREATED',
                beforeData: [],
                afterData: $taskData,
                changedFields: array_keys($taskData),
                options: [
                    'comment' => "New task created: {$taskData['title']}",
                    'actor_type' => 'user',
                    'actor_id' => $this->getCurrentUserId(),
                    'entity_version' => 1
                ]
            );

            return [
                'success' => true,
                'task_id' => $taskId,
                'event_log_id' => $eventLogId
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to create task', [
                'task_data' => $taskData,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Пример: Назначение исполнителей
     */
    public function assignTask(int $taskId, array $assigneeIds): array
    {
        try {
            // 1. Получаем текущих исполнителей
            $currentAssignees = $this->getTaskAssignees($taskId);

            // 2. Обновляем исполнителей
            $this->updateTaskAssignees($taskId, $assigneeIds);

            // 3. Логируем изменение исполнителей
            $eventLogId = $this->eventLoggingService->logEvent(
                entityType: 'task',
                entityId: $taskId,
                eventType: 'ASSIGNEES_CHANGED',
                beforeData: ['assignees' => $currentAssignees],
                afterData: ['assignees' => $assigneeIds],
                changedFields: ['assignees', 'updated_at'],
                options: [
                    'comment' => "Task assignees changed",
                    'actor_type' => 'user',
                    'actor_id' => $this->getCurrentUserId()
                ]
            );

            return [
                'success' => true,
                'task_id' => $taskId,
                'old_assignees' => $currentAssignees,
                'new_assignees' => $assigneeIds,
                'event_log_id' => $eventLogId
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to assign task', [
                'task_id' => $taskId,
                'assignee_ids' => $assigneeIds,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Пример: Изменение расписания с условиями
     */
    public function updateTaskSchedule(int $taskId, array $scheduleData): array
    {
        try {
            // 1. Получаем текущее расписание
            $currentSchedule = $this->getTaskSchedule($taskId);

            // 2. Проверяем, есть ли значительные изменения
            $significantChange = $this->isSignificantScheduleChange($currentSchedule, $scheduleData);

            // 3. Обновляем расписание
            $this->updateTaskScheduleInDatabase($taskId, $scheduleData);

            // 4. Логируем только значительные изменения
            $eventLogId = null;
            if ($significantChange) {
                $eventLogId = $this->eventLoggingService->logEvent(
                    entityType: 'task',
                    entityId: $taskId,
                    eventType: 'SCHEDULE_CHANGED',
                    beforeData: $currentSchedule,
                    afterData: $scheduleData,
                    changedFields: array_keys($scheduleData),
                    options: [
                        'comment' => "Significant schedule change detected",
                        'actor_type' => 'user',
                        'actor_id' => $this->getCurrentUserId()
                    ]
                );
            }

            return [
                'success' => true,
                'task_id' => $taskId,
                'significant_change' => $significantChange,
                'event_log_id' => $eventLogId
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to update task schedule', [
                'task_id' => $taskId,
                'schedule_data' => $scheduleData,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Пример: Удаление задачи с дополнительными проверками
     */
    public function deleteTask(int $taskId): array
    {
        try {
            // 1. Получаем данные задачи
            $taskData = $this->getTaskById($taskId);
            if (!$taskData) {
                throw new \Exception("Task not found");
            }

            // 2. Проверяем права на удаление
            if (!$this->canDeleteTask($taskId)) {
                throw new \Exception("Insufficient permissions to delete task");
            }

            // 3. Удаляем задачу
            $this->deleteTaskFromDatabase($taskId);

            // 4. Логируем удаление (критическое событие)
            $eventLogId = $this->eventLoggingService->logEvent(
                entityType: 'task',
                entityId: $taskId,
                eventType: 'TASK_DELETED',
                beforeData: $taskData,
                afterData: [],
                changedFields: ['deleted_at'],
                options: [
                    'comment' => "Task deleted: {$taskData['title']}",
                    'actor_type' => 'user',
                    'actor_id' => $this->getCurrentUserId(),
                    'severity' => 'critical' // Переопределяем важность
                ]
            );

            return [
                'success' => true,
                'task_id' => $taskId,
                'deleted_task' => $taskData,
                'event_log_id' => $eventLogId
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to delete task', [
                'task_id' => $taskId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    // Вспомогательные методы (заглушки для примера)
    private function getTaskById(int $taskId): ?array
    {
        // Реальная реализация получения задачи из БД
        return [
            'id' => $taskId,
            'title' => 'Example Task',
            'status' => 'pending',
            'version' => 1
        ];
    }

    private function updateTaskInDatabase(int $taskId, array $data): void
    {
        // Реальная реализация обновления в БД
    }

    private function insertTaskToDatabase(array $data): int
    {
        // Реальная реализация создания в БД
        return 123;
    }

    private function getCurrentUserId(): int
    {
        // Получение ID текущего пользователя
        return 456;
    }

    private function getTaskAssignees(int $taskId): array
    {
        return [1, 2, 3];
    }

    private function updateTaskAssignees(int $taskId, array $assigneeIds): void
    {
        // Реальная реализация обновления исполнителей
    }

    private function getTaskSchedule(int $taskId): array
    {
        return [
            'start_date' => '2025-09-15',
            'end_date' => '2025-09-20'
        ];
    }

    private function isSignificantScheduleChange(array $current, array $new): bool
    {
        // Проверка на значительные изменения (например, изменение более чем на 1 день)
        $currentStart = strtotime($current['start_date']);
        $newStart = strtotime($new['start_date']);
        
        return abs($newStart - $currentStart) > 86400; // 1 день в секундах
    }

    private function updateTaskScheduleInDatabase(int $taskId, array $scheduleData): void
    {
        // Реальная реализация обновления расписания
    }

    private function canDeleteTask(int $taskId): bool
    {
        // Проверка прав на удаление
        return true;
    }

    private function deleteTaskFromDatabase(int $taskId): void
    {
        // Реальная реализация удаления
    }

    private function handleTaskCompletion(int $taskId): void
    {
        // Дополнительная логика при завершении задачи
        $this->logger->info('Task completed', ['task_id' => $taskId]);
    }
}
