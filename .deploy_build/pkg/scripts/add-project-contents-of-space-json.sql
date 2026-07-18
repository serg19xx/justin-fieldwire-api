-- Contents of Space: structured calculator JSON (was VARCHAR placeholder)
ALTER TABLE `fw_projects`
  MODIFY COLUMN `contents_of_space` JSON NULL DEFAULT NULL;
