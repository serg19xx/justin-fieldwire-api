-- Seed a default rule for WORKER_INVITED event
-- This rule is enabled and logs only by default (no notifications)

INSERT INTO fw_event_rules (
  event_type,
  enabled,
  actions,
  severity,
  conditions,
  execution_location,
  comment,
  updated_at,
  updated_by
) VALUES (
  'WORKER_INVITED',
  1,
  JSON_ARRAY(
    JSON_OBJECT(
      'type', 'create_report',
      'period', 'daily',
      'recipients', JSON_ARRAY('admin','project_manager'),
      'store_for_dashboard', true
    )
  ),
  'important',
  NULL,
  NULL,
  'Auto-added default rule for worker invitation events',
  NOW(),
  NULL
)
ON DUPLICATE KEY UPDATE
  enabled = VALUES(enabled),
  actions = VALUES(actions),
  severity = VALUES(severity),
  conditions = VALUES(conditions),
  execution_location = VALUES(execution_location),
  comment = VALUES(comment),
  updated_at = NOW();


