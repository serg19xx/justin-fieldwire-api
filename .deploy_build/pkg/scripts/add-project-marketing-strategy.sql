-- Marketing Strategy channels (multi-select JSON array)
ALTER TABLE `fw_projects`
  ADD COLUMN `marketing_strategy` JSON NULL DEFAULT NULL AFTER `hr_vision`;
