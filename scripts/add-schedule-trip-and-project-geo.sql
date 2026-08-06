-- Trip fields on schedule slots (PM distance text + worker start/end geo).
-- Project site coordinates for distance-from-site display.

ALTER TABLE `fw_worker_task_schedules`
  ADD COLUMN `distance_km` VARCHAR(32) DEFAULT NULL COMMENT 'Free-text km (PM)' AFTER `assignment_note`,
  ADD COLUMN `work_start_lat` DECIMAL(10,7) DEFAULT NULL AFTER `distance_km`,
  ADD COLUMN `work_start_lng` DECIMAL(10,7) DEFAULT NULL AFTER `work_start_lat`,
  ADD COLUMN `work_start_at` DATETIME(3) DEFAULT NULL AFTER `work_start_lng`,
  ADD COLUMN `work_end_lat` DECIMAL(10,7) DEFAULT NULL AFTER `work_start_at`,
  ADD COLUMN `work_end_lng` DECIMAL(10,7) DEFAULT NULL AFTER `work_end_lat`,
  ADD COLUMN `work_end_at` DATETIME(3) DEFAULT NULL AFTER `work_end_lng`;

ALTER TABLE `fw_projects`
  ADD COLUMN `latitude` DECIMAL(10,7) DEFAULT NULL COMMENT 'Geocoded from address' AFTER `address`,
  ADD COLUMN `longitude` DECIMAL(10,7) DEFAULT NULL COMMENT 'Geocoded from address' AFTER `latitude`;
