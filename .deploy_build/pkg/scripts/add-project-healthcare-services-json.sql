-- Healthcare Services: multi-select JSON (was VARCHAR single value)
ALTER TABLE `fw_projects`
  MODIFY COLUMN `healthcare_services` JSON NULL DEFAULT NULL;
