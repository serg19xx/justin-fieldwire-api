# Упрощенная система правил событий

## Обзор

Система правил событий была упрощена для устранения конфликтов и дублирования между различными полями.

## Основные изменения

### 1. Упрощенные действия (Actions)

**Старая система:**
```json
{
  "actions": ["notify_admin", "notify_manager", "notify_assignees"]
}
```

**Новая система:**
```json
{
  "actions": ["notify"]
}
```

**Доступные действия:**
- `notify` - уведомление (требует `notify_roles`)
- `log_only` - только логирование
- `create_daily_report` - создание ежедневного отчета

### 2. Убрано дублирование severity

**Убрано:** `event_conditions.min_severity`
**Оставлено:** только поле `severity` в правиле

### 3. Новое условие `notify_roles`

Специальное условие для указания, кого уведомлять при использовании действия `notify`.

## Структура упрощенного правила

### Базовое правило
```json
{
  "event_type": "PROJECT_CREATED",
  "enabled": true,
  "actions": ["notify"],
  "severity": "important",
  "conditions": {
    "notify_roles": ["admin", "project_manager"]
  },
  "comment": "Notify admins and managers about new projects"
}
```

### Правило с дополнительными условиями
```json
{
  "event_type": "TASK_CREATED",
  "enabled": true,
  "actions": ["notify"],
  "severity": "critical",
  "conditions": {
    "notify_roles": ["project_manager"],
    "user_roles": ["admin", "project_manager"],
    "time_conditions": {
      "business_hours_only": true,
      "timezone": "America/New_York"
    },
    "task_conditions": {
      "min_priority": 3
    }
  },
  "comment": "Notify manager about high priority tasks during business hours"
}
```

## Доступные условия

### 1. `user_roles`
**Описание:** Разрешенные роли пользователей для срабатывания правила
**Тип:** `array`
**Значения:** `["admin", "project_manager", "contractor", "architect"]`

### 2. `exclude_roles`
**Описание:** Исключенные роли пользователей
**Тип:** `array`
**Значения:** `["admin", "project_manager", "contractor", "architect"]`

### 3. `notify_roles` ⭐ НОВОЕ
**Описание:** Роли для уведомления (обязательно для действия `notify`)
**Тип:** `array`
**Значения:** `["admin", "project_manager", "contractor", "architect"]`

### 4. `time_conditions`
**Описание:** Временные условия
**Тип:** `object`
**Свойства:**
- `business_hours_only`: `boolean` - только в рабочее время
- `weekdays_only`: `boolean` - только в будние дни
- `timezone`: `string` - часовой пояс
- `time_range`: `object` - временной диапазон
  - `start`: `string` (например, "09:00")
  - `end`: `string` (например, "17:00")

### 5. `project_conditions`
**Описание:** Условия проекта
**Тип:** `object`
**Свойства:**
- `min_budget`: `number` - минимальный бюджет
- `status`: `array` - разрешенные статусы
- `project_type`: `string` - тип проекта

### 6. `task_conditions`
**Описание:** Условия задачи
**Тип:** `object`
**Свойства:**
- `status`: `array` - разрешенные статусы
- `min_priority`: `number` - минимальный приоритет
- `is_milestone`: `boolean` - только вехи

## Валидация

### Обязательные условия для действия `notify`
```json
{
  "actions": ["notify"],
  "conditions": {
    "notify_roles": ["admin", "project_manager"]  // ОБЯЗАТЕЛЬНО!
  }
}
```

**Ошибка при отсутствии `notify_roles`:**
```json
{
  "error_code": 400,
  "status": "error",
  "message": "Validation failed",
  "data": {
    "errors": [
      "Action 'notify' requires 'notify_roles' condition to specify who to notify"
    ]
  }
}
```

### Конфликт между `notify_roles` и `exclude_roles`
```json
{
  "conditions": {
    "notify_roles": ["admin"],
    "exclude_roles": ["admin"]  // КОНФЛИКТ!
  }
}
```

**Ошибка:**
```json
{
  "error_code": 400,
  "status": "error",
  "message": "Validation failed",
  "data": {
    "errors": [
      "Cannot notify roles that are excluded: admin"
    ]
  }
}
```

### Недопустимые действия
```json
{
  "actions": ["notify_manager"]  // НЕДОПУСТИМО!
}
```

**Ошибка:**
```json
{
  "error_code": 400,
  "status": "error",
  "message": "Validation failed",
  "data": {
    "errors": [
      "Action 'notify_manager' is not allowed. Allowed actions: notify, log_only, create_daily_report"
    ]
  }
}
```

## Примеры использования

### 1. Уведомление админов о критических событиях
```json
{
  "event_type": "PROJECT_DELETED",
  "enabled": true,
  "actions": ["notify"],
  "severity": "critical",
  "conditions": {
    "notify_roles": ["admin"],
    "user_roles": ["admin", "project_manager"]
  },
  "comment": "Notify admins about project deletions"
}
```

### 2. Уведомление менеджеров в рабочее время
```json
{
  "event_type": "TASK_OVERDUE",
  "enabled": true,
  "actions": ["notify"],
  "severity": "important",
  "conditions": {
    "notify_roles": ["project_manager"],
    "time_conditions": {
      "business_hours_only": true,
      "timezone": "America/New_York"
    }
  },
  "comment": "Notify managers about overdue tasks during business hours"
}
```

### 3. Только логирование без уведомлений
```json
{
  "event_type": "USER_LOGIN",
  "enabled": true,
  "actions": ["log_only"],
  "severity": "important",
  "conditions": {
    "user_roles": ["admin", "project_manager", "contractor", "architect"]
  },
  "comment": "Log all user logins"
}
```

### 4. Ежедневный отчет
```json
{
  "event_type": "USER_REGISTRATION_COMPLETED",
  "enabled": true,
  "actions": ["create_daily_report"],
  "severity": "important",
  "conditions": {},
  "comment": "Generate daily report for new user registrations"
}
```

## Миграция со старой системы

### Старое правило:
```json
{
  "event_type": "PROJECT_CREATED",
  "enabled": true,
  "actions": ["notify_admin", "notify_manager"],
  "severity": "important",
  "conditions": {
    "user_roles": ["admin", "project_manager"],
    "event_conditions": {
      "min_severity": "important"
    }
  }
}
```

### Новое правило:
```json
{
  "event_type": "PROJECT_CREATED",
  "enabled": true,
  "actions": ["notify"],
  "severity": "important",
  "conditions": {
    "notify_roles": ["admin", "project_manager"],
    "user_roles": ["admin", "project_manager"]
  }
}
```

## Преимущества упрощенной системы

1. **Устранены конфликты** между `severity` и `event_conditions.min_severity`
2. **Упрощены действия** - один `notify` вместо множества специфичных
3. **Четкое разделение** - действия определяют ЧТО делать, условия - КОГДА и КОГО
4. **Меньше дублирования** - нет повторяющихся полей
5. **Проще понимание** - логичная структура правил

## API Endpoints

### Получить доступные условия
```bash
GET /api/v1/admin/event-rules/conditions
```

### Создать правило
```bash
POST /api/v1/admin/event-rules
```

### Обновить правило
```bash
PUT /api/v1/admin/event-rules/{event_type}
```

### Получить все правила
```bash
GET /api/v1/admin/event-rules
```

### Получить конкретное правило
```bash
GET /api/v1/admin/event-rules/{event_type}
```

### Удалить правило
```bash
DELETE /api/v1/admin/event-rules/{event_type}
```

## Заключение

Упрощенная система правил событий устраняет конфликты и дублирование, делая создание и управление правилами более интуитивным и надежным.
