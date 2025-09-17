# Руководство по интеграции системы логирования в бэкенд

## Почему вызовы из бэкенда лучше?

### ✅ Преимущества бэкенд интеграции:

1. **Надежность** - гарантированное выполнение при изменении данных
2. **Безопасность** - API ключи и секреты остаются на сервере
3. **Контроль** - полный контроль над условиями отправки
4. **Простота** - один вызов в нужном месте кода
5. **Контекст** - автоматическое определение контекста операции

### ❌ Проблемы фронтенд интеграции:

1. **Ненадежность** - пользователь может закрыть браузер
2. **Безопасность** - API ключи видны в коде
3. **Дублирование** - логика в двух местах
4. **Сложность** - нужно передавать контекст с фронтенда

## Как интегрировать в существующие контроллеры

### 1. Добавление сервиса логирования

```php
<?php

use App\Services\EventLoggingService;

class YourController
{
    private EventLoggingService $eventLoggingService;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->eventLoggingService = new EventLoggingService($logger);
    }
}
```

### 2. Базовый паттерн интеграции

```php
public function updateSomething(int $id, array $newData): array
{
    try {
        // 1. Получаем текущие данные
        $currentData = $this->getCurrentData($id);
        
        // 2. Выполняем операцию
        $this->performUpdate($id, $newData);
        
        // 3. Логируем изменение
        $eventLogId = $this->eventLoggingService->logEvent(
            entityType: 'your_entity',
            entityId: $id,
            eventType: 'YOUR_EVENT_TYPE',
            beforeData: $currentData,
            afterData: $newData,
            changedFields: array_keys($newData),
            options: [
                'comment' => 'Description of what happened',
                'actor_type' => 'user',
                'actor_id' => $this->getCurrentUserId()
            ]
        );
        
        return [
            'success' => true,
            'event_log_id' => $eventLogId
        ];
        
    } catch (\Exception $e) {
        $this->logger->error('Operation failed', ['error' => $e->getMessage()]);
        throw $e;
    }
}
```

## Примеры интеграции по типам операций

### Создание сущности

```php
public function createEntity(array $data): array
{
    // 1. Создаем сущность
    $entityId = $this->insertToDatabase($data);
    
    // 2. Логируем создание
    $eventLogId = $this->eventLoggingService->logEvent(
        entityType: 'entity',
        entityId: $entityId,
        eventType: 'ENTITY_CREATED',
        beforeData: [],
        afterData: $data,
        changedFields: array_keys($data),
        options: [
            'comment' => "New entity created: {$data['name']}",
            'actor_type' => 'user',
            'actor_id' => $this->getCurrentUserId(),
            'entity_version' => 1
        ]
    );
    
    return ['entity_id' => $entityId, 'event_log_id' => $eventLogId];
}
```

### Обновление сущности

```php
public function updateEntity(int $id, array $newData): array
{
    // 1. Получаем текущие данные
    $currentData = $this->getEntityById($id);
    
    // 2. Обновляем
    $this->updateInDatabase($id, $newData);
    
    // 3. Логируем изменение
    $eventLogId = $this->eventLoggingService->logEvent(
        entityType: 'entity',
        entityId: $id,
        eventType: 'ENTITY_UPDATED',
        beforeData: $currentData,
        afterData: array_merge($currentData, $newData),
        changedFields: array_keys($newData),
        options: [
            'comment' => 'Entity updated',
            'actor_type' => 'user',
            'actor_id' => $this->getCurrentUserId()
        ]
    );
    
    return ['event_log_id' => $eventLogId];
}
```

### Удаление сущности

