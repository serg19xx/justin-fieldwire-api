-- Настройка правил событий для создания проектов
-- Date: 2025-10-26

-- Правило для события создания проекта
INSERT INTO fw_event_rules (event_type, enabled, actions, severity, conditions, comment) 
VALUES (
    'PROJECT_CREATED', 
    1, 
    '["notify_project_manager", "notify_admin", "send_email_notification"]', 
    'important',
    '{"check_roles": true, "check_notifications": true}',
    'Уведомления при создании проекта: админ -> менеджер, менеджер -> админ'
);

-- Правило для ежедневных отчетов
INSERT INTO fw_event_rules (event_type, enabled, actions, severity, conditions, comment) 
VALUES (
    'DAILY_PROJECT_REPORT', 
    1, 
    '["generate_daily_report", "send_email_report", "update_dashboard"]', 
    'important',
    '{"schedule": "daily", "time": "09:00"}',
    'Ежедневный отчет по проектам'
);

-- Правило для уведомлений о статусе проекта
INSERT INTO fw_event_rules (event_type, enabled, actions, severity, conditions, comment) 
VALUES (
    'PROJECT_STATUS_CHANGED', 
    1, 
    '["notify_stakeholders", "log_status_change"]', 
    'important',
    '{"track_status_changes": true}',
    'Уведомления при изменении статуса проекта'
);
