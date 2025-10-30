# Severity vs Priority - Разница и взаимосвязь

## Разница между полями

### `severity` (Важность события)
**Что это:** Бизнес-важность события с точки зрения бизнес-логики

**Возможные значения:**
- `critical` - критическое событие (авария, блокировка пользователя, удаление проекта)
- `important` - важное событие (создание проекта, изменение статуса задачи)

**Где используется:**
- В правилах событий (`fw_event_rules`)
- В логах событий (`fw_event_log`)
- Для фильтрации и поиска в интерфейсе
- Для определения, какие правила применить

**Примеры:**
- `PROJECT_DELETED` → `severity: critical` (критическое для бизнеса)
- `PROJECT_CREATED` → `severity: important` (важное, но не критическое)
- `TASK_OVERDUE` → `severity: important` (важное)
- `SYSTEM_FAILURE` → `severity: critical` (критическое)

---

### `priority` (Приоритет обработки)
**Что это:** Технический приоритет обработки события системой

**Возможные значения:**
- `critical` - немедленная обработка (0 секунд)
- `high` - высокий приоритет (обрабатывается первым в очереди)
- `normal` - обычный приоритет (стандартная очередь)
- `low` - низкий приоритет (можно обрабатывать batch'ами)

**Где используется:**
- В логах событий (`fw_event_log`)
- В очереди обработки (`fw_event_outbox`)
- Для определения порядка обработки
- Для немедленной синхронной обработки

**Примеры:**
- Авария на объекте → `priority: critical` (немедленно!)
- Уведомление о создании проекта → `priority: high` (быстро, но не блокирует)
- Ежедневный отчет → `priority: low` (можно обработать ночью)

---

## Взаимосвязь между severity и priority

### Вариант 1: Автоматическое преобразование (рекомендуется)

```php
// Маппинг severity → priority
$severityToPriority = [
    'critical' => 'critical',  // Критическое событие = критический приоритет
    'important' => 'high'     // Важное событие = высокий приоритет
];

// Но можно переопределить в правиле
$rule = [
    'severity' => 'important',
    'priority' => 'critical'  // Переопределение для немедленной обработки
];
```

### Вариант 2: Независимые поля

```php
// severity определяет бизнес-важность
// priority определяет технический приоритет обработки

// Пример: Важное событие, но можно обработать позже
$event = [
    'severity' => 'important',  // Бизнес-важность
    'priority' => 'low'         // Можно обработать ночью
];

// Пример: Критическое событие с немедленной обработкой
$event = [
    'severity' => 'critical',   // Бизнес-важность
    'priority' => 'critical'     // Немедленная обработка
];
```

### Вариант 3: Правило определяет оба

```json
{
  "event_type": "ACCIDENT_ON_SITE",
  "severity": "critical",      // Бизнес-важность
  "priority": "critical",      // Технический приоритет
  "processed_immediately": true
}
```

---

## Рекомендуемая структура

### Таблица `fw_event_rules`:
```sql
event_type VARCHAR(48) PRIMARY KEY
severity ENUM('critical', 'important')  -- Бизнес-важность
priority ENUM('critical', 'high', 'normal', 'low') DEFAULT NULL  -- Технический приоритет (опционально)
processed_immediately TINYINT(1) DEFAULT 0  -- Немедленная обработка
```

**Логика:**
- Если `priority` не указан → вычисляется из `severity`
- Если `priority` указан → используется указанный
- Если `processed_immediately = 1` → обработка синхронная (не ждет cron)

---

## Немедленная отправка (синхронная обработка)

### Проблема текущей архитектуры:

```
Событие → fw_event_log → fw_event_outbox → Cron (каждые 5 минут) → Отправка
```

**Задержка:** До 5 минут! ❌

### Решение 1: Синхронная обработка для критических событий

```php
public function logEvent(...): ?int
{
    // Логируем событие
    $eventLogId = $this->insertEventLog($logData);
    
    // Получаем правило
    $eventRule = $this->getEventRule($eventType);
    
    // Если немедленная обработка - обрабатываем синхронно
    if ($eventRule['processed_immediately'] ?? false) {
        // Создаем outbox запись
        $outboxId = $this->createOutboxEntry($eventLogId, $eventRule, $logData);
        
        // НЕМЕДЛЕННО обрабатываем (без ожидания cron)
        $this->processOutboxEventImmediately($outboxId);
    } else {
        // Обычная обработка - создаем outbox, ждем cron
        $this->createOutboxEntry($eventLogId, $eventRule, $logData);
    }
    
    return $eventLogId;
}

private function processOutboxEventImmediately(int $outboxId): void
{
    $notificationService = new ProjectNotificationService($this->database, $this->logger);
    $event = $this->getOutboxEvent($outboxId);
    
    // Обрабатываем прямо сейчас
    $notificationService->processEvent($event);
}
```

### Решение 2: Event-driven обработка (еще лучше)

Использовать очереди сообщений (RabbitMQ, Redis Queue) для немедленной обработки:

```php
public function logEvent(...): ?int
{
    // Логируем событие
    $eventLogId = $this->insertEventLog($logData);
    
    // Получаем правило
    $eventRule = $this->getEventRule($eventType);
    
    // Создаем outbox запись
    $outboxId = $this->createOutboxEntry($eventLogId, $eventRule, $logData);
    
    // Если критический приоритет - отправляем в очередь немедленно
    if (($eventRule['priority'] ?? 'normal') === 'critical') {
        // Отправляем в очередь для немедленной обработки
        $this->queueService->push('critical-events', [
            'outbox_id' => $outboxId,
            'priority' => 'critical'
        ]);
    }
    
    return $eventLogId;
}
```

### Решение 3: Webhook/Push для немедленной обработки

```php
public function logEvent(...): ?int
{
    // Логируем событие
    $eventLogId = $this->insertEventLog($logData);
    
    // Получаем правило
    $eventRule = $this->getEventRule($eventType);
    
    // Если немедленная обработка - вызываем обработчик напрямую
    if ($eventRule['processed_immediately'] ?? false) {
        // Создаем outbox для истории
        $outboxId = $this->createOutboxEntry($eventLogId, $eventRule, $logData);
        
        // Немедленно отправляем уведомления
        $this->sendNotificationsImmediately($eventRule, $logData);
        
        // Помечаем outbox как обработанный
        $this->updateOutboxStatus($outboxId, 'sent');
    } else {
        // Обычная обработка через cron
        $this->createOutboxEntry($eventLogId, $eventRule, $logData);
    }
    
    return $eventLogId;
}

private function sendNotificationsImmediately(array $rule, array $logData): void
{
    foreach ($rule['actions'] as $action) {
        if ($action['type'] === 'notify') {
            // Немедленно отправляем уведомления
            foreach ($action['channels'] as $channel) {
                switch ($channel) {
                    case 'email':
                        $this->sendEmail($logData);
                        break;
                    case 'sms':
                        $this->sendSms($logData);
                        break;
                    case 'push':
                        $this->sendPush($logData);
                        break;
                }
            }
        }
    }
}
```

---

## Структура правил для немедленной обработки

### Пример: Авария на объекте

```json
{
  "event_type": "ACCIDENT_ON_SITE",
  "enabled": true,
  "severity": "critical",        // Бизнес-важность: критическое
  "priority": "critical",         // Технический приоритет: критический
  "processed_immediately": true,  // Немедленная обработка (не ждать cron)
  "actions": [
    {
      "type": "notify",
      "channels": ["email", "sms", "push"],
      "store_for_dashboard": true
    }
  ],
  "conditions": {
    "notify_roles": ["admin", "project_manager", "safety_officer"]
  }
}
```

### Пример: Создание проекта (важное, но не срочное)

```json
{
  "event_type": "PROJECT_CREATED",
  "enabled": true,
  "severity": "important",       // Бизнес-важность: важное
  "priority": "high",            // Технический приоритет: высокий
  "processed_immediately": false, // Обычная обработка (через cron)
  "actions": [
    {
      "type": "notify",
      "channels": ["email"],
      "store_for_dashboard": true
    }
  ]
}
```

### Пример: Ежедневный отчет (не критично)

```json
{
  "event_type": "DAILY_REPORT",
  "enabled": true,
  "severity": "important",       // Бизнес-важность: важное
  "priority": "low",             // Технический приоритет: низкий
  "processed_immediately": false, // Обработка ночью
  "actions": [
    {
      "type": "create_report",
      "period": "daily",
      "store_for_dashboard": true
    }
  ]
}
```

---

## Реализация немедленной обработки

### Вариант 1: Синхронная обработка в том же запросе

```php
class EventLoggingService
{
    public function logEvent(...): ?int
    {
        // ... логирование ...
        
        // Если правило требует немедленной обработки
        if ($eventRule['processed_immediately'] ?? false) {
            // Создаем outbox запись
            $outboxId = $this->createOutboxEntry(...);
            
            // НЕМЕДЛЕННО обрабатываем (синхронно)
            $notificationService = new ProjectNotificationService(...);
            $event = $this->getOutboxEvent($outboxId);
            $notificationService->processEvent($event);
        }
        
        return $eventLogId;
    }
}
```

**Преимущества:**
- ✅ Немедленная отправка
- ✅ Простая реализация

**Недостатки:**
- ❌ Замедляет HTTP запрос (если отправка долгая)
- ❌ Если отправка упала - ошибка в HTTP ответе

### Вариант 2: Асинхронная обработка через очередь (рекомендуется)

```php
class EventLoggingService
{
    public function logEvent(...): ?int
    {
        // ... логирование ...
        
        // Создаем outbox запись
        $outboxId = $this->createOutboxEntry(...);
        
        // Если критический приоритет - отправляем в очередь немедленно
        if (($eventRule['priority'] ?? 'normal') === 'critical') {
            // Отправляем в очередь для немедленной обработки фоновым процессом
            $this->queueService->push('critical-events', [
                'outbox_id' => $outboxId
            ]);
            
            // Или запускаем фоновый процесс напрямую
            $this->processInBackground($outboxId);
        }
        
        return $eventLogId;
    }
    
    private function processInBackground(int $outboxId): void
    {
        // Запускаем обработку в фоне (не блокируем HTTP запрос)
        exec("php /path/to/process-event.php $outboxId > /dev/null 2>&1 &");
    }
}
```

**Преимущества:**
- ✅ Не замедляет HTTP запрос
- ✅ Немедленная обработка через фоновый процесс
- ✅ Ошибки обработки не влияют на HTTP ответ

### Вариант 3: Webhook/HTTP callback для немедленной обработки

```php
class EventLoggingService
{
    public function logEvent(...): ?int
    {
        // ... логирование ...
        
        // Если правило требует немедленной обработки
        if ($eventRule['processed_immediately'] ?? false) {
            // Отправляем webhook для немедленной обработки
            $this->sendWebhook('/api/v1/events/process-immediately', [
                'event_log_id' => $eventLogId,
                'event_type' => $eventType
            ]);
        }
        
        return $eventLogId;
    }
}
```

---

## Структура таблиц с приоритетами

### `fw_event_rules`:
```sql
CREATE TABLE fw_event_rules (
    event_type VARCHAR(48) PRIMARY KEY,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    severity ENUM('critical', 'important') NOT NULL,
    priority ENUM('critical', 'high', 'normal', 'low') DEFAULT NULL,
    processed_immediately TINYINT(1) DEFAULT 0,
    actions LONGTEXT NOT NULL,
    conditions LONGTEXT DEFAULT NULL,
    execution_location VARCHAR(20) DEFAULT NULL,
    comment VARCHAR(255),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by BIGINT(20) DEFAULT NULL
);
```

**Логика приоритета:**
- Если `priority` не указан → вычисляется из `severity`: `critical` → `critical`, `important` → `high`
- Если `priority` указан → используется указанный
- Если `processed_immediately = 1` → обработка синхронная/немедленная

### `fw_event_log`:
```sql
ALTER TABLE fw_event_log
ADD COLUMN priority ENUM('critical', 'high', 'normal', 'low') DEFAULT NULL,
ADD COLUMN processed_immediately TINYINT(1) DEFAULT 0,
ADD INDEX idx_log_priority_immediate (priority, processed_immediately, occurred_at);
```

### `fw_event_outbox`:
```sql
ALTER TABLE fw_event_outbox
ADD COLUMN priority ENUM('critical', 'high', 'normal', 'low') DEFAULT NULL,
ADD COLUMN processed_immediately TINYINT(1) DEFAULT 0,
ADD INDEX idx_outbox_priority_status (priority, status, created_at);
```

---

## Итоговая логика

### 1. Severity (бизнес-важность):
- Определяется в правиле события
- Используется для фильтрации и поиска
- Может быть: `critical`, `important`

### 2. Priority (технический приоритет):
- Может быть переопределен в правиле
- По умолчанию вычисляется из severity
- Используется для определения порядка обработки
- Может быть: `critical`, `high`, `normal`, `low`

### 3. Processed Immediately (немедленная обработка):
- Если `true` → обработка синхронная/немедленная (не ждет cron)
- Если `false` → обработка через cron/очередь
- Используется для критических событий (аварии, блокировки)

### 4. Немедленная отправка:
- Для `processed_immediately = true` → обработка синхронно или через очередь немедленно
- Для `priority = critical` → обработка в первую очередь
- Cron обрабатывает только `processed_immediately = false`

---

## Примеры использования

### Авария на объекте (немедленно):
```php
// Правило
{
  "event_type": "ACCIDENT_ON_SITE",
  "severity": "critical",
  "priority": "critical",
  "processed_immediately": true
}

// При логировании
$eventLogId = $eventLoggingService->logEvent(...);
// → Сразу обрабатывается синхронно или через очередь немедленно
// → Уведомления отправляются в течение секунд
```

### Создание проекта (обычная обработка):
```php
// Правило
{
  "event_type": "PROJECT_CREATED",
  "severity": "important",
  "priority": "high",  // или NULL (автоматически из severity)
  "processed_immediately": false
}

// При логировании
$eventLogId = $eventLoggingService->logEvent(...);
// → Создается запись в outbox
// → Cron обрабатывает в течение нескольких минут
```

---

## Выводы

1. **Severity** = бизнес-важность события (для пользователей)
2. **Priority** = технический приоритет обработки (для системы) - определяет порядок в очереди обработки
3. **Система логирования** = для стандартных бизнес-событий с асинхронной обработкой

**Важно:** Система логирования событий работает с очередью (outbox + cron), поэтому настоящая "немедленность" в ней не гарантируется. Приоритеты определяют порядок обработки в очереди, но не гарантируют мгновенную отправку.

**Аварийные оповещения:** Для действительно критических ситуаций (аварии на объекте, критические инциденты) должна быть отдельная система аварийных оповещений (ALARM NOTIFICATION SYSTEM), которая работает в обход системы логирования и обеспечивает немедленную отправку уведомлений. Такая система может быть реализована в будущем, если потребуется.

**Рекомендация для текущей системы:** Использовать приоритеты для определения порядка обработки в очереди. Система логирования работает как есть - через асинхронную обработку с приоритетами.

