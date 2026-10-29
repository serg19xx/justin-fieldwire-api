-- PM-set task priority (Urgent / High / Medium / Low). NULL = not set.
-- Run once (or use scripts/run-add-task-priority.php, which is idempotent).

ALTER TABLE fw_prj_tasks
  ADD COLUMN `priority` ENUM('urgent','high','medium','low') DEFAULT NULL COMMENT 'PM-set task priority' AFTER `category`;
