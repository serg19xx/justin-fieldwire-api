-- Est. clinical hours between MDs on site (free text)
ALTER TABLE `fw_projects`
  ADD COLUMN `est_clinical_hours_mds_on_site` VARCHAR(100) NULL DEFAULT NULL AFTER `monthly_budget_first_year`;
