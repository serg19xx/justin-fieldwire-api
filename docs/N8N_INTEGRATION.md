# Интеграция с N8N

## Обзор

Система логирования событий интегрирована с n8n для автоматизации рабочих процессов. Поддерживаются два основных сценария использования:

1. **Ручные триггеры** - webhook от n8n при нажатии кнопок или изменении временных диапазонов
2. **Автоматизированные процессы** - сбор данных и отправка отчетов по расписанию

## API Эндпоинты для N8N

### 1. Ручной триггер (Manual Trigger Webhook)

**Эндпоинт:** `POST /api/v1/n8n/webhook/manual-trigger`

**Назначение:** Вызывается из n8n при ручных действиях пользователя (нажатие кнопки, изменение диапазона времени)

**Тело запроса:**
```json
{
  "trigger_type": "button_click",
  "entity_type": "task",
  "entity_id": 123,
  "action": "status_change",
  "before_data": {"status": "pending"},
  "after_data": {"status": "completed"},
  "changed_fields": ["status", "updated_at"],
  "user_id": 456,
  "workflow_id": "workflow_123",
  "correlation_id": "optional-correlation-id"
}
```

**Параметры:**
- `trigger_type` - тип триггера (`button_click`, `time_range_change`, `manual_trigger`)
- `entity_type` - тип сущности (`task`, `project`, `user`)
- `entity_id` - ID сущности
- `action` - выполняемое действие
- `before_data` - данные до изменения (опционально)
- `after_data` - данные после изменения (опционально)
- `changed_fields` - список измененных полей
- `user_id` - ID пользователя (опционально)
- `workflow_id` - ID workflow в n8n (опционально)
- `correlation_id` - корреляционный ID для связывания событий (опционально)

**Ответ:**
```json
{
  "error_code": 0,
  "status": "success",
  "message": "Webhook processed successfully",
  "data": {
    "event_log_id": 789,
    "trigger_type": "button_click",
    "entity_type": "task",
    "entity_id": 123,
    "action": "status_change",
    "event_type": "STATUS_CHANGED",
    "timestamp": "2025-09-10T17:22:48+02:00",
    "workflow_id": "workflow_123",
    "correlation_id": "248c7220-ba6a-4bc6-b466-852aa17f3d39"
  }
}
```

### 2. Автоматизированный сбор данных

**Эндпоинт:** `GET /api/v1/n8n/scheduled/data-collection`

**Назначение:** Вызывается n8n по расписанию для сбора данных и генерации отчетов

**Параметры запроса:**
- `report_type` - тип отчета (обязательный)
- `date_from` - дата начала (YYYY-MM-DD, по умолчанию вчера)
- `date_to` - дата окончания (YYYY-MM-DD, по умолчанию сегодня)
- `manager_level` - уровень руководителя (`team`, `department`, `company`)
- `format` - формат вывода (`json`, `csv`, `pdf`)

**Типы отчетов:**
- `daily_summary` - ежедневная сводка
- `weekly_report` - еженедельный отчет
- `monthly_report` - ежемесячный отчет
- `task_status` - отчет по статусам задач
- `project_progress` - отчет по прогрессу проектов
- `user_activity` - отчет по активности пользователей

**Пример запроса:**
```bash
GET /api/v1/n8n/scheduled/data-collection?report_type=daily_summary&date_from=2025-09-01&date_to=2025-09-10&manager_level=team
```

**Ответ:**
```json
{
  "error_code": 0,
  "status": "success",
  "message": "Data collected successfully",
  "data": {
    "report_type": "daily_summary",
    "date_range": {
      "from": "2025-09-01",
      "to": "2025-09-10"
    },
    "manager_level": "team",
    "format": "json",
    "summary": {
      "total_events": 15,
      "date_range": {
        "from": "2025-09-01",
        "to": "2025-09-10"
      },
      "manager_level": "team"
    },
    "details": [
      {
        "event_type": "STATUS_CHANGED",
        "count": 8,
        "severity": "critical",
        "date": "2025-09-10"
      },
      {
        "event_type": "TASK_CREATED",
        "count": 5,
        "severity": "important",
        "date": "2025-09-10"
      }
    ],
    "generated_at": "2025-09-10T17:23:00+02:00"
  }
}
```

### 3. Отслеживание статуса workflow

**Эндпоинт:** `GET /api/v1/n8n/workflow/status`

**Назначение:** Получение статуса выполнения workflow

**Параметры запроса:**
- `correlation_id` - корреляционный ID (опционально)
- `workflow_id` - ID workflow в n8n (опционально)

**Пример запроса:**
```bash
GET /api/v1/n8n/workflow/status?correlation_id=248c7220-ba6a-4bc6-b466-852aa17f3d39
```

**Ответ:**
```json
{
  "error_code": 0,
  "status": "success",
  "message": "Workflow status retrieved successfully",
  "data": {
    "workflow_id": null,
    "correlation_id": "248c7220-ba6a-4bc6-b466-852aa17f3d39",
    "status": "pending",
    "events": [...],
    "total_events": 3
  }
}
```

**Статусы workflow:**
- `pending` - ожидает выполнения
- `running` - выполняется
- `completed` - завершен успешно
- `failed` - завершен с ошибкой

## Сценарии использования в N8N

### Сценарий 1: Ручной триггер при изменении статуса задачи

1. **В приложении:** Пользователь нажимает кнопку "Завершить задачу"
2. **N8N workflow:** Получает webhook с данными о изменении
3. **Действия в N8N:**
   - Отправка уведомления менеджеру
   - Обновление статуса в внешней системе
   - Создание записи в календаре
   - Отправка email уведомления

