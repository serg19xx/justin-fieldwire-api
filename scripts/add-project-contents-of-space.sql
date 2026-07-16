-- Contents of Space (free text)
ALTER TABLE `fw_projects`
  ADD COLUMN `contents_of_space` JSON NULL DEFAULT NULL AFTER `operational_hours`;
