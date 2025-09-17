<?php

/**
 * Пример использования системы логирования событий
 * 
 * Этот файл демонстрирует, как использовать EventLoggingService
 * для логирования изменений в базе данных.
 */

require_once 'vendor/autoload.php';

use App\Services\EventLoggingService;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Создаем логгер
$logger = new Logger('example');
$logger->pushHandler(new StreamHandler('logs/example.log', Logger::INFO));

// Создаем сервис логирования
$eventLoggingService = new EventLoggingService($logger);

echo "=== Пример использования системы логирования событий ===\n\n";

// Пример 1: Логирование изменения статуса задачи
echo "1. Логирование изменения статуса задачи:\n";
$eventLogId = $eventLoggingService->logEvent(
    entityType: 'task',
    entityId: 123,
    eventType: 'STATUS_CHANGED',
    beforeData: ['status' => 'pending', 'updated_at' => '2025-09-10 10:00:00'],
    afterData: ['status' => 'completed', 'updated_at' => '2025-09-10 10:30:00'],
    changedFields: ['status', 'updated_at'],
    options: [
        'comment' => 'Task completed by user',
        'actor_type' => 'user',
        'actor_id' => 456,
        'entity_version' => 2
    ]
);

if ($eventLogId) {
    echo "   ✅ Event log created with ID: $eventLogId\n";
} else {
    echo "   ❌ Failed to create event log\n";
}

echo "\n";

// Пример 2: Логирование создания новой задачи
echo "2. Логирование создания новой задачи:\n";
$eventLogId = $eventLoggingService->logEvent(
    entityType: 'task',
    entityId: 124,
    eventType: 'TASK_CREATED',
    beforeData: [],
    afterData: [
        'title' => 'New Task',
        'description' => 'Task description',
        'status' => 'pending',
        'created_at' => '2025-09-10 11:00:00'
    ],
    changedFields: ['title', 'description', 'status', 'created_at'],
    options: [
        'comment' => 'New task created via API',
        'actor_type' => 'api',
        'actor_id' => null,
        'entity_version' => 1
    ]
);

if ($eventLogId) {
    echo "   ✅ Event log created with ID: $eventLogId\n";
} else {
    echo "   ❌ Failed to create event log\n";
}

echo "\n";

// Пример 3: Логирование изменения расписания
echo "3. Логирование изменения расписания:\n";
$eventLogId = $eventLoggingService->logEvent(
    entityType: 'task',
    entityId: 125,
    eventType: 'SCHEDULE_CHANGED',
    beforeData: [
        'start_date' => '2025-09-15',
        'end_date' => '2025-09-20'
    ],
    afterData: [
        'start_date' => '2025-09-16',
        'end_date' => '2025-09-22'
    ],
    changedFields: ['start_date', 'end_date'],
    options: [
        'comment' => 'Schedule extended due to dependencies',
        'actor_type' => 'user',
        'actor_id' => 789,
        'entity_version' => 3
    ]
);

if ($eventLogId) {
    echo "   ✅ Event log created with ID: $eventLogId\n";
} else {
    echo "   ❌ Failed to create event log\n";
}

echo "\n";

// Пример 4: Получение pending outbox событий
echo "4. Получение pending outbox событий:\n";
$pendingEvents = $eventLoggingService->getPendingOutboxEvents(10);
echo "   📋 Found " . count($pendingEvents) . " pending events\n";

foreach ($pendingEvents as $event) {
    echo "   - Event ID: {$event['id']}, Type: {$event['event_type']}, Action: {$event['payload']['action']}\n";
}

echo "\n";

// Пример 5: Обновление статуса outbox события
if (!empty($pendingEvents)) {
    $firstEvent = $pendingEvents[0];
    echo "5. Обновление статуса outbox события:\n";
    
    $success = $eventLoggingService->updateOutboxEventStatus(
        $firstEvent['id'],
        'sent'
    );
    
    if ($success) {
        echo "   ✅ Outbox event status updated to 'sent'\n";
    } else {
        echo "   ❌ Failed to update outbox event status\n";
    }
}

echo "\n";

// Пример 6: Получение логов с фильтрацией
echo "6. Получение логов с фильтрацией:\n";
$logs = $eventLoggingService->getEventLogs([
    'entity_type' => 'task',
    'event_type' => 'STATUS_CHANGED'
], 5, 0);

echo "   📊 Found {$logs['total']} matching logs\n";
foreach ($logs['logs'] as $log) {
    echo "   - Log ID: {$log['id']}, Entity: {$log['entity_type']}#{$log['entity_id']}, Event: {$log['event_type']}\n";
}

echo "\n=== Пример завершен ===\n";
