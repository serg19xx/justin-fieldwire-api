-- Project Inclusions multi-select JSON
ALTER TABLE `fw_projects`
  ADD COLUMN `project_inclusions` JSON NULL DEFAULT NULL AFTER `healthcare_services`;
