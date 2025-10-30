# Реструктуризация системы событий и правил

## Проблемы текущей реализации

### 1. Способ доставки сообщений
**Проблема:** `actions: ["notify"]` не указывает КАК доставлять (SMS, email, push).

**Текущее состояние:**
- Действия типа `email`, `sms` смешаны с реальными действиями
- Нет явного указания каналов доставки

**Решение:** Добавить поле `channels` для способа доставки.

### 2. Периодичность отчетов
**Проблема:** Только `create_daily_report`, но нужны недели, декады, месяцы, кварталы.

**Текущее состояние:**
- Один тип отчета: `create_daily_report`
- Нет настройки периодичности

**Решение:** Унифицировать отчеты с указанием периодичности.

### 3. Хранение для дашборда
**Проблема:** Некоторые действия нужно хранить для отображения на дашборде.

**Текущее состояние:**
- Все события логируются в `fw_event_log`
- Нет разделения на "для дашборда" и "только для логирования"

**Решение:** Добавить флаг `store_for_dashboard` в действие.

### 4. Избыточность условий
**Проблема:** Много условий может привести к тому, что событие вообще не сработает (все условия должны быть выполнены).

**Текущее состояние:**
- Условия проверяются через AND (все должны быть true)
- Нет возможности сделать условия "мягкими" или опциональными

**Решение:** 
- Разделить условия на обязательные и опциональные
- Или использовать операторы AND/OR для группировки условий

---

## Предлагаемая структура правил

### Базовая структура

```json
{
  "event_type": "PROJECT_CREATED",
  "enabled": true,
  "actions": [
    {
      "type": "notify",
      "channels": ["email", "sms"],
      "store_for_dashboard": true
    },
    {
      "type": "create_report",
      "period": "daily",
      "store_for_dashboard": true
    },
    {
      "type": "log_only",
      "store_for_dashboard": false
    }
  ],
  "severity": "important",
  "conditions": {
    "required": {
      "user_roles": ["admin", "project_manager"]
    },
    "optional": {
      "time_conditions": {
        "business_hours_only": true
      }
    }
  }
}
```

### Альтернатива: Простая структура с отдельными полями

```json
{
  "event_type": "PROJECT_CREATED",
  "enabled": true,
  "actions": ["notify", "create_report"],
  "channels": ["email", "sms"],  // Для всех notify действий
  "report_period": "daily",       // Для всех report действий
  "store_for_dashboard": true,   // Глобальный флаг или массив действий
  "severity": "important",
  "conditions": {
    "notify_roles": ["admin", "project_manager"],
    "time_conditions": {
      "business_hours_only": true
    }
  }
}
```

---

## Детальная структура действий

### Вариант 1: Объекты действий (рекомендуется)

```json
{
  "actions": [
    {
      "type": "notify",
      "channels": ["email", "sms"],
      "store_for_dashboard": true,
      "template_id": 123  // ID шаблона из fw_message_templates
    },
    {
      "type": "create_report",
      "period": "daily",  // daily, weekly, monthly, quarterly, custom
      "custom_period": null,  // Для custom: "P7D" (ISO 8601 duration)
      "store_for_dashboard": true,
      "recipients": ["admin", "project_manager"]
    },
    {
      "type": "log_only",
      "store_for_dashboard": false
    }
  ]
}
```

### Вариант 2: Простые строки + отдельные поля

```json
{
  "actions": ["notify", "create_report"],
  "channels": ["email", "sms"],  // Применяется ко всем notify
  "report_period": "daily",       // Применяется ко всем reports
  "dashboard_actions": ["notify", "create_report"]  // Какие действия хранить для дашборда
}
```

---

## Типы действий

### 1. Уведомления (`notify`)
```json
{
  "type": "notify",
  "channels": ["email", "sms", "push", "webhook", "slack"],
  "store_for_dashboard": true,
  "template_id": 123  // ID шаблона (опционально)
}
```

**Каналы доставки:**
- `email` - Email уведомление
- `sms` - SMS сообщение
- `push` - Push уведомление (мобильное приложение)
- `webhook` - Webhook запрос
- `slack` - Сообщение в Slack
- `telegram` - Сообщение в Telegram (если нужно)

### 2. Отчеты (`create_report`)
```json
{
  "type": "create_report",
  "period": "daily|weekly|monthly|quarterly|custom",
  "custom_period": "P7D",  // ISO 8601 duration (только для custom)
  "store_for_dashboard": true,
  "recipients": ["admin", "project_manager"],  // Кому отправлять отчет
  "format": "email|dashboard|both"  // Куда отправлять
}
```

