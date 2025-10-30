# Анализ системы событий и правил

## Проблемы и несоответствия

### 1. Несоответствие между документацией и кодом

#### Документация (`SIMPLIFIED_EVENT_RULES.md`):
- Упрощенная система с действиями: `notify`, `log_only`, `create_daily_report`
- Условие `notify_roles` должно указывать, кого уведомлять

#### Реальная реализация (`ProjectNotificationService.php`):
```php
switch ($action) {
    case 'notify_project_manager':  // ❌ Старое действие
    case 'notify_admin':              // ❌ Старое действие
    case 'send_email_notification':  // ❌ Старое действие
    case 'generate_daily_report':     // ✅ Есть в документации
    case 'send_email_report':         // ❌ Нет в документации
}
```

#### Доступные действия (`EventConditionsService::getAvailableActions()`):
- Список из 30+ действий, включая:
  - `notify`, `log_only`, `create_daily_report` (из документации)
  - `email`, `sms`, `push`, `webhook`, `slack` (каналы доставки, не действия!)
  - `backup`, `cleanup`, `restart_service` (системные действия)
  - `block_user`, `suspend_user` (действия безопасности)
  - `start_workflow`, `custom_action` (рабочие процессы)
  - И многое другое...

**Проблема:** Список действий не соответствует ни документации, ни текущей реализации!

---

### 2. Смешение концепций: действия vs каналы доставки

#### Действия (что делать):
- `notify` - уведомить
- `log_only` - только логирование
- `create_daily_report` - создать отчет
- `backup` - создать резервную копию
- `block_user` - заблокировать пользователя

#### Каналы доставки (как доставить):
- `email` - email
- `sms` - SMS
- `push` - push уведомление
- `webhook` - webhook
- `slack` - Slack

**Проблема:** Каналы доставки находятся в списке действий, что создает путаницу!

**Правильная структура должна быть:**
```json
{
  "actions": ["notify"],
  "conditions": {
    "notify_roles": ["admin", "project_manager"],
    "channels": ["email", "sms"]  // Каналы доставки
  }
}
```

---

### 3. Логика обработки действий

#### Текущая реализация:
1. `EventLoggingService::logEvent()` получает правило события
2. `processEventActions()` создает записи в `fw_event_outbox` с действиями из правила
3. `ProjectNotificationService::processEvent()` обрабатывает действия через switch-case

#### Проблемы:
- Switch-case не обрабатывает большинство действий из `getAvailableActions()`
- Старые действия (`notify_project_manager`, `notify_admin`) не используют `notify_roles`
- Нет единой точки обработки действий

---

### 4. Условия: много типов, мало использования

#### Определено в `getAvailableConditions()`:
- `user_roles` - разрешенные роли
- `exclude_roles` - исключенные роли
- `notify_roles` - роли для уведомления
- `time_conditions` - временные условия
- `project_conditions` - условия проекта
- `task_conditions` - условия задачи
- `event_conditions` - условия события
- `system_conditions` - системные условия
- `location_conditions` - географические условия
- `security_conditions` - условия безопасности
- `user_conditions` - условия пользователя

#### Используется в коде:
- Только `user_roles`, `exclude_roles`, `notify_roles` проверяются в валидации
- Остальные типы условий определены, но не используются в обработке

**Проблема:** Много определений, мало реализации!

---

### 5. Структура данных в базе

#### Таблица `fw_event_rules`:
```sql
event_type VARCHAR(48) PRIMARY KEY
enabled TINYINT(1)
actions LONGTEXT        -- JSON массив действий
severity ENUM('critical', 'important')
conditions LONGTEXT     -- JSON объект условий
execution_location VARCHAR(20)
```

#### Проблемы:
- `actions` хранится как JSON массив строк
- `conditions` хранится как JSON объект
- Нет валидации структуры на уровне БД
- Нет индексов для поиска по условиям

---

## Предложения по реструктуризации

### Вариант 1: Упрощенная система (рекомендуется)

