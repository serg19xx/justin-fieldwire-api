-- Monthly budget in first year (free text, dollars)
ALTER TABLE `fw_projects`
  ADD COLUMN `monthly_budget_first_year` VARCHAR(100) NULL DEFAULT NULL AFTER `long_term_fm_team_size`;