**Периодичности:**
- `daily` - Ежедневно
- `weekly` - Еженедельно (понедельник)
- `monthly` - Ежемесячно (1-е число)
- `quarterly` - Ежеквартально (1-е число квартала)
- `custom` - Произвольная (требует `custom_period`)

### 3. Логирование (`log_only`)
```json
{
  "type": "log_only",
  "store_for_dashboard": false  // Обычно false, но можно включить
}
```

### 4. Системные действия (для будущего расширения)
```json
{
  "type": "backup",
  "store_for_dashboard": false
},
{
  "type": "cleanup",
  "store_for_dashboard": false
}
```

---

## Упрощение условий

### Проблема: Избыточность условий

**Текущая логика:** Все условия должны быть выполнены (AND).

**Проблема:** Если хотя бы одно условие не выполнилось, правило не сработает.

**Пример проблемы:**
```json
{
  "conditions": {
    "user_roles": ["admin"],           // Пользователь - админ ✅
    "time_conditions": {
      "business_hours_only": true      // Сейчас 20:00 (не рабочее время) ❌
    },
    "project_conditions": {
      "min_budget": 100000            // Бюджет 50000 ❌
    }
  }
}
```
Результат: Правило не сработает, хотя событие важное!

### Решение 1: Разделение на обязательные и опциональные

```json
{
  "conditions": {
    "required": {
      // Эти условия ОБЯЗАТЕЛЬНО должны быть выполнены
      "user_roles": ["admin", "project_manager"]
    },
    "optional": {
      // Эти условия проверяются, но если не выполнены - правило все равно сработает
      // Но можно использовать для условной логики внутри действия
      "time_conditions": {
        "business_hours_only": true
      },
      "project_conditions": {
        "min_budget": 100000
      }
    }
  }
}
```

**Логика:**
- Если `required` условия не выполнены → правило не срабатывает
- Если `required` выполнены, но `optional` нет → правило срабатывает, но действие может изменить поведение

### Решение 2: Операторы AND/OR для группировки

```json
{
  "conditions": {
    "operator": "AND",  // или "OR"
    "groups": [
      {
        "operator": "OR",
        "conditions": {
          "user_roles": ["admin"],
          "user_roles": ["project_manager"]
        }
      },
      {
        "operator": "AND",
        "conditions": {
          "time_conditions": {
            "business_hours_only": true
          }
        }
      }
    ]
  }
}
```

**Сложность:** Высокая, может быть избыточной.

### Решение 3: Приоритеты условий (рекомендуется)

```json
{
  "conditions": {
    "user_roles": {
      "value": ["admin", "project_manager"],
      "priority": "required"  // required, preferred, optional
    },
    "time_conditions": {
      "value": {
        "business_hours_only": true
      },
      "priority": "preferred"  // Предпочтительно, но не обязательно
    },
    "project_conditions": {
      "value": {
        "min_budget": 100000
      },
      "priority": "optional"  // Опционально
    }
  }
}
```

**Логика:**
- `required` - правило не сработает, если условие не выполнено
- `preferred` - правило сработает, но действие может изменить поведение (например, отложить отправку)
- `optional` - условие игнорируется при проверке, но передается в действие для условной логики

### Решение 4: Минимальное упрощение (простое)

Оставить текущую структуру, но добавить флаг `strict_mode`:

```json
{
  "conditions": {
    "strict_mode": false,  // Если false, то достаточно выполнить хотя бы одно условие
    "user_roles": ["admin"],
    "time_conditions": {
      "business_hours_only": true
    }
  }
}
```

**Логика:**
- `strict_mode: true` - все условия должны быть выполнены (AND)
- `strict_mode: false` - хотя бы одно условие должно быть выполнено (OR)

---

## Минимальные обязательные условия

### Для действия `notify`:
- `notify_roles` или `notify_users` - кого уведомлять
- `channels` - каналы доставки (в действии или в условиях)

### Для действия `create_report`:
- `report_period` - периодичность отчета
- `recipients` - получатели отчета (опционально)

### Для действия `log_only`:
- Ничего не требуется

---

## Предлагаемая финальная структура

### Рекомендуемая структура правил:

