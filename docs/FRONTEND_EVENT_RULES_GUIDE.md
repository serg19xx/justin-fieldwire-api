# Документация для фронтенда: Система правил событий

> **Updated 2026-07:** Recipients belong on each action (`recipients[]`), not in `conditions.notify_roles`.  
> Conditions are a **schedule filter** only (`time_conditions`). See [SIMPLIFIED_EVENT_RULES.md](./SIMPLIFIED_EVENT_RULES.md).

## Резюме анализа

### Проблемы, которые были решены:

1. **Смешение действий и каналов доставки** - теперь разделены
2. **Нет способа указать каналы доставки** - добавлено поле `channels` в действиях
3. **Только один тип отчетов** - унифицированы отчеты с периодичностью
4. **Избыточность условий** - добавлены приоритеты для условий
5. **Неясность между severity и priority** - разделены на бизнес-важность и технический приоритет
6. **Связь шаблонов с правилами** - изменена структура связей:
   - Убрано поле `event_type` из таблицы `fw_message_templates`
   - Добавлено поле `channel_templates` в действие `notify`
   - Шаблоны выбираются вручную для каждого канала доставки
   - Шаблон привязан к типу (email/sms), а не к событию

### Новая структура:

- **Действия** - объекты вместо строк, с указанием каналов доставки и параметров
- **Условия** - с приоритетами (required/preferred/optional)
- **Приоритеты** - для определения порядка обработки в очереди
- **Severity и Priority** - разделены (бизнес-важность vs технический приоритет)

---

## Структура правила события

### Полная структура JSON (current):

```json
{
  "event_type": "PROJECT_CREATED",
  "enabled": true,
  "severity": "critical",
  "priority": "high",
  "actions": [
    {
      "type": "notify",
      "channels": ["email", "sms"],
      "channel_templates": {
        "email": 123,
        "sms": 124
      },
      "recipients": ["admin", "project_manager"]
    },
    {
      "type": "create_report",
      "period": "daily",
      "recipients": ["admin", "project_manager"]
    },
    {
      "type": "log_only"
    }
  ],
  "conditions": {
    "time_conditions": {
      "business_hours_only": true,
      "weekdays_only": true,
      "timezone": "America/New_York",
      "time_range": { "start": "09:00", "end": "17:00" }
    }
  },
  "execution_location": "server",
  "comment": "Notify PM/admin during business hours"
}
```

### Legacy structure (deprecated — migrated on save):

```json
{
  "event_type": "PROJECT_CREATED",
  "enabled": true,
  "severity": "critical",
  "priority": "high",
  "actions": [
    {
      "type": "notify",
      "channels": ["email", "sms"],
      "channel_templates": {
        "email": 123,
        "sms": 124
      },
      "store_for_dashboard": true
    },
    {
      "type": "create_report",
      "period": "daily",
      "store_for_dashboard": true,
      "recipients": ["admin", "project_manager"]
    },
    {
      "type": "log_only",
      "store_for_dashboard": false
    }
  ],
  "conditions": {
    "strict_mode": false,
    "notify_roles": {
      "value": ["admin", "project_manager"],
      "priority": "required"
    },
    "user_roles": {
      "value": ["admin", "project_manager"],
      "priority": "required"
    },
    "time_conditions": {
      "value": {
        "business_hours_only": true,
        "timezone": "America/New_York"
      },
      "priority": "preferred"
    }
  },
  "execution_location": "server",
  "comment": "Notify admins and managers about new projects"
}
```

---

## Детальная структура полей

### 1. `event_type` (string, required)

**Описание:** Тип события, для которого применяется правило

**Формат:** UPPERCASE с подчеркиваниями

**Примеры:**
- `PROJECT_CREATED`
- `PROJECT_UPDATED`
- `PROJECT_DELETED`
- `TASK_CREATED`
- `TASK_OVERDUE`
- `USER_LOGIN`
- `USER_BLOCKED`

**Валидация:**
- Только заглавные буквы и подчеркивания
- Минимум 3 символа
- Максимум 48 символов
- Регулярное выражение: `/^[A-Z_]+$/`

**UI:**
- Текстовое поле с автодополнением
- Список доступных типов событий из API
- Валидация при вводе

---

### 2. `enabled` (boolean, required)

**Описание:** Включено ли правило

**Возможные значения:** `true`, `false`

**По умолчанию:** `true`

**UI:**
- Checkbox или toggle switch
- При `false` - правило не применяется

---

### 3. `severity` (enum, required)

**Описание:** Бизнес-важность события (для пользователей и фильтрации)

**Возможные значения:**
- `critical` - Критическое событие (удаление проекта, блокировка пользователя)
- `important` - Важное событие (создание проекта, изменение статуса)

**По умолчанию:** `important`

**Логика:**
- Используется для фильтрации в интерфейсе
- Показывается пользователям (цвет, иконка)
- Определяет, какие правила применить

**UI:**
- Radio buttons или select
- Визуальное отображение: `critical` - красный, `important` - оранжевый

---

### 4. `priority` (enum, optional)

**Описание:** Технический приоритет обработки в очереди (для системы)

