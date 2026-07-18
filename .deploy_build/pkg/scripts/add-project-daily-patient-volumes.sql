-- Daily Patient Volumes (Healthcare section)
ALTER TABLE `fw_projects`
  ADD COLUMN `daily_patient_volumes` VARCHAR(100) NULL DEFAULT NULL AFTER `est_clinical_hours_mds_on_site`;