**Пример N8N workflow:**
```json
{
  "nodes": [
    {
      "name": "Webhook",
      "type": "n8n-nodes-base.webhook",
      "parameters": {
        "path": "task-status-change",
        "httpMethod": "POST"
      }
    },
    {
      "name": "HTTP Request to API",
      "type": "n8n-nodes-base.httpRequest",
      "parameters": {
        "url": "http://localhost:8080/api/v1/n8n/webhook/manual-trigger",
        "method": "POST",
        "body": {
          "trigger_type": "button_click",
          "entity_type": "task",
          "entity_id": "={{ $json.task_id }}",
          "action": "status_change",
          "before_data": "={{ $json.before_data }}",
          "after_data": "={{ $json.after_data }}",
          "changed_fields": ["status", "updated_at"],
          "user_id": "={{ $json.user_id }}",
          "workflow_id": "task-status-workflow"
        }
      }
    },
    {
      "name": "Send Notification",
      "type": "n8n-nodes-base.slack",
      "parameters": {
        "channel": "#notifications",
        "text": "Task {{ $json.entity_id }} status changed to {{ $json.after_data.status }}"
      }
    }
  ]
}
```

### Сценарий 2: Автоматизированный ежедневный отчет

1. **N8N Cron Trigger:** Запускается каждый день в 9:00
2. **HTTP Request:** Вызывает API для сбора данных
3. **Data Processing:** Обрабатывает данные отчета
4. **Email/Slack:** Отправляет отчет руководителям

**Пример N8N workflow:**
```json
{
  "nodes": [
    {
      "name": "Cron Trigger",
      "type": "n8n-nodes-base.cron",
      "parameters": {
        "rule": {
          "interval": [
            {
              "field": "cronExpression",
              "expression": "0 9 * * *"
            }
          ]
        }
      }
    },
    {
      "name": "Get Daily Report",
      "type": "n8n-nodes-base.httpRequest",
      "parameters": {
        "url": "http://localhost:8080/api/v1/n8n/scheduled/data-collection",
        "method": "GET",
        "qs": {
          "report_type": "daily_summary",
          "date_from": "={{ $now.minus({days: 1}).toFormat('yyyy-MM-dd') }}",
          "date_to": "={{ $now.minus({days: 1}).toFormat('yyyy-MM-dd') }}",
          "manager_level": "team"
        }
      }
    },
    {
      "name": "Format Report",
      "type": "n8n-nodes-base.function",
      "parameters": {
        "functionCode": "// Format the report data\nconst reportData = $input.first().json.data;\nconst summary = reportData.summary;\nconst details = reportData.details;\n\nlet reportText = `📊 Daily Summary Report\\n`;\nreportText += `📅 Date: ${reportData.date_range.from}\\n`;\nreportText += `👥 Manager Level: ${reportData.manager_level}\\n`;\nreportText += `📈 Total Events: ${summary.total_events}\\n\\n`;\n\nreportText += `📋 Event Details:\\n`;\ndetails.forEach(event => {\n  reportText += `• ${event.event_type}: ${event.count} (${event.severity})\\n`;\n});\n\nreturn {\n  reportText: reportText,\n  reportData: reportData\n};"
      }
    },
    {
      "name": "Send to Managers",
      "type": "n8n-nodes-base.emailSend",
      "parameters": {
        "toEmail": "managers@company.com",
        "subject": "Daily Summary Report - {{ $now.minus({days: 1}).toFormat('yyyy-MM-dd') }}",
        "text": "={{ $json.reportText }}"
      }
    }
  ]
}
```

## Маппинг типов событий

Система автоматически определяет тип события на основе триггера и действия:

| Trigger Type | Action | Event Type |
|--------------|--------|------------|
| button_click | status_change | STATUS_CHANGED |
| button_click | assign_task | ASSIGNEES_CHANGED |
| button_click | schedule_change | SCHEDULE_CHANGED |
| button_click | create_task | TASK_CREATED |
| button_click | delete_task | TASK_DELETED |
| button_click | publish_task | TASK_PUBLISHED |
| time_range_change | schedule_update | SCHEDULE_CHANGED |
| time_range_change | deadline_change | SCHEDULE_CHANGED |
| manual_trigger | report_generation | REPORT_GENERATED |
| manual_trigger | notification_send | NOTIFICATION_SENT |
| manual_trigger | data_export | DATA_EXPORTED |

## Обработка ошибок

### В N8N Workflow

```json
{
  "name": "Error Handler",
  "type": "n8n-nodes-base.function",
  "parameters": {
    "functionCode": "// Handle errors from API calls\nif ($input.first().json.error_code !== 0) {\n  throw new Error(`API Error: ${$input.first().json.message}`);\n}\nreturn $input.first().json;"
  }
}
```

### В API

Все ошибки логируются в `logs/app.log` с соответствующими уровнями:
- `INFO` - успешные операции
- `WARNING` - предупреждения
- `ERROR` - ошибки

## Мониторинг и отладка

### Просмотр логов в реальном времени

```bash
tail -f logs/app.log | grep -i "n8n\|webhook"
```

### Проверка статуса workflow

```bash
curl "http://localhost:8080/api/v1/n8n/workflow/status?correlation_id=YOUR_CORRELATION_ID"
```

### Просмотр pending outbox событий

```bash
curl "http://localhost:8080/api/v1/event-logs/outbox/pending"
```

## Лучшие практики

1. **Используйте correlation_id** - для связывания связанных событий в workflow
2. **Обрабатывайте ошибки** - всегда проверяйте error_code в ответах API
3. **Логируйте в N8N** - используйте узлы для логирования важных операций
4. **Мониторьте производительность** - следите за временем выполнения workflow
5. **Используйте retry логику** - для критически важных операций
6. **Тестируйте workflow** - перед развертыванием в production

## Примеры интеграции

См. файлы в директории `examples/n8n/` для полных примеров N8N workflow.
