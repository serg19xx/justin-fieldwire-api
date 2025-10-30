# Архитектура обработки событий и правил

## Текущая архитектура

### Поток данных:

```
Событие → fw_event_log → Правило события → fw_event_outbox → Обработка
```

### Текущая структура:

1. **fw_event_log** - хранит все события (историческая запись)
   - Событие всегда логируется
   - Получает правило для определения действий
   - Создает записи в outbox на основе действий из правила

2. **fw_event_outbox** - очередь для обработки действий
   - Статус: `pending`, `sent`, `error`
   - Создается для каждого действия из правила
   - Обрабатывается асинхронно

### Проблемы текущей архитектуры:

1. **Нет приоритизации** - все события обрабатываются одинаково
2. **Не поддерживает прямую запись в outbox** - всегда через лог
3. **Структура payload не соответствует новой структуре действий** - нужны каналы, периодичность и т.д.
4. **Нет разделения на немедленную/отложенную обработку**

---

## Предлагаемая архитектура

### Вариант 1: Приоритеты в логе (рекомендуется)

#### Структура `fw_event_log`:

```sql
ALTER TABLE fw_event_log
ADD COLUMN priority ENUM('critical', 'high', 'normal', 'low') DEFAULT 'normal',
ADD COLUMN processed_immediately TINYINT(1) DEFAULT 0,
ADD COLUMN execution_location VARCHAR(20) DEFAULT NULL;
```

#### Поток данных:

```
Событие → fw_event_log (с приоритетом) 
         ↓
    Правило события
         ↓
    Проверка условий
         ↓
    ┌─────────────────┬─────────────────┐
    │                 │                 │
Немедленная      Отложенная       Только логирование
обработка       обработка
    │                 │
    ↓                 ↓
fw_event_outbox  fw_event_outbox
(priority=high)  (priority=normal)
```

#### Логика:

1. **Событие логируется** → `fw_event_log` с приоритетом
2. **Получается правило** → проверяются условия
3. **Если `processed_immediately = 1`** → создается outbox с `priority = 'critical'` и обрабатывается немедленно
4. **Если `priority = 'normal'`** → создается outbox с обычным приоритетом, обрабатывается асинхронно
5. **Если `priority = 'low'`** → можно обрабатывать batch'ами

#### Преимущества:

- ✅ Единая точка входа (все через лог)
- ✅ Аудит всех событий
- ✅ Гибкая приоритизация
- ✅ Обратная совместимость

---

### Вариант 2: Прямая запись в outbox (для критических операций)

#### Структура `fw_event_outbox`:

```sql
ALTER TABLE fw_event_outbox
ADD COLUMN priority ENUM('critical', 'high', 'normal', 'low') DEFAULT 'normal',
ADD COLUMN source ENUM('event_log', 'direct') DEFAULT 'event_log',
ADD COLUMN scheduled_for DATETIME NULL COMMENT 'Для отложенных действий',
ADD COLUMN execution_location VARCHAR(20) DEFAULT NULL;

-- Индекс для быстрой выборки приоритетных сообщений
CREATE INDEX idx_outbox_priority_status ON fw_event_outbox(priority, status, created_at);
```

#### Поток данных:

```
┌─────────────────────────────────────┐
│                                     │
│  Критическая операция               │
│  (например, блокировка пользователя)│
│                                     │
└──────────────┬──────────────────────┘
               │
               ↓
       ┌───────────────┐
       │ fw_event_outbox│ ← Прямая запись
       │ (source=direct)│
       │ (priority=critical)│
       └───────────────┘
               │
               ↓
         Немедленная обработка
```

#### Использование:

```php
// Для критических операций - прямая запись в outbox
$eventLoggingService->logDirectAction([
    'action' => 'block_user',
    'priority' => 'critical',
    'payload' => [...],
    'channels' => ['email', 'sms']
]);
```

#### Преимущества:

- ✅ Немедленная обработка без проверки правил
- ✅ Для критических операций (безопасность, блокировки)
- ✅ Меньше задержек

#### Недостатки:

- ❌ Нет связи с логом событий (можно добавить опционально)
- ❌ Нет аудита через единую точку

---

### Вариант 3: Гибридный подход (рекомендуется)

#### Комбинирование обоих вариантов:

1. **Обычные события** → через `fw_event_log` с приоритетами
2. **Критические операции** → прямая запись в `fw_event_outbox` с автоматическим логированием

#### Структура:

