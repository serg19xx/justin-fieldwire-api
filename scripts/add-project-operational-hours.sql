-- Operational hours (free text)
ALTER TABLE `fw_projects`
  ADD COLUMN `operational_hours` VARCHAR(100) NULL DEFAULT NULL AFTER `hr_vision`;
