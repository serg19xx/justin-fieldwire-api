-- Healthcare services category for projects (select from fixed list)
ALTER TABLE `fw_projects`
  ADD COLUMN `healthcare_services` JSON NULL DEFAULT NULL AFTER `clinic_model_type`;