```sql
-- fw_event_log
ALTER TABLE fw_event_log
ADD COLUMN priority ENUM('critical', 'high', 'normal', 'low') DEFAULT 'normal',
ADD COLUMN processed_immediately TINYINT(1) DEFAULT 0,
ADD COLUMN execution_location VARCHAR(20) DEFAULT NULL,
ADD INDEX idx_log_priority_immediate (priority, processed_immediately, occurred_at);

-- fw_event_outbox
ALTER TABLE fw_event_outbox
ADD COLUMN priority ENUM('critical', 'high', 'normal', 'low') DEFAULT 'normal',
ADD COLUMN source ENUM('event_log', 'direct') DEFAULT 'event_log',
ADD COLUMN scheduled_for DATETIME NULL,
ADD COLUMN execution_location VARCHAR(20) DEFAULT NULL,
ADD INDEX idx_outbox_priority_status (priority, status, created_at);
```

#### Поток данных:

```
┌─────────────────────────────────────────────────────────┐
│                    Событие                              │
└──────────────┬──────────────────────────────────────────┘
               │
       ┌───────┴────────┐
       │                 │
   Критическое?      Обычное?
       │                 │
       ↓                 ↓
┌──────────────┐  ┌──────────────┐
│ Прямая запись│  │ Через лог    │
│ в outbox     │  │ с приоритетом│
│ (source=direct)│ │              │
└──────┬───────┘  └──────┬───────┘
       │                 │
       │                 │
       └────────┬────────┘
                │
            ┌───┴────┐
            │ outbox │
            └───┬────┘
                │
        ┌───────┴────────┐
        │                 │
   Немедленная       Отложенная
   обработка         обработка
```

---

## Обновление структуры payload

### Текущая структура payload в `fw_event_outbox`:

```json
{
  "event_log_id": 123,
  "event_type": "PROJECT_CREATED",
  "action": "notify",
  "entity_type": "project",
  "entity_id": 456,
  "severity": "important",
  "actor_id": 789,
  "before_data": {...},
  "after_data": {...}
}
```

### Новая структура payload (поддерживает новую систему действий):

```json
{
  "event_log_id": 123,
  "event_type": "PROJECT_CREATED",
  "action": {
    "type": "notify",
    "channels": ["email", "sms"],
    "store_for_dashboard": true,
    "template_id": 123
  },
  "conditions": {
    "notify_roles": ["admin", "project_manager"],
    "time_conditions": {...}
  },
  "entity_type": "project",
  "entity_id": 456,
  "severity": "important",
  "priority": "high",
  "actor_id": 789,
  "before_data": {...},
  "after_data": {...},
  "correlation_id": "uuid",
  "execution_location": "server"
}
```

### Для отчетов:

```json
{
  "action": {
    "type": "create_report",
    "period": "daily",
    "store_for_dashboard": true,
    "recipients": ["admin", "project_manager"]
  },
  "scheduled_for": "2025-01-15 09:00:00"
}
```

---

## Изменения в коде

### 1. EventLoggingService::logEvent()

```php
public function logEvent(
    string $entityType,
    int $entityId,
    string $eventType,
    array $beforeData = [],
    array $afterData = [],
    array $changedFields = [],
    array $options = []
): ?int {
    // Получаем правило
    $eventRule = $this->getEventRule($eventType);
    
    // Определяем приоритет
    $priority = $options['priority'] ?? $eventRule['priority'] ?? 'normal';
    $processedImmediately = $options['processed_immediately'] ?? false;
    
    // Логируем событие
    $eventLogId = $this->insertEventLog($logData, $priority, $processedImmediately);
    
    // Проверяем условия
    if ($this->checkConditions($eventRule['conditions'], $eventData)) {
        // Создаем записи в outbox для каждого действия
        $this->processEventActions(
            $eventLogId, 
            $eventType, 
            $eventRule['actions'], 
            $logData,
            $priority,
            $processedImmediately
        );
    }
    
    return $eventLogId;
}
```

### 2. EventLoggingService::logDirectAction()

```php
/**
 * Прямая запись действия в outbox (для критических операций)
 */
public function logDirectAction(array $actionData, array $payload, string $priority = 'critical'): ?int
{
    try {
        // Опционально: логируем в event_log для аудита
        $eventLogId = null;
        if ($actionData['audit'] ?? true) {
            $eventLogId = $this->insertEventLog([
                'entity_type' => $payload['entity_type'] ?? 'system',
                'entity_id' => $payload['entity_id'] ?? 0,
                'event_type' => $payload['event_type'] ?? 'DIRECT_ACTION',
                'severity' => $priority === 'critical' ? 'critical' : 'important',
                'priority' => $priority,
                'processed_immediately' => 1,
                // ... остальные данные
            ], $priority, true);
        }
        
        // Прямая запись в outbox
        $this->connection->executeStatement(
            'INSERT INTO fw_event_outbox 
             (event_log_id, event_type, payload, status, priority, source, execution_location) 
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $eventLogId,
                $payload['event_type'] ?? 'DIRECT_ACTION',
                json_encode([
                    'action' => $actionData,
                    'payload' => $payload,
                    'priority' => $priority,
                    'source' => 'direct'
                ], JSON_UNESCAPED_UNICODE),
                'pending',
                $priority,
                'direct',
                $actionData['execution_location'] ?? null
            ]
        );
        
        $outboxId = $this->connection->lastInsertId();
        
        // Если критический приоритет - обрабатываем немедленно
        if ($priority === 'critical') {
            $this->processImmediately($outboxId);
        }
        
        return $outboxId;
    } catch (Exception $e) {
        $this->logger->error('Failed to log direct action', [
            'error' => $e->getMessage(),
            'action_data' => $actionData
        ]);
        return null;
    }
}
```

