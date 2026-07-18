-- HR Vision specialties (multi-select JSON array)
ALTER TABLE `fw_projects`
  ADD COLUMN `hr_vision` JSON NULL DEFAULT NULL AFTER `est_clinical_hours_mds_on_site`;
