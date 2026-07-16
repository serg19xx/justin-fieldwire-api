-- Contents of Space (free text)
ALTER TABLE `fw_projects`
  ADD COLUMN `contents_of_space` VARCHAR(2000) NULL DEFAULT NULL AFTER `operational_hours`;
