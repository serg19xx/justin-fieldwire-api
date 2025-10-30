# Предложение по реструктуризации связей Правила ↔ Шаблоны

## Проблема текущей структуры

### Текущая структура:
```
fw_event_rules (event_type)
    ↓
fw_message_templates (event_type - FK к правилу)
```

**Проблемы:**
1. Шаблон привязан к `event_type` (всему правилу), а не к конкретному действию/каналу
2. В правиле может быть несколько каналов доставки (email, sms), но шаблон один
3. Нет возможности указать: "этот шаблон для email, а этот для sms"
4. При редактировании шаблона мы не знаем, какие каналы используются в правиле
5. Нельзя назначить разные шаблоны для разных каналов

## Предлагаемое решение

### Новая структура:
```
fw_event_rules
    ↓ actions[].type = "notify"
    ↓ actions[].channels = ["email", "sms"]
    ↓ actions[].template_id → fw_message_templates.id (для каждого канала отдельно)
    
fw_message_templates
    - НЕТ поля event_type (убрать FK)
    - Есть поле type (email/sms) - для указания типа шаблона
    - Есть поле parent_id - для наследования
```

### Структура действия notify:
```json
{
  "type": "notify",
  "channels": ["email", "sms"],
  "templates": {
    "email": 123,  // ID шаблона для email
    "sms": 124     // ID шаблона для sms
  },
  "store_for_dashboard": true
}
```

Или более простой вариант:

```json
{
  "type": "notify",
  "channels": ["email", "sms"],
  "channel_templates": [
    {
      "channel": "email",
      "template_id": 123
    },
    {
      "channel": "sms",
      "template_id": 124
    }
  ],
  "store_for_dashboard": true
}
```

## Преимущества новой структуры

### 1. Гибкость:
- Можно назначить разные шаблоны для разных каналов
- Можно использовать один шаблон для нескольких каналов (если типы совпадают)
- Можно не указывать шаблон для канала (используется шаблон по умолчанию)

### 2. Ясность:
- При редактировании правила видно, какой шаблон для какого канала
- При редактировании шаблона не нужно думать о `event_type`
- Шаблон описывает себя сам через `type` (email/sms)

### 3. Логика:
- Шаблон привязан к действию, а не к правилу
- Можно использовать один шаблон в разных правилах
- Легче понять, где используется шаблон (поиск по `template_id` в действиях)

## Структура таблиц

### fw_message_templates (обновленная):
```sql
CREATE TABLE `fw_message_templates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `type` ENUM('sms', 'email') NOT NULL COMMENT 'Тип шаблона (для какого канала)',
  `category` ENUM('system', 'custom') NOT NULL DEFAULT 'custom',
  -- УБРАТЬ: event_type VARCHAR(48) - больше не нужен!
  `subject` VARCHAR(255) DEFAULT NULL COMMENT 'Тема письма (только для email)',
  `body` TEXT NOT NULL COMMENT 'Тело сообщения с переменными',
  `variables` JSON NULL COMMENT 'Описание доступных переменных',
  `is_active` TINYINT(1) DEFAULT 1,
  `parent_id` INT NULL COMMENT 'Рекурсивная ссылка на базовый шаблон',
  `created_by` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_editable` TINYINT(1) DEFAULT 1,
  
  FOREIGN KEY (parent_id) REFERENCES fw_message_templates(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES fw_users(id) ON DELETE SET NULL,
  
  KEY idx_type (type),
  KEY idx_category (category),
  KEY idx_is_active (is_active)
);
```

### fw_event_rules (действия):
```json
{
  "event_type": "PROJECT_CREATED",
  "actions": [
    {
      "type": "notify",
      "channels": ["email", "sms"],
      "channel_templates": {
        "email": 123,  // ID шаблона типа email
        "sms": 124     // ID шаблона типа sms
      },
      "store_for_dashboard": true
    }
  ]
}
```

## Логика работы

### При создании/редактировании правила:

