-- Healthcare services category for projects (select from fixed list)
ALTER TABLE `fw_projects`
  ADD COLUMN `healthcare_services` VARCHAR(100) NULL DEFAULT NULL AFTER `clinic_model_type`;
