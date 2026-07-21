-- Seed system event rules for event-driven operational reports.
-- Schedule (time_conditions) is crontab-like: frequency + at_time/until_time + timezone.
-- Periodicity lives in the schedule, not in a special event-type prefix.
-- Recipients live on each create_report action.
--
-- After seeding, add crontab:
--   */10 * * * * cd /path/to/api && php scripts/run-report-schedule-tick.php >> storage/logs/report-schedule-tick.log 2>&1
-- Then disable legacy: run-daily-operational-report.php --send

INSERT INTO fw_event_rules (event_type, enabled, actions, severity, conditions, comment, execution_location, updated_by)
VALUES (
    'REPORT_DAILY',
    1,
    JSON_ARRAY(
        JSON_OBJECT(
            'type', 'create_report',
            'period', 'daily',
            'recipients', JSON_ARRAY('admin', 'project_manager')
        )
    ),
    'important',
    JSON_OBJECT(
        'time_conditions', JSON_OBJECT(
            'frequency', 'daily',
            'days_of_week', JSON_ARRAY(1, 2, 3, 4, 5, 6, 7),
            'at_time', '00:15',
            'until_time', '00:45',
            'timezone', 'America/New_York'
        )
    ),
    'Event-driven daily operational report (schedule tick)',
    'server',
    NULL
)
ON DUPLICATE KEY UPDATE
    actions = VALUES(actions),
    conditions = VALUES(conditions),
    comment = VALUES(comment),
    enabled = VALUES(enabled);

INSERT INTO fw_event_rules (event_type, enabled, actions, severity, conditions, comment, execution_location, updated_by)
VALUES (
    'REPORT_WEEKLY',
    0,
    JSON_ARRAY(
        JSON_OBJECT(
            'type', 'create_report',
            'period', 'weekly',
            'recipients', JSON_ARRAY('admin', 'project_manager')
        )
    ),
    'important',
    JSON_OBJECT(
        'time_conditions', JSON_OBJECT(
            'frequency', 'weekly',
            'days_of_week', JSON_ARRAY(1),
            'at_time', '07:00',
            'until_time', '08:00',
            'timezone', 'America/New_York'
        )
    ),
    'Event-driven weekly operational report (disabled until ready; enable Mon window)',
    'server',
    NULL
)
ON DUPLICATE KEY UPDATE
    comment = VALUES(comment);

INSERT INTO fw_event_rules (event_type, enabled, actions, severity, conditions, comment, execution_location, updated_by)
VALUES (
    'REPORT_MONTHLY',
    0,
    JSON_ARRAY(
        JSON_OBJECT(
            'type', 'create_report',
            'period', 'monthly',
            'recipients', JSON_ARRAY('admin', 'project_manager')
        )
    ),
    'important',
    JSON_OBJECT(
        'time_conditions', JSON_OBJECT(
            'frequency', 'monthly',
            'monthly_mode', 'day_of_month',
            'day_of_month', 1,
            'day_of_month_last', false,
            'at_time', '07:00',
            'until_time', '08:00',
            'timezone', 'America/New_York'
        )
    ),
    'Event-driven monthly operational report (disabled until ready)',
    'server',
    NULL
)
ON DUPLICATE KEY UPDATE
    comment = VALUES(comment);