```php
public function deleteEntity(int $id): array
{
    // 1. Получаем данные для логирования
    $entityData = $this->getEntityById($id);
    
    // 2. Удаляем
    $this->deleteFromDatabase($id);
    
    // 3. Логируем удаление
    $eventLogId = $this->eventLoggingService->logEvent(
        entityType: 'entity',
        entityId: $id,
        eventType: 'ENTITY_DELETED',
        beforeData: $entityData,
        afterData: [],
        changedFields: ['deleted_at'],
        options: [
            'comment' => "Entity deleted: {$entityData['name']}",
            'actor_type' => 'user',
            'actor_id' => $this->getCurrentUserId(),
            'severity' => 'critical'
        ]
    );
    
    return ['event_log_id' => $eventLogId];
}
```

## Условная логика

### Логирование только при определенных условиях

```php
public function updateWithConditionalLogging(int $id, array $newData): array
{
    $currentData = $this->getEntityById($id);
    $this->updateInDatabase($id, $newData);
    
    // Логируем только значительные изменения
    $eventLogId = null;
    if ($this->isSignificantChange($currentData, $newData)) {
        $eventLogId = $this->eventLoggingService->logEvent(
            entityType: 'entity',
            entityId: $id,
            eventType: 'SIGNIFICANT_CHANGE',
            beforeData: $currentData,
            afterData: $newData,
            changedFields: array_keys($newData),
            options: [
                'comment' => 'Significant change detected',
                'actor_type' => 'user',
                'actor_id' => $this->getCurrentUserId()
            ]
        );
    }
    
    return ['event_log_id' => $eventLogId];
}

private function isSignificantChange(array $current, array $new): bool
{
    // Ваша логика определения значительных изменений
    return abs($new['value'] - $current['value']) > 100;
}
```

### Логирование с разными уровнями важности

```php
public function updateWithSeverity(int $id, array $newData): array
{
    $currentData = $this->getEntityById($id);
    $this->updateInDatabase($id, $newData);
    
    // Определяем важность события
    $severity = $this->determineSeverity($currentData, $newData);
    
    $eventLogId = $this->eventLoggingService->logEvent(
        entityType: 'entity',
        entityId: $id,
        eventType: 'ENTITY_UPDATED',
        beforeData: $currentData,
        afterData: $newData,
        changedFields: array_keys($newData),
        options: [
            'comment' => 'Entity updated',
            'actor_type' => 'user',
            'actor_id' => $this->getCurrentUserId(),
            'severity' => $severity // Переопределяем важность
        ]
    );
    
    return ['event_log_id' => $eventLogId];
}

private function determineSeverity(array $current, array $new): string
{
    // Критическое изменение
    if (isset($new['status']) && $new['status'] === 'cancelled') {
        return 'critical';
    }
    
    // Важное изменение
    if (isset($new['priority']) && $new['priority'] === 'high') {
        return 'important';
    }
    
    // Обычное изменение
    return 'important'; // По умолчанию
}
```

## Интеграция в существующие методы

### Пример: TaskController

```php
// Было:
public function updateTaskStatus(int $taskId, string $newStatus): void
{
    $this->updateTaskInDatabase($taskId, ['status' => $newStatus]);
}

// Стало:
public function updateTaskStatus(int $taskId, string $newStatus): void
{
    // Получаем текущие данные
    $currentTask = $this->getTaskById($taskId);
    
    // Обновляем
    $this->updateTaskInDatabase($taskId, ['status' => $newStatus]);
    
    // Логируем
    $this->eventLoggingService->logEvent(
        entityType: 'task',
        entityId: $taskId,
        eventType: 'STATUS_CHANGED',
        beforeData: ['status' => $currentTask['status']],
        afterData: ['status' => $newStatus],
        changedFields: ['status', 'updated_at'],
        options: [
            'comment' => "Task status changed from {$currentTask['status']} to {$newStatus}",
            'actor_type' => 'user',
            'actor_id' => $this->getCurrentUserId()
        ]
    );
}
```

## Лучшие практики

### 1. Всегда логируйте критические операции

