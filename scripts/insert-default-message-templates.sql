-- Системные шаблоны сообщений по умолчанию
INSERT INTO fw_message_templates (name, type, category, event_type, subject, body, variables, is_editable) VALUES

-- SMS шаблоны
('project_created_sms', 'sms', 'system', 'PROJECT_CREATED', NULL, 
 'Новый проект "{{project_name}}" создан пользователем {{user_name}}. Бюджет: {{project_budget}}',
 '{"project_name": "Название проекта", "user_name": "Имя пользователя", "project_budget": "Бюджет проекта"}',
 TRUE),

('task_overdue_sms', 'sms', 'system', 'TASK_OVERDUE', NULL,
 'Задача "{{task_name}}" просрочена на {{overdue_days}} дней. Проект: {{project_name}}',
 '{"task_name": "Название задачи", "overdue_days": "Дни просрочки", "project_name": "Название проекта"}',
 TRUE),

('milestone_reached_sms', 'sms', 'system', 'MILESTONE_REACHED', NULL,
 'Достигнута веха "{{milestone_name}}" в проекте "{{project_name}}"',
 '{"milestone_name": "Название вехи", "project_name": "Название проекта"}',
 TRUE),

-- Email шаблоны
('project_created_email', 'email', 'system', 'PROJECT_CREATED', 
 'Новый проект создан: {{project_name}}',
 '<h2>Новый проект создан</h2>
  <p><strong>Название:</strong> {{project_name}}</p>
  <p><strong>Создатель:</strong> {{user_name}}</p>
  <p><strong>Бюджет:</strong> {{project_budget}}</p>
  <p><strong>Дата создания:</strong> {{created_date}}</p>
  <p><strong>Описание:</strong> {{project_description}}</p>',
 '{"project_name": "Название проекта", "user_name": "Имя пользователя", "project_budget": "Бюджет проекта", "created_date": "Дата создания", "project_description": "Описание проекта"}',
 TRUE),

('task_overdue_email', 'email', 'system', 'TASK_OVERDUE',
 'Просроченная задача: {{task_name}}',
 '<h2>Просроченная задача</h2>
  <p><strong>Задача:</strong> {{task_name}}</p>
  <p><strong>Проект:</strong> {{project_name}}</p>
  <p><strong>Ответственный:</strong> {{assignee_name}}</p>
  <p><strong>Просрочка:</strong> {{overdue_days}} дней</p>
  <p><strong>Планируемая дата:</strong> {{planned_date}}</p>',
 '{"task_name": "Название задачи", "project_name": "Название проекта", "assignee_name": "Ответственный", "overdue_days": "Дни просрочки", "planned_date": "Планируемая дата"}',
 TRUE),

('daily_report_email', 'email', 'system', 'DAILY_REPORT',
 'Ежедневный отчет: {{report_date}}',
 '<h2>Ежедневный отчет</h2>
  <p><strong>Дата:</strong> {{report_date}}</p>
  <p><strong>Создано проектов:</strong> {{projects_created}}</p>
  <p><strong>Завершено задач:</strong> {{tasks_completed}}</p>
  <p><strong>Просрочено задач:</strong> {{tasks_overdue}}</p>
  <p><strong>Активных пользователей:</strong> {{active_users}}</p>',
 '{"report_date": "Дата отчета", "projects_created": "Создано проектов", "tasks_completed": "Завершено задач", "tasks_overdue": "Просрочено задач", "active_users": "Активных пользователей"}',
 TRUE);