**Возможные значения:**
- `critical` - Обрабатывается первым в очереди
- `high` - Высокий приоритет
- `normal` - Обычный приоритет (по умолчанию)
- `low` - Низкий приоритет (можно обрабатывать batch'ами)

**По умолчанию:** Если не указан, вычисляется из `severity`:
- `severity: critical` → `priority: critical`
- `severity: important` → `priority: high`

**Логика:**
- Определяет порядок обработки в очереди
- Высокий приоритет = быстрее обработается
- Не гарантирует немедленную обработку (это очередь)

**UI:**
- Select с опцией "Автоматически" (по severity)
- Если выбрано "Автоматически" → показывать вычисленное значение
- Визуально: `critical` - красный, `high` - желтый, `normal` - синий, `low` - серый

---

### 5. `actions` (array of objects, required)

**Описание:** Список действий, которые выполняются при срабатывании правила

**Тип:** Массив объектов действия

**Минимум:** 1 действие

**Структура действия:**

#### 5.1. Действие типа `notify`:

```json
{
  "type": "notify",
  "channels": ["email", "sms"],
  "channel_templates": {
    "email": 123,
    "sms": 124
  },
  "store_for_dashboard": true
}
```

**Поля:**

- **`type`** (string, required): `"notify"`
- **`channels`** (array of strings, required): Каналы доставки уведомлений
  - Возможные значения: `["email", "sms", "push", "webhook", "slack"]`
  - Минимум: 1 канал
  - Можно выбрать несколько
- **`channel_templates`** (object, optional): Шаблоны сообщений для каждого канала доставки
  - Ключи: названия каналов (`"email"`, `"sms"`)
  - Значения: ID шаблона из `fw_message_templates`
  - Обязательно для каналов `email` и `sms` (если эти каналы указаны)
  - Не требуется для каналов `push`, `webhook`, `slack`
  - Структура: `{"email": 123, "sms": 124}`
  - Валидация: `template.type` должен соответствовать каналу (email → email, sms → sms)
- **`store_for_dashboard`** (boolean, required): Хранить для дашборда
  - `true` - событие отображается на дашборде
  - `false` - только логирование

**Логика работы с шаблонами:**

- Шаблоны выбираются **вручную** для каждого канала доставки
- При выборе канала `email` → нужно выбрать шаблон с `type = 'email'`
- При выборе канала `sms` → нужно выбрать шаблон с `type = 'sms'`
- Шаблоны для `push`, `webhook`, `slack` не требуются (используется другой механизм)
- Шаблон может использоваться в разных правилах (нет привязки к `event_type`)
- Если шаблон не указан для канала → используется шаблон по умолчанию или отправка без шаблона

**Важно:** 
- Шаблон НЕ привязан к событию через `event_type` (это поле убрано из таблицы `fw_message_templates`)
- Шаблон привязан только к типу (email/sms) через поле `type`
- Правило назначает шаблоны для каждого канала отдельно через `channel_templates`
- Это позволяет использовать разные шаблоны для разных каналов в одном правиле

**UI для действия `notify`:**
- Тип действия: Select (disabled, значение "notify")
- Каналы доставки: Multi-select checkbox group
  - ✅ Email
  - ✅ SMS
  - ✅ Push уведомление
  - ✅ Webhook
  - ✅ Slack
- Для каждого выбранного канала, который требует шаблона (email, sms):
  - Показать Select для выбора шаблона
  - Заголовок: "Шаблон для {channel}"
  - Загрузка шаблонов: `GET /api/v1/admin/message-templates?type={channel}`
  - Фильтрация: только шаблоны с `type` = канал
  - Показывать: "Название шаблона [Категория: system/custom]"
  - Если шаблон имеет `parent_id` → показать иерархию: "Мой шаблон [Базовый: Системный шаблон]"
  - Опция: "Использовать шаблон по умолчанию" (не указывать template_id)
- Хранить для дашборда: Checkbox

---

#### 5.2. Действие типа `create_report`:

```json
{
  "type": "create_report",
  "period": "daily",
  "store_for_dashboard": true,
  "recipients": ["admin", "project_manager"]
}
```

**Поля:**

- **`type`** (string, required): `"create_report"`
- **`period`** (string, required): Периодичность отчета
  - Возможные значения: `"daily"`, `"weekly"`, `"monthly"`, `"quarterly"`, `"custom"`
  - Если `period: "custom"` → требуется поле `custom_period`
- **`custom_period`** (string, optional): Кастомная периодичность (ISO 8601 duration)
  - Только если `period: "custom"`
  - Примеры: `"P7D"` (7 дней), `"P1M"` (1 месяц)
- **`store_for_dashboard`** (boolean, required): Хранить для дашборда
- **`recipients`** (array of strings, optional): Получатели отчета
  - Возможные значения: `["admin", "project_manager", "contractor", "architect"]`
  - Если не указано - отчет создается, но не отправляется

**UI для действия `create_report`:**
- Тип действия: Select (disabled, значение "create_report")
- Периодичность: Select
  - Ежедневно
  - Еженедельно
  - Ежемесячно
  - Ежеквартально
  - Произвольная → показывать поле `custom_period`
- Кастомная периодичность: Текстовое поле (только если выбрано "Произвольная")
  - Placeholder: "P7D (7 дней), P1M (1 месяц)"
  - Валидация ISO 8601 duration
- Хранить для дашборда: Checkbox
- Получатели: Multi-select checkbox group
  - ✅ Admin
  - ✅ Project Manager
  - ✅ Contractor
  - ✅ Architect

---

#### 5.3. Действие типа `log_only`:

```json
{
  "type": "log_only",
  "store_for_dashboard": false
}
```

**Поля:**

- **`type`** (string, required): `"log_only"`
- **`store_for_dashboard`** (boolean, required): Хранить для дашборда
  - Обычно `false` для `log_only`

**UI для действия `log_only`:**
- Тип действия: Select (disabled, значение "log_only")
- Хранить для дашборда: Checkbox

---

#### UI для массива actions:

**Общий интерфейс:**

1. **Список действий:**
   - Таблица или список с карточками действий
   - Показывать тип действия, каналы (для notify), периодичность (для report)
   - Кнопки: Редактировать, Удалить, Переместить

2. **Добавление действия:**
   - Кнопка "Добавить действие"
   - Модальное окно с выбором типа действия
   - После выбора типа - показывать форму для этого типа

3. **Редактирование действия:**
   - Клик по действию → модальное окно с формой
   - Все поля, специфичные для типа действия

4. **Валидация:**
   - При удалении последнего действия - ошибка
   - Для `notify` - обязательно указать хотя бы один канал
   - Для `create_report` - если `period: "custom"`, обязательно `custom_period`

---

### 6. `conditions` (object, optional)

**Описание:** Условия срабатывания правила

**Структура:**

```json
{
  "strict_mode": false,
  "notify_roles": {
    "value": ["admin", "project_manager"],
    "priority": "required"
  },
  "user_roles": {
    "value": ["admin", "project_manager"],
    "priority": "required"
  },
  "time_conditions": {
    "value": {
      "business_hours_only": true,
      "timezone": "America/New_York"
    },
    "priority": "preferred"
  }
}
```

**Поля верхнего уровня:**

- **`strict_mode`** (boolean, optional): Строгий режим проверки условий
  - `true` - все условия должны быть выполнены (AND)
  - `false` - хотя бы одно условие должно быть выполнено (OR)
  - По умолчанию: `false`

**Каждое условие имеет структуру:**

```json
{
  "value": <типизированное_значение>,
  "priority": "required" | "preferred" | "optional"
}
```

**Приоритеты условий:**

- **`required`** - обязательное условие
  - Если не выполнено → правило не срабатывает
  - Обязательно для `notify_roles` если есть действие `notify`
  
- **`preferred`** - предпочтительное условие
  - Правило сработает, но действие может изменить поведение
  - Например, если время не рабочее - отложить отправку
  
- **`optional`** - опциональное условие
  - Игнорируется при проверке, но передается в действие для условной логики

---

#### 6.1. Условие `notify_roles`:

**Описание:** Роли пользователей, которых нужно уведомить (обязательно для действия `notify`)

**Тип:** `object`

**Структура:**
```json
{
  "value": ["admin", "project_manager"],
  "priority": "required"
}
```

**`value`** (array of strings, required):
- Возможные значения: `["admin", "project_manager", "contractor", "architect", "viewer", "guest"]`
- Минимум: 1 роль
- Можно выбрать несколько

**`priority`** (string, required):
- По умолчанию: `"required"`
- Если есть действие `notify` → обязательно `"required"`

**UI:**
- Заголовок: "Роли для уведомления"
- Multi-select checkbox group:
  - ✅ Admin
  - ✅ Project Manager
  - ✅ Contractor
  - ✅ Architect
  - ✅ Viewer
  - ✅ Guest
- Приоритет: Select (disabled, значение "Обязательное")
- Валидация: Если есть действие `notify` → обязательно указать хотя бы одну роль

---

#### 6.2. Условие `user_roles`:

**Описание:** Роли пользователей, которые могут вызвать событие

**Тип:** `object`

**Структура:**
```json
{
  "value": ["admin", "project_manager"],
  "priority": "required"
}
```

**`value`** (array of strings, required):
- Возможные значения: `["admin", "project_manager", "contractor", "architect", "viewer", "guest"]`
- Минимум: 1 роль

**`priority`** (string, required):
- Возможные значения: `"required"`, `"preferred"`, `"optional"`
- По умолчанию: `"required"`

**UI:**
- Заголовок: "Роли пользователей"
- Multi-select checkbox group (те же роли)
- Приоритет: Select
  - Обязательное
  - Предпочтительное
  - Опциональное

---

#### 6.3. Условие `exclude_roles`:

**Описание:** Роли пользователей, которые исключаются из правила

**Тип:** `object`

**Структура:**
```json
{
  "value": ["contractor"],
  "priority": "required"
}
```

**`value`** (array of strings, required):
- Возможные значения: те же роли

**`priority`** (string, required):
- По умолчанию: `"required"`

**UI:**
- Заголовок: "Исключенные роли"
- Multi-select checkbox group
- Приоритет: Select
- Валидация: Если есть `notify_roles` → проверка на конфликт

---

#### 6.4. Условие `time_conditions`:

**Описание:** Временные условия (рабочее время, день недели, часовой пояс)

**Тип:** `object`

**Структура:**
```json
{
  "value": {
    "business_hours_only": true,
    "weekdays_only": true,
    "timezone": "America/New_York",
    "time_range": {
      "start": "09:00",
      "end": "17:00"
    }
  },
  "priority": "preferred"
}
```

**`value`** (object, required):

- **`business_hours_only`** (boolean, optional): Только в рабочее время (9:00 - 17:00)
- **`weekdays_only`** (boolean, optional): Только в будние дни
- **`weekends_only`** (boolean, optional): Только в выходные дни
- **`timezone`** (string, optional): Часовой пояс (например, "America/New_York")
- **`time_range`** (object, optional): Временной диапазон
  - **`start`** (string, required): Время начала (формат "HH:mm")
  - **`end`** (string, required): Время окончания (формат "HH:mm")
- **`specific_hours`** (array of integers, optional): Конкретные часы (0-23)
- **`specific_days`** (array of integers, optional): Конкретные дни недели (1-7, где 1 = понедельник)
- **`exclude_holidays`** (boolean, optional): Исключить праздники

**`priority`** (string, required):
- По умолчанию: `"preferred"` (не блокирует правило)

**UI:**
- Заголовок: "Временные условия"
- Checkbox: "Только в рабочее время"
- Checkbox: "Только в будние дни"
- Checkbox: "Только в выходные дни"
- Checkbox: "Исключить праздники"
- Select: "Часовой пояс" (список часовых поясов)
- Временной диапазон:
  - Поле "С": Time picker (HH:mm)
  - Поле "До": Time picker (HH:mm)
- Конкретные часы: Multi-select (0-23)
- Конкретные дни: Multi-select (Пн, Вт, Ср, Чт, Пт, Сб, Вс)
- Приоритет: Select (по умолчанию "Предпочтительное")

---

#### 6.5. Условие `project_conditions`:

**Описание:** Условия проекта (бюджет, статус, тип)

**Тип:** `object`

**Структура:**
```json
{
  "value": {
    "min_budget": 100000,
    "max_budget": 1000000,
    "status": ["active", "planning"],
    "project_type": "commercial"
  },
  "priority": "preferred"
}
```

**`value`** (object, required):

- **`min_budget`** (number, optional): Минимальный бюджет
- **`max_budget`** (number, optional): Максимальный бюджет
- **`status`** (array of strings, optional): Разрешенные статусы
- **`exclude_status`** (array of strings, optional): Исключенные статусы
- **`project_type`** (string, optional): Тип проекта
- **`priority`** (array of strings, optional): Разрешенные приоритеты
- **`min_duration_days`** (number, optional): Минимальная длительность в днях
- **`max_duration_days`** (number, optional): Максимальная длительность в днях

**UI:**
- Заголовок: "Условия проекта"
- Поля для бюджета:
  - "Минимальный бюджет": Number input
  - "Максимальный бюджет": Number input
- Select: "Статусы проекта" (multi-select)
- Select: "Тип проекта" (single select)
- Приоритет: Select

---

#### 6.6. Условие `task_conditions`:

**Описание:** Условия задачи (статус, приоритет, тип)

**Тип:** `object`

**Структура:**
```json
{
  "value": {
    "status": ["in_progress", "blocked"],
    "min_priority": 3,
    "overdue_only": true
  },
  "priority": "preferred"
}
```

**`value`** (object, required):

- **`status`** (array of strings, optional): Разрешенные статусы
- **`exclude_status`** (array of strings, optional): Исключенные статусы
- **`min_priority`** (number, optional): Минимальный приоритет
- **`max_priority`** (number, optional): Максимальный приоритет
- **`is_milestone`** (boolean, optional): Только вехи
- **`has_dependencies`** (boolean, optional): Только задачи с зависимостями
- **`overdue_only`** (boolean, optional): Только просроченные задачи
- **`due_soon_days`** (number, optional): Срок истекает через N дней

**UI:**
- Заголовок: "Условия задачи"
- Select: "Статусы задачи" (multi-select)
- Поля для приоритета:
  - "Минимальный приоритет": Number input
  - "Максимальный приоритет": Number input
- Checkbox: "Только вехи"
- Checkbox: "Только просроченные задачи"
- Поле: "Срок истекает через (дней)": Number input

---

#### UI для conditions:

**Общий интерфейс:**

1. **Строгий режим:**
   - Checkbox: "Строгий режим проверки условий"
   - Tooltip: "Если включен - все условия должны быть выполнены, иначе - хотя бы одно"

2. **Список условий:**
   - Accordion или табы для группировки условий
   - Для каждого условия:
     - Заголовок с названием
     - Чекбокс "Включить условие"
     - Когда включено → показывать форму условия
     - Select "Приоритет условия" (required/preferred/optional)
     - Кнопка "Удалить условие"

3. **Добавление условия:**
   - Кнопка "Добавить условие"
   - Выпадающий список типов условий:
     - Роли для уведомления (notify_roles)
     - Роли пользователей (user_roles)
     - Исключенные роли (exclude_roles)
     - Временные условия (time_conditions)
     - Условия проекта (project_conditions)
     - Условия задачи (task_conditions)

4. **Валидация:**
   - Если есть действие `notify` → обязательно условие `notify_roles`
   - Конфликт между `notify_roles` и `exclude_roles`:
     - Если роль есть в обоих → показать ошибку
     - Нельзя уведомлять роли, которые исключены

---

### 7. `execution_location` (string, optional)

**Описание:** Где выполняется правило

**Возможные значения:**
- `"server"` - На сервере (PHP)
- `"n8n"` - В n8n (автоматизация)
- `"both"` - На сервере и в n8n

**По умолчанию:** `null` (определяется автоматически)

**Логика:**
- Если `"server"` → обрабатывается только на сервере
- Если `"n8n"` → передается в n8n для обработки
- Если `"both"` → обрабатывается на сервере и передается в n8n

**UI:**
- Select: "Место выполнения"
  - Автоматически (null)
  - На сервере
  - В n8n
  - На сервере и в n8n

---

### 8. `comment` (string, optional)

**Описание:** Комментарий к правилу

**Тип:** `string`

**Максимум:** 255 символов

**UI:**
- Textarea: "Комментарий"
- Максимум 255 символов
- Подсчет символов

---

## Валидация на фронтенде

### Общие правила:

1. **`event_type`** - обязательное, формат UPPERCASE с подчеркиваниями
2. **`enabled`** - обязательное, boolean
3. **`severity`** - обязательное, enum
4. **`actions`** - обязательное, минимум 1 действие
5. **Для действия `notify`** - обязательно условие `notify_roles`
6. **Для действия `notify`** - обязательно указать хотя бы один канал
7. **Для действия `create_report`** - если `period: "custom"` → обязательно `custom_period`
8. **Конфликт `notify_roles` и `exclude_roles`** - нельзя уведомлять исключенные роли

### Валидация действий:

```javascript
// Валидация массива actions
function validateActions(actions) {
  if (!actions || actions.length === 0) {
    return { error: "Хотя бы одно действие обязательно" };
  }
  
  for (const action of actions) {
    if (action.type === 'notify') {
      if (!action.channels || action.channels.length === 0) {
        return { error: "Для действия notify необходимо указать хотя бы один канал доставки" };
      }
      
      // Проверка шаблонов для каналов, которые требуют шаблона
      if (action.channels.includes('email')) {
        if (!action.channel_templates || !action.channel_templates.email) {
          // Предупреждение, но не ошибка - можно использовать шаблон по умолчанию
          console.warn("Шаблон для email не указан, будет использован шаблон по умолчанию");
        }
      }
      
      if (action.channels.includes('sms')) {
        if (!action.channel_templates || !action.channel_templates.sms) {
          // Предупреждение, но не ошибка - можно использовать шаблон по умолчанию
          console.warn("Шаблон для sms не указан, будет использован шаблон по умолчанию");
        }
      }
      
      // Валидация соответствия типа шаблона каналу
      if (action.channel_templates) {
        if (action.channel_templates.email) {
          const template = getTemplateById(action.channel_templates.email);
          if (template && template.type !== 'email') {
            return { error: "Шаблон для email должен иметь type='email'" };
          }
        }
        
        if (action.channel_templates.sms) {
          const template = getTemplateById(action.channel_templates.sms);
          if (template && template.type !== 'sms') {
            return { error: "Шаблон для sms должен иметь type='sms'" };
          }
        }
      }
    }
    
    if (action.type === 'create_report') {
      if (action.period === 'custom' && !action.custom_period) {
        return { error: "Для кастомной периодичности необходимо указать custom_period" };
      }
    }
  }
  
  return { valid: true };
}
```

### Валидация условий:

```javascript
// Валидация условий
function validateConditions(conditions, actions) {
  const errors = [];
  
  // Проверка на наличие notify_roles для действия notify
  const hasNotifyAction = actions.some(a => a.type === 'notify');
  if (hasNotifyAction) {
    if (!conditions.notify_roles || !conditions.notify_roles.value || conditions.notify_roles.value.length === 0) {
      errors.push("Для действия notify необходимо указать условие notify_roles");
    }
  }
  
  // Проверка конфликта notify_roles и exclude_roles
  if (conditions.notify_roles && conditions.exclude_roles) {
    const conflict = conditions.notify_roles.value.filter(r => 
      conditions.exclude_roles.value.includes(r)
    );
    if (conflict.length > 0) {
      errors.push(`Нельзя уведомлять роли, которые исключены: ${conflict.join(', ')}`);
    }
  }
  
  return errors.length === 0 ? { valid: true } : { errors };
}
```

---

## Примеры UI компонентов

### Форма создания/редактирования правила:

```vue
<template>
  <form @submit.prevent="handleSubmit">
    <!-- Основные поля -->
    <div class="form-group">
      <label>Тип события *</label>
      <input 
        v-model="rule.event_type" 
        pattern="^[A-Z_]+$"
        placeholder="PROJECT_CREATED"
        required
      />
      <select v-model="selectedEventType" @change="loadEventType">
        <option value="">Выберите тип события</option>
        <option v-for="type in eventTypes" :key="type" :value="type">
          {{ type }}
        </option>
      </select>
    </div>
    
    <div class="form-group">
      <label>
        <input type="checkbox" v-model="rule.enabled" />
        Правило включено
      </label>
    </div>
    
    <div class="form-group">
      <label>Важность события *</label>
      <select v-model="rule.severity" required>
        <option value="important">Важное</option>
        <option value="critical">Критическое</option>
      </select>
    </div>
    
    <div class="form-group">
      <label>Приоритет обработки</label>
      <select v-model="rule.priority">
        <option :value="null">Автоматически (по важности)</option>
        <option value="critical">Критический</option>
        <option value="high">Высокий</option>
        <option value="normal">Обычный</option>
        <option value="low">Низкий</option>
      </select>
      <span v-if="!rule.priority" class="hint">
        Будет установлен: {{ computedPriority }}
      </span>
    </div>
    
    <!-- Действия -->
    <div class="form-group">
      <label>Действия *</label>
      <div v-for="(action, index) in rule.actions" :key="index" class="action-card">
        <div class="action-header">
          <span>{{ getActionTypeLabel(action.type) }}</span>
          <button @click="removeAction(index)" type="button">Удалить</button>
        </div>
        
        <!-- Форма для notify -->
        <div v-if="action.type === 'notify'">
          <label>Каналы доставки *</label>
          <div class="checkbox-group">
            <label v-for="channel in channels" :key="channel">
              <input 
                type="checkbox" 
                :value="channel"
                v-model="action.channels"
                @change="onChannelChange(action)"
              />
              {{ channel }}
            </label>
          </div>
          
          <!-- Выбор шаблона для каждого канала -->
          <div v-for="channel in action.channels" :key="channel">
            <div v-if="channel === 'email' || channel === 'sms'">
              <label>Шаблон для {{ channel }} *</label>
              <select v-model="action.channel_templates[channel]">
                <option :value="null">Использовать шаблон по умолчанию</option>
                <option 
                  v-for="template in getTemplatesForChannel(channel)" 
                  :key="template.id" 
                  :value="template.id"
                >
                  {{ template.name }} [{{ template.category }}]
                  <span v-if="template.parent_id">
                    [Базовый: {{ getParentTemplateName(template.parent_id) }}]
                  </span>
                </option>
              </select>
            </div>
          </div>
          
          <label>
            <input type="checkbox" v-model="action.store_for_dashboard" />
            Хранить для дашборда
          </label>
        </div>
        
        <!-- Форма для create_report -->
        <div v-if="action.type === 'create_report'">
          <label>Периодичность *</label>
          <select v-model="action.period" required>
            <option value="daily">Ежедневно</option>
            <option value="weekly">Еженедельно</option>
            <option value="monthly">Ежемесячно</option>
            <option value="quarterly">Ежеквартально</option>
            <option value="custom">Произвольная</option>
          </select>
          
          <div v-if="action.period === 'custom'">
            <label>Кастомная периодичность *</label>
            <input 
              v-model="action.custom_period" 
              placeholder="P7D (7 дней)"
              pattern="^P\d+[DM]$"
            />
          </div>
          
          <label>
            <input type="checkbox" v-model="action.store_for_dashboard" />
            Хранить для дашборда
          </label>
          
          <label>Получатели</label>
          <div class="checkbox-group">
            <label v-for="role in roles" :key="role">
              <input 
                type="checkbox" 
                :value="role"
                v-model="action.recipients"
              />
              {{ role }}
            </label>
          </div>
        </div>
        
        <!-- Форма для log_only -->
        <div v-if="action.type === 'log_only'">
          <label>
            <input type="checkbox" v-model="action.store_for_dashboard" />
            Хранить для дашборда
          </label>
        </div>
      </div>
      
      <button type="button" @click="showAddActionModal = true">
        Добавить действие
      </button>
    </div>
    
    <!-- Условия -->
    <div class="form-group">
      <label>Условия</label>
      
      <label>
        <input type="checkbox" v-model="rule.conditions.strict_mode" />
        Строгий режим проверки условий
      </label>
      
      <div v-for="(condition, key) in rule.conditions" :key="key">
        <!-- Форма для notify_roles -->
        <div v-if="key === 'notify_roles'">
          <h3>Роли для уведомления *</h3>
          <div class="checkbox-group">
            <label v-for="role in roles" :key="role">
              <input 
                type="checkbox" 
                :value="role"
                v-model="condition.value"
              />
              {{ role }}
            </label>
          </div>
          <select v-model="condition.priority">
            <option value="required">Обязательное</option>
          </select>
        </div>
        
        <!-- Другие условия... -->
      </div>
      
      <button type="button" @click="showAddConditionModal = true">
        Добавить условие
      </button>
    </div>
    
    <!-- Место выполнения -->
    <div class="form-group">
      <label>Место выполнения</label>
      <select v-model="rule.execution_location">
        <option :value="null">Автоматически</option>
        <option value="server">На сервере</option>
        <option value="n8n">В n8n</option>
        <option value="both">На сервере и в n8n</option>
      </select>
    </div>
    
    <!-- Комментарий -->
    <div class="form-group">
      <label>Комментарий</label>
      <textarea 
        v-model="rule.comment" 
        maxlength="255"
        :length="rule.comment?.length || 0"
      ></textarea>
    </div>
    
    <!-- Кнопки -->
    <div class="form-actions">
      <button type="submit">Сохранить</button>
      <button type="button" @click="cancel">Отмена</button>
    </div>
  </form>
</template>

<script>
export default {
  data() {
    return {
      rule: {
        event_type: '',
        enabled: true,
        severity: 'important',
        priority: null,
        actions: [],
        conditions: {
          strict_mode: false
        },
        execution_location: null,
        comment: ''
      },
      channels: ['email', 'sms', 'push', 'webhook', 'slack'],
      roles: ['admin', 'project_manager', 'contractor', 'architect', 'viewer', 'guest'],
      templates: {
        email: [],
        sms: []
      }
    }
  },
  methods: {
    // Добавление действия notify
    addNotifyAction() {
      this.rule.actions.push({
        type: 'notify',
        channels: [],
        channel_templates: {}, // Инициализируем как пустой объект
        store_for_dashboard: true
      });
    },
    
    // Загрузка шаблонов для канала
    async loadTemplatesForChannel(channel) {
      if (channel !== 'email' && channel !== 'sms') {
        return; // Шаблоны требуются только для email и sms
      }
      
      try {
        const response = await fetch(
          `/api/v1/admin/message-templates?type=${channel}`,
          {
            headers: {
              'Authorization': `Bearer ${this.getToken()}`
            }
          }
        );
        const data = await response.json();
        
        if (data.status === 'success') {
          this.templates[channel] = data.data.templates.filter(t => t.is_active);
        }
      } catch (error) {
        console.error(`Failed to load templates for ${channel}:`, error);
      }
    },
    
    // Получить шаблоны для канала
    getTemplatesForChannel(channel) {
      return this.templates[channel] || [];
    },
    
    // Получить название родительского шаблона
    getParentTemplateName(parentId) {
      // Ищем в загруженных шаблонах
      for (const templates of Object.values(this.templates)) {
        const parent = templates.find(t => t.id === parentId);
        if (parent) return parent.name;
      }
      return 'Неизвестный';
    },
    
    // Инициализация channel_templates при выборе канала
    onChannelChange(action) {
      // Инициализируем channel_templates если его нет
      if (!action.channel_templates) {
        action.channel_templates = {};
      }
      
      // Загружаем шаблоны для новых каналов, которые требуют шаблона
      action.channels.forEach(channel => {
        if ((channel === 'email' || channel === 'sms') && !this.templates[channel]?.length) {
          this.loadTemplatesForChannel(channel);
        }
      });
      
      // Удаляем шаблоны для каналов, которые были убраны
      Object.keys(action.channel_templates).forEach(channel => {
        if (!action.channels.includes(channel)) {
          delete action.channel_templates[channel];
        }
      });
    },
    
    // Валидация действия notify
    validateNotifyAction(action) {
      const errors = [];
      
      if (!action.channels || action.channels.length === 0) {
        errors.push('Необходимо выбрать хотя бы один канал доставки');
      }
      
      // Проверка шаблонов для email и sms
      if (action.channels.includes('email')) {
        if (!action.channel_templates?.email) {
          // Предупреждение, но не ошибка
          console.warn('Шаблон для email не указан');
        } else {
          const template = this.templates.email.find(t => t.id === action.channel_templates.email);
          if (template && template.type !== 'email') {
            errors.push('Шаблон для email должен иметь type="email"');
          }
        }
      }
      
      if (action.channels.includes('sms')) {
        if (!action.channel_templates?.sms) {
          // Предупреждение, но не ошибка
          console.warn('Шаблон для sms не указан');
        } else {
          const template = this.templates.sms.find(t => t.id === action.channel_templates.sms);
          if (template && template.type !== 'sms') {
            errors.push('Шаблон для sms должен иметь type="sms"');
          }
        }
      }
      
      return errors;
    },
    
    handleSubmit() {
      // Валидация всех действий
      const errors = [];
      this.rule.actions.forEach((action, index) => {
        if (action.type === 'notify') {
          const actionErrors = this.validateNotifyAction(action);
          if (actionErrors.length > 0) {
            errors.push(`Действие ${index + 1}: ${actionErrors.join(', ')}`);
          }
        }
      });
      
      if (errors.length > 0) {
        alert(errors.join('\n'));
        return;
      }
      
      // Отправка данных
      this.submitRule();
    },
    
    getToken() {
      // Получение токена из localStorage или другого места
      return localStorage.getItem('auth_token');
    }
  },
  
  mounted() {
    // При монтировании загружаем шаблоны для всех каналов
    this.loadTemplatesForChannel('email');
    this.loadTemplatesForChannel('sms');
  }
}
</script>
```

---

## API Endpoints для фронтенда

### GET `/api/v1/admin/event-rules`
Получить все правила

**Response:**
```json
{
  "error_code": 0,
  "status": "success",
  "data": {
    "rules": [
      {
        "event_type": "PROJECT_CREATED",
        "enabled": true,
        "actions": [...],
        "severity": "important",
        "priority": "high",
        "conditions": {...},
        "execution_location": "server",
        "comment": "...",
        "updated_at": "2025-01-15 10:00:00"
      }
    ]
  }
}
```

### GET `/api/v1/admin/event-rules/{event_type}`
Получить конкретное правило

### POST `/api/v1/admin/event-rules`
Создать правило

**Request Body:** Полная структура правила (JSON)

### PUT `/api/v1/admin/event-rules/{event_type}`
Обновить правило

**Request Body:** Полная структура правила (JSON)

### DELETE `/api/v1/admin/event-rules/{event_type}`
Удалить правило

### GET `/api/v1/admin/event-rules/conditions`
Получить доступные типы условий

### GET `/api/v1/admin/event-rules/actions`
Получить доступные типы действий

### GET `/api/v1/admin/message-templates`
Получить все шаблоны (с фильтрацией по `type`, `category`)

**Query Parameters:**
- `type` (optional): Фильтр по типу (`email` или `sms`)
- `category` (optional): Фильтр по категории (`system` или `custom`)

**Response:**
```json
{
  "error_code": 0,
  "status": "success",
  "data": {
    "templates": [
      {
        "id": 123,
        "name": "Project Created Email",
        "type": "email",
        "category": "system",
        "subject": "New Project: {{project_name}}",
        "body": "<h2>New Project</h2><p>{{project_name}}</p>",
        "variables": {
          "project_name": "Project name",
          "created_by": "Creator name"
        },
        "is_editable": true,
        "is_active": true,
        "parent_id": null,
        "created_by": 1,
        "created_at": "2025-01-15 10:00:00",
        "updated_at": "2025-01-15 10:00:00"
      }
    ]
  }
}
```

### GET `/api/v1/admin/message-templates/by-event/{event_type}`
**УСТАРЕЛО** - больше не используется, так как шаблоны не привязаны к `event_type`

**Вместо этого используйте:** `GET /api/v1/admin/message-templates?type={channel}`

**Структура связей между таблицами:**

1. **`fw_event_rules` → `fw_message_templates`:**
   - В действии правила есть поле `channel_templates` → содержит ссылки на шаблоны по каналам
   - Структура: `{"email": 123, "sms": 124}` где значения - это `fw_message_templates.id`
   - Это связь: правило назначает шаблоны для каждого канала доставки отдельно

2. **`fw_message_templates` (упрощенная структура):**
   - Поле `event_type` **УБРАНО** - шаблон больше не привязан к событию
   - Поле `type` (enum: 'email', 'sms') - определяет тип шаблона и соответствующий канал доставки
   - Шаблон может использоваться в любом правиле, где есть действие `notify` с соответствующим каналом
   - Шаблон универсален - не привязан к конкретному событию

3. **Рекурсивная связь в `fw_message_templates`:**
   - Поле `parent_id` (или `base_template_id`) - ссылается на другой шаблон в той же таблице (`fw_message_templates.id`)
   - Используется для наследования базового шаблона (для кастомизации системных шаблонов)
   - Если `parent_id` указан → шаблон наследует структуру от базового

**Логика работы с шаблонами:**
- При создании/редактировании правила с действием `notify`:
  1. Выбираем каналы доставки (email, sms, push, webhook, slack)
  2. Для каждого канала, который требует шаблона (email, sms):
     - Загружаем шаблоны с `type` = канал: `GET /api/v1/admin/message-templates?type={channel}`
     - Показываем список доступных шаблонов
     - Пользователь выбирает шаблон для этого канала
     - Сохраняем `template_id` в `channel_templates[channel]`
  3. Для каналов `push`, `webhook`, `slack` - шаблон не требуется
  4. Если шаблон имеет `parent_id` → показываем иерархию наследования

**Преимущества новой структуры:**
- ✅ Разные шаблоны для разных каналов в одном правиле
- ✅ Шаблон не привязан к событию - можно использовать в разных правилах
- ✅ Ясность - при редактировании правила видно, какой шаблон для какого канала
- ✅ Гибкость - можно не указывать шаблон (используется по умолчанию)
- ✅ Валидация - тип шаблона должен соответствовать каналу

---

## Примеры данных

### Пример 1: Правило для создания проекта

```json
{
  "event_type": "PROJECT_CREATED",
  "enabled": true,
  "severity": "important",
  "priority": "high",
  "actions": [
    {
      "type": "notify",
      "channels": ["email"],
      "channel_templates": {
        "email": 123
      },
      "store_for_dashboard": true
    }
  ],
  "conditions": {
    "strict_mode": false,
    "notify_roles": {
      "value": ["admin", "project_manager"],
      "priority": "required"
    }
  },
  "execution_location": "server",
  "comment": "Уведомлять админов и менеджеров о создании проекта"
}
```

### Пример 2: Правило с отчетами

```json
{
  "event_type": "DAILY_REPORT",
  "enabled": true,
  "severity": "important",
  "priority": "low",
  "actions": [
    {
      "type": "create_report",
      "period": "daily",
      "store_for_dashboard": true,
      "recipients": ["admin", "project_manager"]
    }
  ],
  "conditions": {
    "strict_mode": false
  },
  "execution_location": "server",
  "comment": "Ежедневный отчет для админов и менеджеров"
}
```

### Пример 3: Правило с временными условиями

```json
{
  "event_type": "TASK_OVERDUE",
  "enabled": true,
  "severity": "important",
  "priority": "high",
  "actions": [
    {
      "type": "notify",
      "channels": ["email", "sms"],
      "channel_templates": {
        "email": 125,
        "sms": 126
      },
      "store_for_dashboard": true
    }
  ],
  "conditions": {
    "strict_mode": false,
    "notify_roles": {
      "value": ["project_manager"],
      "priority": "required"
    },
    "time_conditions": {
      "value": {
        "business_hours_only": true,
        "timezone": "America/New_York"
      },
      "priority": "preferred"
    }
  },
  "execution_location": "server",
  "comment": "Уведомлять менеджеров о просроченных задачах в рабочее время"
}
```

---

## Чек-лист для разработки фронтенда

- [ ] Форма создания/редактирования правила
- [ ] Валидация event_type (формат UPPERCASE)
- [ ] Выбор severity (radio или select)
- [ ] Выбор priority с автоматическим вычислением
- [ ] Компонент для массива actions (добавление/удаление/редактирование)
- [ ] Форма для действия notify (каналы, шаблон, дашборд)
- [ ] Форма для действия create_report (периодичность, получатели)
- [ ] Форма для действия log_only
- [ ] Компонент для условий (добавление/удаление)
- [ ] Форма для условия notify_roles
- [ ] Форма для условия user_roles
- [ ] Форма для условия exclude_roles
- [ ] Форма для условия time_conditions
- [ ] Форма для условия project_conditions
- [ ] Форма для условия task_conditions
- [ ] Валидация конфликтов (notify_roles vs exclude_roles)
- [ ] Валидация обязательных полей (notify_roles для notify)
- [ ] Загрузка шаблонов сообщений по типу (`type` = канал доставки)
- [ ] Выбор шаблона для каждого канала доставки (email, sms)
- [ ] Валидация соответствия типа шаблона каналу (email → email, sms → sms)
- [ ] Отображение иерархии шаблонов (если есть parent_id) при выборе
- [ ] Инициализация `channel_templates` как объекта при добавлении действия notify
- [ ] Обработчик изменения каналов для загрузки шаблонов и очистки неиспользуемых
- [ ] Метод `loadTemplatesForChannel(channel)` для загрузки шаблонов по типу
- [ ] Метод `getTemplatesForChannel(channel)` для получения шаблонов из кэша
- [ ] Метод `onChannelChange(action)` для обработки изменения каналов
- [ ] Загрузка доступных типов условий из API
- [ ] Загрузка доступных типов действий из API
- [ ] Отображение списка правил в таблице
- [ ] Включение/выключение правила (toggle)
- [ ] Удаление правила с подтверждением
- [ ] Копирование правила
- [ ] Предпросмотр правила перед сохранением

---

## Примечания

1. **Обратная совместимость:** Старая структура actions (массив строк) все еще поддерживается, но рекомендуется использовать новую структуру (массив объектов)

2. **Автоматическое вычисление priority:** Если priority не указан, он вычисляется из severity:
   - `critical` → `critical`
   - `important` → `high`

3. **Приоритеты условий:** Используются для определения, насколько строго проверять условие. `required` блокирует правило, `preferred` и `optional` нет.

4. **Хранение для дашборда:** Определяет, будет ли событие отображаться на дашборде пользователя.

5. **Execution location:** Определяет, где выполняется правило. Если не указан, определяется автоматически на основе типа действия.

6. **Шаблоны сообщений:** 
   - **Связь правил → шаблоны:** В действии правила поле `channel_templates` содержит ссылки на шаблоны для каждого канала
   - **Структура шаблонов:** 
     - Поле `event_type` **УБРАНО** из таблицы `fw_message_templates`
     - Поле `type` (email/sms) определяет тип шаблона и соответствующий канал доставки
     - Шаблон универсален - может использоваться в любом правиле с соответствующим каналом
   - **Рекурсивная связь шаблонов:** В таблице `fw_message_templates` есть поле `parent_id`, которое ссылается на другой шаблон в той же таблице
   - При создании/редактировании правила:
     - Для каждого канала (email, sms) выбирается шаблон вручную
     - Загружаются шаблоны с `type` = канал: `GET /api/v1/admin/message-templates?type={channel}`
     - Валидация: тип шаблона должен соответствовать каналу
     - Можно не указывать шаблон (используется по умолчанию)

7. **Валидация:** Все валидации выполняются и на фронтенде, и на бэкенде. Фронтенд показывает ошибки сразу, бэкенд проверяет при сохранении.

---

## Дополнительные ресурсы

- API документация: `/api/v1/swagger.json`
- Тестовые данные: Примеры правил выше
- Типы событий: Загружаются из API `/api/v1/admin/event-rules` (event_type из существующих правил)

---

**Версия документа:** 1.0  
**Дата:** 2025-01-15  
**Статус:** Готово для разработки фронтенда

