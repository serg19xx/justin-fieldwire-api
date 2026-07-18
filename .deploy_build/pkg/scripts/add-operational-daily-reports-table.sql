-- Operational daily reports (per project, JSON payload)
-- Separate from legacy fw_daily_project_reports (created-projects stats)

CREATE TABLE IF NOT EXISTS `fw_operational_daily_reports` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_date` DATE NOT NULL,
  `project_id` BIGINT UNSIGNED NOT NULL,
  `report_type` VARCHAR(20) NOT NULL DEFAULT 'daily',
  `scope` VARCHAR(20) NOT NULL DEFAULT 'project',
  `title` VARCHAR(255) NULL DEFAULT NULL,
  `payload_json` JSON NOT NULL,
  `rendered_html` MEDIUMTEXT NULL DEFAULT NULL,
  `status` ENUM('generated', 'sent', 'failed') NOT NULL DEFAULT 'generated',
  `generated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at` TIMESTAMP NULL DEFAULT NULL,
  `last_error` VARCHAR(500) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fw_op_daily_reports_date_project` (`report_date`, `project_id`),
  KEY `idx_fw_op_daily_reports_status` (`status`),
  KEY `idx_fw_op_daily_reports_project` (`project_id`),
  KEY `idx_fw_op_daily_reports_type_date` (`report_type`, `report_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
