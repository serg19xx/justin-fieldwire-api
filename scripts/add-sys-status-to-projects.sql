-- Migration: Add system lifecycle status to projects
-- Field: fw_projects.sys_status
-- Date: 2026-03-23

ALTER TABLE `fw_projects`
ADD COLUMN `sys_status` ENUM('Draft','Active','Closing','Suspended','Done') DEFAULT NULL AFTER `status`;

CREATE INDEX `idx_fw_projects_sys_status` ON `fw_projects` (`sys_status`);

-- Optional backfill example (run manually if needed):
-- UPDATE `fw_projects` SET `sys_status` = 'Draft' WHERE `sys_status` IS NULL;