1. **Добавляем действие `notify`:**
   - Выбираем каналы: email, sms, push, webhook, slack
   - Для каждого канала, который требует шаблона (email, sms):
     - Показываем список доступных шаблонов с `type` = канал
     - Выбираем шаблон для этого канала
     - Сохраняем `template_id` в `channel_templates[channel]`

2. **Валидация:**
   - Если канал `email` → шаблон должен быть `type = 'email'`
   - Если канал `sms` → шаблон должен быть `type = 'sms'`
   - Если канал `push`, `webhook`, `slack` → шаблон не требуется (или используется другой механизм)

### При создании/редактировании шаблона:

1. **Указываем тип шаблона:**
   - `type`: `email` или `sms`
   - Больше не нужно указывать `event_type`

2. **Шаблон становится универсальным:**
   - Можно использовать в любом правиле, где есть действие `notify` с соответствующим каналом
   - При поиске правил, использующих шаблон → ищем по `template_id` в действиях

### При использовании шаблона:

1. **Правило срабатывает:**
   - Для каждого канала в `channels`:
     - Если канал требует шаблона (email, sms):
       - Берем `template_id` из `channel_templates[channel]`
       - Загружаем шаблон
       - Проверяем, что `template.type` = канал
       - Используем шаблон для отправки

2. **Если шаблон не указан:**
   - Используется шаблон по умолчанию (если есть)
   - Или отправляется без шаблона (сырое сообщение)

## Структура JSON для действия notify

### Вариант 1: Объект channel_templates (рекомендуется)

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

**Преимущества:**
- Простая структура
- Легко получить шаблон для канала: `channel_templates[channel]`
- Необязательные поля (можно не указывать шаблон для канала)

### Вариант 2: Массив channel_templates

```json
{
  "type": "notify",
  "channels": ["email", "sms"],
  "channel_templates": [
    {
      "channel": "email",
      "template_id": 123
    },
    {
      "channel": "sms",
      "template_id": 124
    }
  ],
  "store_for_dashboard": true
}
```

**Преимущества:**
- Можно добавить дополнительные поля (например, `enabled`, `priority`)
- Более расширяемая структура

**Недостатки:**
- Более сложная структура
- Нужно искать нужный канал в массиве

## Рекомендация

**Использовать Вариант 1 (объект channel_templates):**

```json
{
  "type": "notify",
  "channels": ["email", "sms", "push"],
  "channel_templates": {
    "email": 123,  // ID шаблона type='email'
    "sms": 124     // ID шаблона type='sms'
    // push не требует шаблона
  },
  "store_for_dashboard": true
}
```

**Логика:**
- `channel_templates` содержит только те каналы, которые требуют шаблона
- Для `email` и `sms` - обязателен шаблон
- Для `push`, `webhook`, `slack` - шаблон не требуется (или используется другой механизм)

## Миграция

### Шаг 1: Обновить структуру таблицы fw_message_templates

```sql
-- Удалить FK и поле event_type
ALTER TABLE fw_message_templates
DROP FOREIGN KEY fw_message_templates_ibfk_1,  -- Название FK нужно проверить
DROP COLUMN event_type,
DROP INDEX idx_event_type;
```

### Шаг 2: Обновить структуру действий в правилах

- Добавить поле `channel_templates` в действие `notify`
- При загрузке старых правил:
  - Если есть `event_type` в шаблоне → найти шаблоны по `event_type` и `type`
  - Назначить их в `channel_templates`

### Шаг 3: Обновить API

- Убрать фильтрацию по `event_type` при получении шаблонов
- Добавить фильтрацию по `type` (email/sms)
- При создании/обновлении правила валидировать, что `template.type` = канал

## Выводы

**Рекомендация: Принять новую структуру**

1. ✅ Убрать `event_type` из `fw_message_templates`
2. ✅ Добавить `channel_templates` в действие `notify`
3. ✅ Шаблон привязан к типу (email/sms), а не к событию
4. ✅ Правило назначает шаблоны для каждого канала отдельно
5. ✅ Больше гибкости и ясности

**Это правильное решение!**

