# Event Rules Execution Location

## Обзор

Добавлено поле `execution_location` типа `VARCHAR(20)` в таблицу `fw_event_rules` для указания места выполнения правил событий.

## Поле execution_location

**Тип:** `VARCHAR(20)`  
**По умолчанию:** `NULL`

### Возможные значения:
- `server` - выполнение на сервере (программа)
- `auto` - выполнение в системе автоматизации (n8n)
- `NULL` - не указано (по умолчанию)

### Логика работы:

1. **`server`** - правило выполняется только в программе на сервере
2. **`auto`** - правило выполняется только в системе автоматизации n8n
3. **`NULL`** - место выполнения не указано (может выполняться везде)

## Использование

### Создание правила с указанием места выполнения:
```json
{
  "event_type": "PROJECT_CREATED",
  "enabled": true,
  "actions": ["notify"],
  "severity": "important",
  "execution_location": "server",
  "conditions": {
    "notify_roles": ["admin", "project_manager"]
  },
  "comment": "Notify about new projects in both program and n8n"
}
```

### Обновление места выполнения:
```json
{
  "execution_location": "auto",
  "actions": ["create_daily_report"]
}
```

## API Endpoints

Все существующие endpoints поддерживают новое поле:

- `POST /api/v1/admin/event-rules` - создание правила
- `PUT /api/v1/admin/event-rules/{event_type}` - обновление правила
- `GET /api/v1/admin/event-rules` - получение всех правил
- `GET /api/v1/admin/event-rules/{event_type}` - получение конкретного правила

## Примеры ответов

### Создание/обновление правила:
```json
{
  "error_code": 0,
  "status": "success",
  "message": "Event rule created successfully",
  "data": {
    "rule": {
      "event_type": "PROJECT_CREATED",
      "enabled": true,
      "actions": ["notify"],
      "severity": "important",
      "conditions": {
        "notify_roles": ["admin", "project_manager"]
      },
      "comment": "Notify about new projects",
      "execution_location": "server",
      "updated_at": "2025-10-26 13:30:00",
      "updated_by": 1
    }
  }
}
```

## Валидация

- Поле `execution_location` необязательное
- По умолчанию устанавливается `NULL`
- Допустимые значения: `server`, `auto`
- Максимальная длина: 20 символов
- При недопустимом значении возвращается ошибка валидации

### Пример ошибки валидации:
```json
{
  "error_code": 400,
  "status": "error",
  "message": "Validation failed",
  "data": {
    "errors": [
      "Execution location must be either \"server\" or \"auto\""
    ]
  }
}
```

## Интеграция с n8n

### CRON на сервере n8n:
- Выбирает события с `execution_location = 'auto'` или `execution_location IS NULL`
- Обрабатывает только свои правила
- Может создавать отчеты, графики, диаграммы для дашборда

### Программа на сервере:
- Выбирает события с `execution_location = 'server'` или `execution_location IS NULL`
- Обрабатывает только свои правила
- Отправляет email уведомления

## Примеры использования

### 1. Только на сервере (email уведомления):
```json
{
  "event_type": "TASK_OVERDUE",
  "execution_location": "server",
  "actions": ["notify"],
  "conditions": {
    "notify_roles": ["project_manager"]
  }
}
```

### 2. Только в системе автоматизации (отчеты и аналитика):
```json
{
  "event_type": "DAILY_SUMMARY",
  "execution_location": "auto",
  "actions": ["create_daily_report"],
  "conditions": {}
}
```

### 3. Везде (email + дашборд):
```json
{
  "event_type": "PROJECT_MILESTONE",
  "execution_location": null,
  "actions": ["notify", "create_report"],
  "conditions": {
    "notify_roles": ["admin"],
    "project_conditions": {
      "min_budget": 100000
    }
  }
}
```

## Миграция

Для обновления существующих правил выполните SQL:

```sql
ALTER TABLE fw_event_rules 
ADD COLUMN execution_location VARCHAR(20) DEFAULT NULL;

-- Обновление существующих записей (по умолчанию NULL)
UPDATE fw_event_rules SET execution_location = NULL WHERE execution_location IS NULL;
```

## Заключение

Поле `execution_location` позволяет гибко управлять местом выполнения правил событий, обеспечивая разделение ответственности между программой и сервером автоматизации n8n.
