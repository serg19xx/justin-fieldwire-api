-- Documents attached to one schedule entry (slot) in two buckets:
-- setup (PM instructions) and completed (worker results).
-- Allowed files are validated in API (image/* and application/pdf).

CREATE TABLE IF NOT EXISTS `fw_schedule_slot_documents` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` BIGINT(20) UNSIGNED NOT NULL,
  `schedule_entry_id` BIGINT(20) UNSIGNED NOT NULL,
  `bucket` ENUM('setup', 'completed') NOT NULL,
  `file_name` VARCHAR(512) NOT NULL COMMENT 'Stored relative path in public/uploads',
  `original_name` VARCHAR(255) NOT NULL,
  `display_name` VARCHAR(160) DEFAULT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `file_size` BIGINT(20) UNSIGNED NOT NULL,
  `uploaded_by` BIGINT(20) UNSIGNED NOT NULL,
  `uploaded_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `deleted_at` DATETIME(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fw_ssd_project_entry_bucket_uploaded` (`project_id`, `schedule_entry_id`, `bucket`, `uploaded_at`),
  KEY `idx_fw_ssd_entry` (`schedule_entry_id`),
  KEY `idx_fw_ssd_uploaded_by` (`uploaded_by`),
  CONSTRAINT `fk_fw_ssd_project`
    FOREIGN KEY (`project_id`) REFERENCES `fw_projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fw_ssd_entry`
    FOREIGN KEY (`schedule_entry_id`) REFERENCES `fw_worker_task_schedules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fw_ssd_uploaded_by`
    FOREIGN KEY (`uploaded_by`) REFERENCES `fw_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
