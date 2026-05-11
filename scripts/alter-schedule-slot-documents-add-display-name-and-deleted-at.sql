-- Apply on existing databases where fw_schedule_slot_documents already exists.

ALTER TABLE `fw_schedule_slot_documents`
  ADD COLUMN IF NOT EXISTS `display_name` VARCHAR(160) DEFAULT NULL AFTER `original_name`,
  ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME(6) DEFAULT NULL AFTER `uploaded_at`;
