-- Long Term Family Medicine team size for projects (select from fixed list)
ALTER TABLE `fw_projects`
  ADD COLUMN `long_term_fm_team_size` VARCHAR(20) NULL DEFAULT NULL AFTER `healthcare_services`;
