-- Add event rules for worker registration and activation cycle
-- This script adds rules for tracking the complete worker registration process

-- USER_PROFILE_UPDATED: When user updates personal profile data
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
  'USER_PROFILE_UPDATED',
  1,
  JSON_ARRAY(
    JSON_OBJECT(
      'type', 'log',
      'store_for_dashboard', true
    )
  ),
  'important',
  NULL,
  NULL,
  'Auto-added default rule for user profile update events',
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

-- USER_EMERGENCY_CONTACT_UPDATED: When user updates emergency contacts, medical data, or insurance
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
  'USER_EMERGENCY_CONTACT_UPDATED',
  1,
  JSON_ARRAY(
    JSON_OBJECT(
      'type', 'log',
      'store_for_dashboard', true
    )
  ),
  'important',
  NULL,
  NULL,
  'Auto-added default rule for emergency contact and medical data update events',
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

-- USER_PROFILE_BECAME_COMPLETE: When user profile becomes complete (all required fields filled)
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
  'USER_PROFILE_BECAME_COMPLETE',
  1,
  JSON_ARRAY(
    JSON_OBJECT(
      'type', 'create_report',
      'period', 'daily',
      'recipients', JSON_ARRAY('admin', 'project_manager'),
      'store_for_dashboard', true
    )
  ),
  'important',
  NULL,
  NULL,
  'Auto-added default rule for profile completion events - triggers when profile becomes ready for activation',
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

-- USER_ACTIVATED: When user is activated (becomes active and ready to work)
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
  'USER_ACTIVATED',
  1,
  JSON_ARRAY(
    JSON_OBJECT(
      'type', 'create_report',
      'period', 'daily',
      'recipients', JSON_ARRAY('admin', 'project_manager'),
      'store_for_dashboard', true
    )
  ),
  'important',
  NULL,
  NULL,
  'Auto-added default rule for user activation events',
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

-- USER_DEACTIVATED: When user is deactivated (temporarily or permanently)
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
  'USER_DEACTIVATED',
  1,
  JSON_ARRAY(
    JSON_OBJECT(
      'type', 'create_report',
      'period', 'daily',
      'recipients', JSON_ARRAY('admin', 'project_manager'),
      'store_for_dashboard', true
    )
  ),
  'important',
  NULL,
  NULL,
  'Auto-added default rule for user deactivation events',
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

-- USER_STATUS_CHANGED: When user status changes (activation/deactivation)
-- This event is used by logEvent() method and requires a rule
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
  'USER_STATUS_CHANGED',
  1,
  JSON_ARRAY(
    JSON_OBJECT(
      'type', 'log',
      'store_for_dashboard', true
    )
  ),
  'important',
  NULL,
  NULL,
  'Auto-added default rule for user status change events (activation/deactivation)',
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