```json
{
  "event_type": "PROJECT_CREATED",
  "enabled": true,
  "actions": [
    {
      "type": "notify",
      "channels": ["email", "sms"],
      "store_for_dashboard": true,
      "template_id": 123
    },
    {
      "type": "create_report",
      "period": "daily",
      "store_for_dashboard": true,
      "recipients": ["admin", "project_manager"]
    }
  ],
  "severity": "important",
  "conditions": {
    "strict_mode": true,  // Все условия должны быть выполнены
    "user_roles": {
      "value": ["admin", "project_manager"],
      "priority": "required"
    },
    "notify_roles": {
      "value": ["admin", "project_manager"],
      "priority": "required"
    },
    "time_conditions": {
      "value": {
        "business_hours_only": true
      },
      "priority": "preferred"  // Предпочтительно, но не блокирует
    }
  },
  "execution_location": "server",
  "comment": "Notify admins and managers about new projects"
}
```

### Упрощенная структура (для обратной совместимости):

```json
{
  "event_type": "PROJECT_CREATED",
  "enabled": true,
  "actions": ["notify", "create_report"],
  "channels": ["email", "sms"],
  "report_period": "daily",
  "store_for_dashboard": ["notify", "create_report"],
  "severity": "important",
  "conditions": {
    "strict_mode": false,  // OR логика для условий
    "notify_roles": ["admin", "project_manager"],
    "user_roles": ["admin", "project_manager"],
    "time_conditions": {
      "business_hours_only": true
    }
  }
}
```

---

## Миграция существующих правил

### Шаг 1: Обновить структуру действий
```php
// Старая структура
"actions": ["notify", "log_only"]

// Новая структура
"actions": [
  {"type": "notify", "channels": ["email"], "store_for_dashboard": true},
  {"type": "log_only", "store_for_dashboard": false}
]
```

### Шаг 2: Добавить каналы доставки
- Если `actions` содержит `notify`, добавить `channels` из настроек по умолчанию

### Шаг 3: Добавить периодичность отчетов
- Если `actions` содержит `create_daily_report`, преобразовать в `{"type": "create_report", "period": "daily"}`

### Шаг 4: Упростить условия
- Оставить только используемые условия
- Добавить `strict_mode: false` для новых правил

---

## Изменения в базе данных

### Таблица `fw_event_rules`
```sql
ALTER TABLE fw_event_rules 
MODIFY COLUMN actions LONGTEXT COMMENT 'JSON массив объектов действий',
ADD COLUMN channels JSON COMMENT 'Каналы доставки (deprecated, используется в actions)',
ADD COLUMN strict_mode TINYINT(1) DEFAULT 1 COMMENT 'Строгий режим проверки условий';
```

---

## Изменения в коде

### 1. `EventConditionsService::getAvailableActions()`
Вернуть только базовые типы действий:
- `notify`
- `create_report`
- `log_only`

### 2. `ProjectNotificationService::processEvent()`
Обрабатывать новую структуру действий:
```php
foreach ($actions as $action) {
    if (is_string($action)) {
        // Старая структура (для обратной совместимости)
        $action = ['type' => $action];
    }
    
    switch ($action['type']) {
        case 'notify':
            $channels = $action['channels'] ?? ['email'];
            $this->notify($action, $channels);
            break;
        case 'create_report':
            $period = $action['period'] ?? 'daily';
            $this->createReport($action, $period);
            break;
        // ...
    }
}
```

### 3. Проверка условий
```php
public function checkConditions(?array $conditions, array $eventData): bool
{
    if (empty($conditions)) {
        return true;
    }
    
    $strictMode = $conditions['strict_mode'] ?? true;
    $requiredConditions = [];
    $optionalConditions = [];
    
    // Разделяем условия по приоритету
    foreach ($conditions as $key => $condition) {
        if ($key === 'strict_mode') continue;
        
        if (is_array($condition) && isset($condition['priority'])) {
            if ($condition['priority'] === 'required') {
                $requiredConditions[$key] = $condition['value'];
            } else {
                $optionalConditions[$key] = $condition['value'];
            }
        } else {
            // Старая структура - считаем обязательным
            $requiredConditions[$key] = $condition;
        }
    }
    
    // Проверяем обязательные условия
    foreach ($requiredConditions as $type => $value) {
        if (!$this->checkCondition($type, $value, $eventData)) {
            return false; // Правило не сработает
        }
    }
    
    // Опциональные условия передаем в действие для условной логики
    // Правило сработает, но действие может изменить поведение
    
    return true;
}
```

---

## Выводы и рекомендации

1. **Действия должны быть объектами** с указанием каналов доставки
2. **Отчеты унифицировать** с указанием периодичности
3. **Добавить флаг `store_for_dashboard`** для каждого действия
4. **Упростить условия** с разделением на обязательные/опциональные
5. **Миграция пошаговая** с поддержкой обратной совместимости

**Рекомендуемая структура:** Объекты действий с каналами доставки + приоритеты условий.