#### Действия (только базовые):
```php
'notify' => [
    'description' => 'Отправить уведомление',
    'requires' => ['notify_roles', 'channels']
],
'log_only' => [
    'description' => 'Только логирование',
    'requires' => []
],
'create_daily_report' => [
    'description' => 'Создать ежедневный отчет',
    'requires' => []
],
'create_weekly_report' => [
    'description' => 'Создать еженедельный отчет',
    'requires' => []
],
'create_monthly_report' => [
    'description' => 'Создать ежемесячный отчет',
    'requires' => []
]
```

#### Условия (только используемые):
```php
'notify_roles' => [
    'description' => 'Роли для уведомления',
    'type' => 'array',
    'required_for' => ['notify']
],
'channels' => [
    'description' => 'Каналы доставки уведомлений',
    'type' => 'array',
    'values' => ['email', 'sms', 'push', 'webhook', 'slack'],
    'required_for' => ['notify']
],
'user_roles' => [
    'description' => 'Разрешенные роли пользователей',
    'type' => 'array'
],
'exclude_roles' => [
    'description' => 'Исключенные роли',
    'type' => 'array'
],
'time_conditions' => [
    'description' => 'Временные условия',
    'type' => 'object'
],
'project_conditions' => [
    'description' => 'Условия проекта',
    'type' => 'object'
],
'task_conditions' => [
    'description' => 'Условия задачи',
    'type' => 'object'
]
```

#### Структура правила:
```json
{
  "event_type": "PROJECT_CREATED",
  "enabled": true,
  "actions": ["notify"],
  "severity": "important",
  "conditions": {
    "notify_roles": ["admin", "project_manager"],
    "channels": ["email"],
    "time_conditions": {
      "business_hours_only": true
    }
  }
}
```

---

### Вариант 2: Расширенная система (если нужна гибкость)

#### Действия (группированные по категориям):

**Уведомления:**
- `notify` - базовое уведомление
- `notify_role` - уведомление конкретной роли
- `notify_user` - уведомление конкретного пользователя

**Отчеты:**
- `create_daily_report`
- `create_weekly_report`
- `create_monthly_report`

**Системные:**
- `log_only`
- `backup`
- `cleanup`

**Безопасность:**
- `block_user`
- `suspend_user`
- `enable_2fa`

**Интеграции:**
- `webhook`
- `api_call`
- `sync_data`

#### Каналы доставки (отдельное поле):
```json
{
  "actions": ["notify"],
  "channels": ["email", "sms"],
  "conditions": {
    "notify_roles": ["admin"]
  }
}
```

---

## Рекомендуемая структура правил

### Минимальная (текущая упрощенная):
```json
{
  "event_type": "PROJECT_CREATED",
  "enabled": true,
  "actions": ["notify"],
  "severity": "important",
  "conditions": {
    "notify_roles": ["admin", "project_manager"],
    "channels": ["email"]
  }
}
```

### Расширенная (с дополнительными условиями):
```json
{
  "event_type": "TASK_OVERDUE",
  "enabled": true,
  "actions": ["notify"],
  "severity": "critical",
  "conditions": {
    "notify_roles": ["project_manager"],
    "channels": ["email", "sms"],
    "time_conditions": {
      "business_hours_only": true,
      "timezone": "America/New_York"
    },
    "task_conditions": {
      "min_priority": 3,
      "overdue_only": true
    }
  }
}
```

---

## Миграция

### Шаг 1: Обновить `EventConditionsService`
- Убрать неиспользуемые действия
- Разделить действия и каналы
- Обновить документацию

### Шаг 2: Обновить `ProjectNotificationService`
- Заменить старые действия на новые
- Использовать `notify_roles` и `channels` из условий
- Унифицировать обработку уведомлений

### Шаг 3: Обновить валидацию
- Проверять обязательные условия для действий
- Валидировать каналы доставки
- Проверять конфликты между условиями

### Шаг 4: Обновить документацию
- Удалить устаревшую документацию
- Создать новую документацию с примерами
- Обновить Swagger

---

## Выводы

1. **Действия и каналы смешаны** - нужно разделить
2. **Документация не соответствует коду** - нужно привести в соответствие
3. **Много определений, мало реализации** - нужно убрать неиспользуемое
4. **Старая и новая системы сосуществуют** - нужно мигрировать на новую
5. **Нет единой точки обработки** - нужно создать универсальный обработчик

**Рекомендация:** Выбрать вариант 1 (упрощенная система) и привести всю систему в соответствие с ним.

