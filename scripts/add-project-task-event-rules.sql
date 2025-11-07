-- Add event rules for projects and tasks
-- Date: 2025-01-XX

-- ==========================================
-- PROJECT EVENTS
-- ==========================================

-- PROJECT_CREATED
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
  'PROJECT_CREATED',
  1,
  JSON_ARRAY('notify'),
  'critical',
  NULL,
  'server',
  'Project creation with role and time conditions',
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

-- PROJECT_UPDATED
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
  'PROJECT_UPDATED',
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
  'Auto-added default rule for project update events',
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

-- PROJECT_STATUS_CHANGED
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
  'PROJECT_STATUS_CHANGED',
  1,
  JSON_ARRAY(
    JSON_OBJECT(
      'type', 'notify',
      'recipients', JSON_ARRAY('admin', 'project_manager'),
      'channels', JSON_ARRAY('email', 'dashboard'),
      'store_for_dashboard', true
    )
  ),
  'important',
  NULL,
  NULL,
  'Auto-added default rule for project status change events',
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

-- PROJECT_DELETED
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
  'PROJECT_DELETED',
  1,
  JSON_ARRAY(
    JSON_OBJECT(
      'type', 'notify',
      'recipients', JSON_ARRAY('admin'),
      'channels', JSON_ARRAY('email'),
      'store_for_dashboard', true
    )
  ),
  'critical',
  NULL,
  NULL,
  'Auto-added default rule for project deletion events',
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

-- PROJECT_MEMBER_ADDED
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
  'PROJECT_MEMBER_ADDED',
  1,
  JSON_ARRAY(
    JSON_OBJECT(
      'type', 'notify',
      'recipients', JSON_ARRAY('project_manager'),
      'channels', JSON_ARRAY('email'),
      'store_for_dashboard', true
    )
  ),
  'important',
  NULL,
  NULL,
  'Auto-added default rule for project member addition events',
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

-- PROJECT_MEMBER_REMOVED
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
  'PROJECT_MEMBER_REMOVED',
  1,
  JSON_ARRAY(
    JSON_OBJECT(
      'type', 'notify',
      'recipients', JSON_ARRAY('project_manager'),
      'channels', JSON_ARRAY('email'),
      'store_for_dashboard', true
    )
  ),
  'important',
  NULL,
  NULL,
  'Auto-added default rule for project member removal events',
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

-- ==========================================
-- TASK EVENTS
-- ==========================================

-- TASK_CREATED
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
  'TASK_CREATED',
  1,
  JSON_ARRAY(
    JSON_OBJECT(
      'type', 'create_report',
      'period', 'daily',
      'recipients', JSON_ARRAY('project_manager', 'task_lead'),
      'store_for_dashboard', true
    )
  ),
  'important',
  NULL,
  NULL,
  'Auto-added default rule for task creation events',
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

-- TASK_UPDATED
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
  'TASK_UPDATED',
  1,
  JSON_ARRAY(
    JSON_OBJECT(
      'type', 'create_report',
      'period', 'daily',
      'recipients', JSON_ARRAY('project_manager', 'task_lead'),
      'store_for_dashboard', true
    )
  ),
  'important',
  NULL,
  NULL,
  'Auto-added default rule for task update events',
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

-- TASK_STATUS_CHANGED
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
  'TASK_STATUS_CHANGED',
  1,
  JSON_ARRAY(
    JSON_OBJECT(
      'type', 'notify',
      'recipients', JSON_ARRAY('project_manager', 'task_lead', 'team_members'),
      'channels', JSON_ARRAY('email', 'dashboard'),
      'store_for_dashboard', true
    )
  ),
  'important',
  NULL,
  NULL,
  'Auto-added default rule for task status change events',
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

-- TASK_SCHEDULE_CHANGED
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
  'TASK_SCHEDULE_CHANGED',
  1,
  JSON_ARRAY(
    JSON_OBJECT(
      'type', 'notify',
      'recipients', JSON_ARRAY('project_manager', 'task_lead', 'team_members'),
      'channels', JSON_ARRAY('email', 'dashboard'),
      'store_for_dashboard', true
    )
  ),
  'important',
  NULL,
  NULL,
  'Auto-added default rule for task schedule change events',
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

-- TASK_ASSIGNEES_CHANGED
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
  'TASK_ASSIGNEES_CHANGED',
  1,
  JSON_ARRAY(
    JSON_OBJECT(
      'type', 'notify',
      'recipients', JSON_ARRAY('project_manager', 'task_lead'),
      'channels', JSON_ARRAY('email'),
      'store_for_dashboard', true
    )
  ),
  'important',
  NULL,
  NULL,
  'Auto-added default rule for task assignees change events',
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

-- TASK_DELETED
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
  'TASK_DELETED',
  1,
  JSON_ARRAY(
    JSON_OBJECT(
      'type', 'notify',
      'recipients', JSON_ARRAY('project_manager', 'task_lead'),
      'channels', JSON_ARRAY('email'),
      'store_for_dashboard', true
    )
  ),
  'important',
  NULL,
  NULL,
  'Auto-added default rule for task deletion events',
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

-- TASK_DEPENDENCY_ADDED
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
  'TASK_DEPENDENCY_ADDED',
  1,
  JSON_ARRAY(
    JSON_OBJECT(
      'type', 'create_report',
      'period', 'daily',
      'recipients', JSON_ARRAY('project_manager'),
      'store_for_dashboard', true
    )
  ),
  'important',
  NULL,
  NULL,
  'Auto-added default rule for task dependency addition events',
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

-- TASK_DEPENDENCY_UPDATED
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
  'TASK_DEPENDENCY_UPDATED',
  1,
  JSON_ARRAY(
    JSON_OBJECT(
      'type', 'create_report',
      'period', 'daily',
      'recipients', JSON_ARRAY('project_manager'),
      'store_for_dashboard', true
    )
  ),
  'important',
  NULL,
  NULL,
  'Auto-added default rule for task dependency update events',
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

-- TASK_DEPENDENCY_REMOVED
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
  'TASK_DEPENDENCY_REMOVED',
  1,
  JSON_ARRAY(
    JSON_OBJECT(
      'type', 'create_report',
      'period', 'daily',
      'recipients', JSON_ARRAY('project_manager'),
      'store_for_dashboard', true
    )
  ),
  'important',
  NULL,
  NULL,
  'Auto-added default rule for task dependency removal events',
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