### 3. ProjectNotificationService::processEvent()

```php
public function processPendingEvents(int $limit = 100, ?string $priority = null): void
{
    $whereClause = 'status = ?';
    $params = ['pending'];
    
    // Фильтр по приоритету
    if ($priority) {
        $whereClause .= ' AND priority = ?';
        $params[] = $priority;
    }
    
    // Сортировка: сначала критичные, потом по дате
    $result = $this->connection->executeQuery(
        "SELECT * FROM fw_event_outbox 
         WHERE $whereClause 
         ORDER BY 
           CASE priority 
             WHEN 'critical' THEN 1 
             WHEN 'high' THEN 2 
             WHEN 'normal' THEN 3 
             WHEN 'low' THEN 4 
           END,
           created_at ASC
         LIMIT $limit",
        $params
    );
    
    foreach ($result->fetchAllAssociative() as $event) {
        $this->processEvent($event);
    }
}
```

---

## Рекомендации

### 1. Использовать гибридный подход

- ✅ Все события логируются в `fw_event_log` для аудита
- ✅ Обычные события идут через правило → outbox
- ✅ Критические операции могут идти напрямую в outbox (с опциональным логированием)

### 2. Приоритеты обработки

- **critical** - немедленная обработка (безопасность, блокировки)
- **high** - высокий приоритет (уведомления пользователей)
- **normal** - обычная обработка (большинство событий)
- **low** - низкий приоритет (отчеты, аналитика)

### 3. Обновить структуру payload

- Поддержка новой структуры действий (объекты вместо строк)
- Добавить каналы доставки, периодичность отчетов
- Добавить флаг `store_for_dashboard`

### 4. Разделение по execution_location

- `server` - обрабатывается на сервере
- `n8n` - обрабатывается через n8n
- `both` - обрабатывается на сервере и передается в n8n

---

## Миграция

### Шаг 1: Обновить структуру таблиц

```sql
-- fw_event_log
ALTER TABLE fw_event_log
ADD COLUMN priority ENUM('critical', 'high', 'normal', 'low') DEFAULT 'normal',
ADD COLUMN processed_immediately TINYINT(1) DEFAULT 0,
ADD COLUMN execution_location VARCHAR(20) DEFAULT NULL,
ADD INDEX idx_log_priority_immediate (priority, processed_immediately, occurred_at);

-- fw_event_outbox
ALTER TABLE fw_event_outbox
ADD COLUMN priority ENUM('critical', 'high', 'normal', 'low') DEFAULT 'normal',
ADD COLUMN source ENUM('event_log', 'direct') DEFAULT 'event_log',
ADD COLUMN scheduled_for DATETIME NULL,
ADD COLUMN execution_location VARCHAR(20) DEFAULT NULL,
ADD INDEX idx_outbox_priority_status (priority, status, created_at);
```

### Шаг 2: Обновить код обработки

- Обновить `processEventActions()` для поддержки новой структуры действий
- Добавить `logDirectAction()` для критических операций
- Обновить `processPendingEvents()` для приоритизации

### Шаг 3: Миграция существующих данных

```sql
-- Установить приоритет для существующих записей
UPDATE fw_event_log SET priority = 
  CASE severity 
    WHEN 'critical' THEN 'critical'
    ELSE 'normal'
  END;

UPDATE fw_event_outbox SET priority = 'normal', source = 'event_log';
```

---

## Выводы

1. **Все события должны логироваться** - для аудита и истории
2. **Приоритеты в логе** - для определения срочности обработки
3. **Обновить структуру payload** - для поддержки новой системы действий
4. **Гибридный подход** - комбинация обоих вариантов для максимальной гибкости

**Важно:** Система логирования событий (EVENT LOGGING SYSTEM) предназначена для стандартных бизнес-событий с асинхронной обработкой через cron/очередь.

**Аварийные оповещения (ALARM NOTIFICATION SYSTEM)** - это отдельная система для критических ситуаций, которая выходит за рамки системы логирования и требует немедленной обработки без очередей. Такая система может быть реализована в будущем, если потребуется.

**Рекомендация:** Использовать текущую систему логирования как есть, с приоритетами для определения порядка обработки в очереди. Для действительно немедленных оповещений (аварии, критические инциденты) - использовать отдельную систему аварийных оповещений (ALARM NOTIFICATION SYSTEM), которая работает в обход системы логирования.

