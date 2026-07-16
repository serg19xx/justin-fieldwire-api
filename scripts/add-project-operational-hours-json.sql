-- Operational Hours: weekly Open/Close schedule JSON (was VARCHAR placeholder)
ALTER TABLE `fw_projects`
  MODIFY COLUMN `operational_hours` JSON NULL DEFAULT NULL;
