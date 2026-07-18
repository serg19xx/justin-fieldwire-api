-- Locations of interest: selected Canadian FSA postal prefixes (JSON array of codes)
ALTER TABLE `fw_projects`
  ADD COLUMN `locations_of_interest` JSON NULL DEFAULT NULL AFTER `address`;
