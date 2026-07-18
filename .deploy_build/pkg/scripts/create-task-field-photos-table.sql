-- Field work before/after photos attached to a task work day.
-- Files are stored on disk under public/uploads/task-field-photos/...

CREATE TABLE IF NOT EXISTS `fw_task_field_photos` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` BIGINT(20) UNSIGNED NOT NULL,
  `task_id` BIGINT(20) UNSIGNED NOT NULL,
  `work_date` DATE NOT NULL,
  `slot` ENUM('before', 'after') NOT NULL,
  `file_name` VARCHAR(512) NOT NULL COMMENT 'Stored relative path in public/uploads',
  `original_name` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(100) NOT NULL,
  `file_size` BIGINT(20) UNSIGNED NOT NULL,
  `uploaded_by` BIGINT(20) UNSIGNED NOT NULL,
  `uploaded_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `deleted_at` DATETIME(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_fw_tfp_project_task_date_slot` (`project_id`, `task_id`, `work_date`, `slot`, `uploaded_at`),
  KEY `idx_fw_tfp_task` (`task_id`),
  KEY `idx_fw_tfp_uploaded_by` (`uploaded_by`),
  CONSTRAINT `fk_fw_tfp_project`
    FOREIGN KEY (`project_id`) REFERENCES `fw_projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fw_tfp_task`
    FOREIGN KEY (`task_id`) REFERENCES `fw_prj_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fw_tfp_uploaded_by`
    FOREIGN KEY (`uploaded_by`) REFERENCES `fw_users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
