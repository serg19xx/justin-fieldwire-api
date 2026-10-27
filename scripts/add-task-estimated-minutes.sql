-- PM "Estimated time to complete" for a task (days / hours / minutes stored as total minutes).
-- The API treats the column as optional, so it is safe to deploy code before running this.
ALTER TABLE `fw_prj_tasks`
  ADD COLUMN `estimated_minutes` INT UNSIGNED DEFAULT NULL AFTER `end_time`;
