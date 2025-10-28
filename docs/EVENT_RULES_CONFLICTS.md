# Event Rules Conflict Resolution

## Обзор

Система правил событий включает валидацию конфликтов между различными полями правил для предотвращения логических противоречий.

## Типы конфликтов

### 1. Конфликт `severity` и `event_conditions.min_severity`

**Проблема:** Важность правила ниже требуемого минимума.

**Пример конфликта:**
```json
{
  "severity": "important",
  "conditions": {
    "event_conditions": {
      "min_severity": "critical"  // Конфликт!
    }
  }
}
```

**Ошибка валидации:**
```
Rule severity 'important' is lower than required minimum 'critical'
```

**Решение:** Повысить `severity` до `critical` или понизить `min_severity` до `important`.

### 2. Конфликт `actions` и `user_roles`

**Проблема:** Действие требует роли, которая не разрешена в условиях.

**Пример конфликта:**
```json
{
  "actions": ["notify_admin"],
  "conditions": {
    "user_roles": ["contractor"]  // Конфликт!
  }
}
```

**Ошибка валидации:**
```
Action 'notify_admin' requires 'admin' role, but only contractor are allowed
```

**Решение:** Добавить `admin` в `user_roles` или изменить действие.

### 3. Конфликт `actions` и `exclude_roles`

**Проблема:** Действие конфликтует с исключенными ролями.

**Пример конфликта:**
```json
{
  "actions": ["notify_assignees"],
  "conditions": {
    "exclude_roles": ["contractor", "architect"]  // Конфликт!
  }
}
```

**Ошибка валидации:**
```
Action 'notify_assignees' conflicts with excluded roles: contractor, architect
```

**Решение:** Убрать `contractor` и `architect` из `exclude_roles` или изменить действие.

## Маппинг действий и ролей

### Действие `notify_admin`
- **Требует роль:** `admin`
- **Конфликт с:** `exclude_roles: ["admin"]`

### Действие `notify_manager`
- **Требует роль:** `project_manager`
- **Конфликт с:** `exclude_roles: ["project_manager"]`

### Действие `notify_assignees`
- **Требует роли:** `contractor` или `architect`
- **Конфликт с:** `exclude_roles: ["contractor", "architect"]`

## Примеры валидных правил

### 1. Правило без конфликтов
```json
{
  "event_type": "PROJECT_CREATED",
  "enabled": true,
  "actions": ["notify_manager", "notify_admin"],
  "severity": "critical",
  "conditions": {
    "user_roles": ["admin", "project_manager"],
    "time_conditions": {
      "business_hours_only": true
    },
    "event_conditions": {
      "min_severity": "important"
    }
  },
  "comment": "Valid rule without conflicts"
}
```

### 2. Правило с временными условиями
```json
{
  "event_type": "TASK_CREATED",
  "enabled": true,
  "actions": ["notify_manager"],
  "severity": "important",
  "conditions": {
    "user_roles": ["project_manager"],
    "time_conditions": {
      "business_hours_only": true,
      "timezone": "America/New_York"
    }
  },
  "comment": "Notify manager only during business hours"
}
```

### 3. Правило с исключениями
```json
{
  "event_type": "STATUS_CHANGED",
  "enabled": true,
  "actions": ["notify_assignees"],
  "severity": "critical",
  "conditions": {
    "exclude_roles": ["admin"],  // Исключаем только админов
    "user_roles": ["contractor", "architect"]
  },
  "comment": "Notify assignees except admins"
}
```

## API Валидация

### Создание правила с конфликтами
```bash
curl -X POST "http://localhost:8000/api/v1/admin/event-rules" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "event_type": "CONFLICT_TEST",
    "enabled": true,
    "actions": ["notify_admin"],
    "severity": "important",
    "conditions": {
      "user_roles": ["contractor"],
      "event_conditions": {
        "min_severity": "critical"
      }
    }
  }'
```

**Ответ с ошибками:**
```json
{
  "error_code": 400,
  "status": "error",
  "message": "Validation failed",
  "data": {
    "errors": [
      "Rule severity 'important' is lower than required minimum 'critical'",
      "Action 'notify_admin' requires 'admin' role, but only contractor are allowed"
    ]
  }
}
```

### Обновление правила с конфликтами
```bash
curl -X PUT "http://localhost:8000/api/v1/admin/event-rules/PROJECT_CREATED" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "actions": ["notify_assignees"],
    "conditions": {
      "exclude_roles": ["contractor", "architect"]
    }
  }'
```

**Ответ с ошибкой:**
```json
{
  "error_code": 400,
  "status": "error",
  "message": "Validation failed",
  "data": {
    "errors": [
      "Action 'notify_assignees' conflicts with excluded roles: contractor, architect"
    ]
  }
}
```

## Рекомендации

### 1. Приоритет полей
- `severity` - основная важность правила
- `event_conditions.min_severity` - дополнительное условие
- **Рекомендация:** Используйте `event_conditions.min_severity` только для дополнительной фильтрации

### 2. Логика действий
- `actions` определяют, что делать
- `user_roles` и `exclude_roles` определяют, кто может вызвать событие
- **Рекомендация:** Убедитесь, что действия соответствуют разрешенным ролям

### 3. Комбинирование условий
- Можно комбинировать несколько типов условий
- **Рекомендация:** Тестируйте сложные правила перед использованием в продакшене

## Отладка конфликтов

### 1. Проверка через API
Используйте endpoint `GET /api/v1/admin/event-rules/conditions` для получения списка доступных условий.

### 2. Пошаговая валидация
1. Создайте правило без условий
2. Добавьте условия по одному
3. Проверяйте валидацию на каждом шаге

### 3. Тестирование
Создайте тестовые правила с различными комбинациями условий для проверки логики.

## Заключение

Система валидации конфликтов предотвращает создание логически противоречивых правил событий, обеспечивая корректную работу системы уведомлений.
