-- Total Doctors (General) + Financials inputs
ALTER TABLE `fw_projects`
  ADD COLUMN `total_doctors` VARCHAR(100) NULL DEFAULT NULL AFTER `marketing_strategy`,
  ADD COLUMN `project_fee_per_doctor` VARCHAR(100) NULL DEFAULT NULL AFTER `total_doctors`,
  ADD COLUMN `cost_per_sq_ft` VARCHAR(100) NULL DEFAULT NULL AFTER `project_fee_per_doctor`,
  ADD COLUMN `mark_up` VARCHAR(100) NULL DEFAULT NULL AFTER `cost_per_sq_ft`;
