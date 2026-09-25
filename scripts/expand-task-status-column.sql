-- Expand fw_prj_tasks.status beyond the legacy 5-value ENUM.
-- UI / API use rich statuses (scheduled, partially_completed, completed, ...).
-- Under non-strict sql_mode, invalid ENUM values were silently stored as ''
-- and then read back as planned.

ALTER TABLE `fw_prj_tasks`
  MODIFY COLUMN `status` VARCHAR(50) NOT NULL DEFAULT 'planned'
  COMMENT 'Task status: planned, scheduled, scheduled_accepted, in_progress, partially_completed, delayed_due_to_issue, ready_for_inspection, completed (legacy: done, blocked, delayed)';

-- Repair rows corrupted by invalid ENUM writes
UPDATE `fw_prj_tasks`
SET `status` = 'planned'
WHERE `status` IS NULL OR `status` = '';
