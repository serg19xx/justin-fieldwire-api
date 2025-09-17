# Система логирования событий (Event Logging System)

## Обзор

Система логирования событий предназначена для отслеживания всех изменений в базе данных с возможностью настройки автоматических действий на основе типов событий.

## Архитектура

### Компоненты системы

1. **EventLoggingService** - основной сервис для логирования событий
2. **EventLogController** - контроллер для управления логами через API
3. **Таблицы базы данных**:
   - `fw_event_log` - основная таблица логов
   - `fw_event_rules` - правила обработки событий
   - `fw_event_outbox` - очередь событий для обработки

### Таблицы базы данных

#### fw_event_log
Основная таблица для хранения логов событий:
- `id` - уникальный идентификатор
- `occurred_at` - время события
- `entity_type` - тип сущности (task, project, user)
- `entity_id` - ID сущности
- `event_type` - тип события (STATUS_CHANGED, TASK_CREATED, etc.)
- `severity` - важность (critical, important)
- `actor_type` - тип актора (user, system, api)
- `changed_fields` - список измененных полей
- `before_data` - данные до изменения
- `after_data` - данные после изменения

#### fw_event_rules
Правила обработки событий:
- `event_type` - тип события
- `enabled` - включено ли правило
- `actions` - действия для выполнения
- `severity` - важность события

#### fw_event_outbox
Очередь событий для обработки:
- `event_log_id` - ссылка на лог события
- `payload` - данные для обработки
- `status` - статус обработки (pending, sent, error)

## API Эндпоинты

### Получение логов событий
```
GET /api/v1/event-logs
```

Параметры фильтрации:
- `entity_type` - тип сущности
- `entity_id` - ID сущности
- `event_type` - тип события
- `severity` - важность
- `actor_type` - тип актора
- `date_from` - дата начала
- `date_to` - дата окончания
- `limit` - количество записей (по умолчанию 50)
- `offset` - смещение (по умолчанию 0)

### Получение конкретного лога
```
GET /api/v1/event-logs/{id}
```

### Создание нового лога
```
POST /api/v1/event-logs
```

Тело запроса:
```json
{
  "entity_type": "task",
  "entity_id": 123,
  "event_type": "STATUS_CHANGED",
  "before_data": {"status": "pending"},
  "after_data": {"status": "completed"},
  "changed_fields": ["status", "updated_at"],
  "comment": "Status changed by user",
  "actor_type": "user",
  "actor_id": 456
}
```

### Получение pending outbox событий
```
GET /api/v1/event-logs/outbox/pending
```

### Обновление статуса outbox события
```
PUT /api/v1/event-logs/outbox/{id}/status
```

Тело запроса:
```json
{
  "status": "sent",
  "error": "Error message if status is 'error'"
}
```

## Использование в коде

### Базовое использование

```php
use App\Services\EventLoggingService;

$eventLoggingService = new EventLoggingService($logger);

// Логирование изменения статуса задачи
$eventLogId = $eventLoggingService->logEvent(
    entityType: 'task',
    entityId: 123,
    eventType: 'STATUS_CHANGED',
    beforeData: ['status' => 'pending'],
    afterData: ['status' => 'completed'],
    changedFields: ['status', 'updated_at'],
    options: [
        'comment' => 'Task completed by user',
        'actor_type' => 'user',
        'actor_id' => 456
    ]
);
```

### Интеграция в контроллеры

```php
class TaskController
{
    private EventLoggingService $eventLoggingService;
    
    public function updateTaskStatus(int $taskId, string $newStatus): void
    {
        // Получаем текущие данные
        $currentData = $this->getTaskData($taskId);
        
        // Обновляем статус
        $this->updateTask($taskId, ['status' => $newStatus]);
        
        // Логируем изменение
        $this->eventLoggingService->logEvent(
            entityType: 'task',
            entityId: $taskId,
            eventType: 'STATUS_CHANGED',
            beforeData: $currentData,
            afterData: array_merge($currentData, ['status' => $newStatus]),
            changedFields: ['status', 'updated_at'],
            options: [
                'actor_type' => 'user',
                'actor_id' => $this->getCurrentUserId()
            ]
        );
    }
}
```

## Типы событий

### Предопределенные типы событий

- `STATUS_CHANGED` - изменение статуса
- `TASK_CREATED` - создание задачи
- `TASK_DELETED` - удаление задачи
- `TASK_PUBLISHED` - публикация задачи
- `SCHEDULE_CHANGED` - изменение расписания
- `ASSIGNEES_CHANGED` - изменение исполнителей
- `DEPENDENCIES_CHANGED` - изменение зависимостей
- `DEFAULT` - поведение по умолчанию

### Настройка правил событий

Правила событий настраиваются в таблице `fw_event_rules`:

```sql
INSERT INTO fw_event_rules (event_type, enabled, actions, severity, comment) 
VALUES ('CUSTOM_EVENT', 1, '["notify_manager", "send_email"]', 'important', 'Custom event rule');
```

## Обработка outbox событий

### Получение pending событий

```php
$pendingEvents = $eventLoggingService->getPendingOutboxEvents(100);

foreach ($pendingEvents as $event) {
    $payload = $event['payload'];
    $action = $payload['action'];
    
    switch ($action) {
        case 'notify_manager':
            $this->notifyManager($payload);
            break;
        case 'send_email':
            $this->sendEmail($payload);
            break;
    }
    
    // Обновляем статус
    $eventLoggingService->updateOutboxEventStatus(
        $event['id'], 
        'sent'
    );
}
```

### Обработка ошибок

```php
try {
    $this->processEvent($event);
    $eventLoggingService->updateOutboxEventStatus($eventId, 'sent');
} catch (Exception $e) {
    $eventLoggingService->updateOutboxEventStatus(
        $eventId, 
        'error', 
        $e->getMessage()
    );
}
```

## Мониторинг и отладка

### Просмотр логов

```bash
# Получить все логи
curl "http://localhost:8080/api/v1/event-logs"

# Получить логи по типу сущности
curl "http://localhost:8080/api/v1/event-logs?entity_type=task"

# Получить логи по типу события
curl "http://localhost:8080/api/v1/event-logs?event_type=STATUS_CHANGED"

# Получить pending outbox события
curl "http://localhost:8080/api/v1/event-logs/outbox/pending"
```

### Логирование в файлы

Все операции логируются в `logs/app.log` с соответствующими уровнями:
- `INFO` - успешные операции
- `WARNING` - предупреждения
- `ERROR` - ошибки

## Лучшие практики

1. **Всегда логируйте изменения** - каждое изменение в БД должно быть залогировано
2. **Используйте правильные типы событий** - создавайте специфичные типы для разных операций
3. **Обрабатывайте outbox события** - настройте фоновую обработку pending событий
4. **Мониторьте ошибки** - следите за событиями со статусом 'error'
5. **Используйте correlation_id** - для связывания связанных событий
6. **Настройте retention policy** - удаляйте старые логи для экономии места

## Примеры интеграции

См. файл `example-usage.php` для полных примеров использования системы логирования.
