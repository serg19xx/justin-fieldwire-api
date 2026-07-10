-- Event rule: foreman submitted field work for PM review (dashboard only in phase 2)

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
  'TASK_FOREMAN_SUBMITTED',
  1,
  JSON_ARRAY(
    JSON_OBJECT(
      'type', 'notify',
      'recipients', JSON_ARRAY('project_manager'),
      'channels', JSON_ARRAY('dashboard'),
      'store_for_dashboard', true
    )
  ),
  'important',
  NULL,
  'server',
  'Foreman submitted field work; notify PM on dashboard',
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
