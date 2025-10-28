# Event Rules Conditions System

## Обзор

Система условий для правил событий позволяет создавать гибкие правила, которые срабатывают только при определенных обстоятельствах.

## Доступные типы условий

### 1. `user_roles` - Роли пользователей
**Описание:** Разрешенные роли пользователей, которые могут вызвать событие

**Тип:** `array`

**Доступные роли:**
- `admin` - Администратор
- `project_manager` - Менеджер проекта
- `contractor` - Подрядчик
- `architect` - Архитектор

**Пример:**
```json
{
  "user_roles": ["admin", "project_manager"]
}
```

### 2. `exclude_roles` - Исключенные роли
**Описание:** Роли пользователей, которые НЕ должны вызывать событие

**Тип:** `array`

**Пример:**
```json
{
  "exclude_roles": ["contractor"]
}
```

### 3. `time_conditions` - Временные условия
**Описание:** Условия, связанные с временем

**Тип:** `object`

**Свойства:**
- `business_hours_only` (boolean) - Только в рабочее время (9:00-17:00)
- `weekdays_only` (boolean) - Только в будние дни
- `timezone` (string) - Часовой пояс
- `time_range` (object) - Пользовательский временной диапазон
  - `start` (string) - Время начала (например, "09:00")
  - `end` (string) - Время окончания (например, "17:00")

**Примеры:**
```json
{
  "time_conditions": {
    "business_hours_only": true,
    "timezone": "America/New_York"
  }
}
```

```json
{
  "time_conditions": {
    "time_range": {
      "start": "08:00",
      "end": "18:00"
    }
  }
}
```

### 4. `project_conditions` - Условия проекта
**Описание:** Условия, связанные с проектом

**Тип:** `object`

**Свойства:**
- `min_budget` (number) - Минимальный бюджет проекта
- `status` (array) - Разрешенные статусы проекта
- `project_type` (string) - Тип проекта

**Пример:**
```json
{
  "project_conditions": {
    "min_budget": 100000,
    "status": ["active", "planning"]
  }
}
```

### 5. `task_conditions` - Условия задачи
**Описание:** Условия, связанные с задачей

**Тип:** `object`

**Свойства:**
- `status` (array) - Разрешенные статусы задачи
- `min_priority` (number) - Минимальный приоритет
- `is_milestone` (boolean) - Только вехи

**Пример:**
```json
{
  "task_conditions": {
    "status": ["in_progress", "blocked"],
    "min_priority": 3
  }
}
```

### 6. `event_conditions` - Условия события
**Описание:** Условия, связанные с самим событием

**Тип:** `object`

**Свойства:**
- `min_severity` (string) - Минимальная важность события
- `exclude_auto_generated` (boolean) - Исключить автоматически сгенерированные события

**Пример:**
```json
{
  "event_conditions": {
    "min_severity": "important",
    "exclude_auto_generated": true
  }
}
```

## Примеры правил с условиями

### 1. Уведомлять админа только о крупных проектах
```json
{
  "event_type": "PROJECT_CREATED",
  "enabled": true,
  "actions": ["notify_admin"],
  "severity": "important",
  "conditions": {
    "project_conditions": {
      "min_budget": 100000
    }
  },
  "comment": "Notify admin only for large projects"
}
```

### 2. Уведомлять менеджера только в рабочее время
```json
{
  "event_type": "TASK_CREATED",
  "enabled": true,
  "actions": ["notify_manager"],
  "severity": "important",
  "conditions": {
    "time_conditions": {
      "business_hours_only": true,
      "timezone": "America/New_York"
    }
  },
  "comment": "Notify manager only during business hours"
}
```

### 3. Уведомлять только определенные роли
```json
{
  "event_type": "STATUS_CHANGED",
  "enabled": true,
  "actions": ["notify_assignees"],
  "severity": "critical",
  "conditions": {
    "user_roles": ["admin", "project_manager"],
    "exclude_roles": ["contractor"]
  },
  "comment": "Notify only admins and project managers"
}
```

### 4. Комбинированные условия
```json
{
  "event_type": "TASK_DELETED",
  "enabled": true,
  "actions": ["notify_manager", "notify_admin"],
  "severity": "critical",
  "conditions": {
    "user_roles": ["admin", "project_manager"],
    "time_conditions": {
      "business_hours_only": true
    },
    "task_conditions": {
      "min_priority": 2
    }
  },
  "comment": "Notify about high priority task deletions during business hours"
}
```

## Использование в коде

```php
use App\Services\EventConditionsService;

$conditionsService = new EventConditionsService($database, $logger);

// Проверка условий
$conditions = json_decode($rule['conditions'], true);
$eventData = [
    'actor_id' => 1,
    'entity_id' => 123,
    'severity' => 'important',
    'actor_type' => 'user'
];

if ($conditionsService->checkConditions($conditions, $eventData)) {
    // Условия выполнены, выполняем действия
    $this->executeActions($rule['actions'], $eventData);
}
```

## API для получения доступных условий

```php
// Получить список доступных типов условий
$availableConditions = $conditionsService->getAvailableConditions();
```

Этот метод возвращает полную структуру всех доступных условий с описаниями и примерами.
