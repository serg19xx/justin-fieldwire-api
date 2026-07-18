-- Clinic model type for projects (select from fixed list)
ALTER TABLE `fw_projects`
  ADD COLUMN `clinic_model_type` VARCHAR(100) NULL DEFAULT NULL AFTER `level`;