```php
// ✅ Хорошо - логируем критическое изменение
public function changeUserRole(int $userId, string $newRole): void
{
    $oldRole = $this->getUserRole($userId);
    $this->updateUserRole($userId, $newRole);
    
    $this->eventLoggingService->logEvent(
        entityType: 'user',
        entityId: $userId,
        eventType: 'ROLE_CHANGED',
        beforeData: ['role' => $oldRole],
        afterData: ['role' => $newRole],
        changedFields: ['role'],
        options: [
            'comment' => "User role changed from {$oldRole} to {$newRole}",
            'severity' => 'critical'
        ]
    );
}
```

### 2. Используйте описательные комментарии

```php
// ✅ Хорошо - понятный комментарий
'comment' => "Task #{$taskId} status changed from {$oldStatus} to {$newStatus} by user {$userId}"

// ❌ Плохо - неинформативный комментарий
'comment' => "Updated"
```

### 3. Логируйте до и после данные

```php
// ✅ Хорошо - полные данные
beforeData: ['status' => 'pending', 'priority' => 'medium'],
afterData: ['status' => 'completed', 'priority' => 'high'],

// ❌ Плохо - неполные данные
beforeData: ['status' => 'pending'],
afterData: ['status' => 'completed'],
```

### 4. Обрабатывайте ошибки

```php
try {
    // Ваша логика
    $eventLogId = $this->eventLoggingService->logEvent(...);
} catch (\Exception $e) {
    // Логируем ошибку, но не прерываем основную операцию
    $this->logger->error('Failed to log event', [
        'error' => $e->getMessage(),
        'entity_type' => 'task',
        'entity_id' => $taskId
    ]);
}
```

## Типы событий для разных операций

### Задачи (Tasks)
- `TASK_CREATED` - создание задачи
- `TASK_UPDATED` - обновление задачи
- `TASK_DELETED` - удаление задачи
- `STATUS_CHANGED` - изменение статуса
- `ASSIGNEES_CHANGED` - изменение исполнителей
- `SCHEDULE_CHANGED` - изменение расписания
- `PRIORITY_CHANGED` - изменение приоритета

### Проекты (Projects)
- `PROJECT_CREATED` - создание проекта
- `PROJECT_UPDATED` - обновление проекта
- `PROJECT_DELETED` - удаление проекта
- `PROJECT_STATUS_CHANGED` - изменение статуса
- `PROJECT_MEMBER_ADDED` - добавление участника
- `PROJECT_MEMBER_REMOVED` - удаление участника
- `PROJECT_BUDGET_CHANGED` - изменение бюджета

### Пользователи (Users)
- `USER_REGISTERED` - регистрация
- `USER_UPDATED` - обновление профиля
- `USER_DELETED` - удаление пользователя
- `USER_ROLE_CHANGED` - изменение роли
- `USER_STATUS_CHANGED` - изменение статуса
- `USER_PASSWORD_CHANGED` - изменение пароля

## Проверка интеграции

### Тестирование логирования

```php
public function testLoggingIntegration(): void
{
    // Выполняем операцию
    $result = $this->updateTaskStatus(123, 'completed');
    
    // Проверяем, что событие залогировано
    $this->assertNotNull($result['event_log_id']);
    
    // Проверяем outbox события
    $pendingEvents = $this->eventLoggingService->getPendingOutboxEvents();
    $this->assertNotEmpty($pendingEvents);
}
```

### Мониторинг в логах

```bash
# Просмотр логов событий
tail -f logs/app.log | grep -i "event logged"

# Просмотр ошибок логирования
tail -f logs/app.log | grep -i "failed to log"
```

## Заключение

Интеграция системы логирования в бэкенд - это правильный подход, который обеспечивает:

- **Надежность** - все события гарантированно логируются
- **Безопасность** - API ключи остаются на сервере
- **Простота** - один вызов в нужном месте
- **Контроль** - полный контроль над условиями

Следуйте примерам в `examples/backend-integration/` для быстрого старта!
