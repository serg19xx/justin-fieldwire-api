-- Multiple clients per project: primary stays on fw_projects.client_*;
-- additional clients live in fw_project_clients (is_primary = 0).
-- Primary may also be mirrored with is_primary = 1 for consistency.

CREATE TABLE IF NOT EXISTS `fw_project_clients` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` BIGINT(20) UNSIGNED NOT NULL,
  `client_id` BIGINT(20) UNSIGNED NOT NULL,
  `client_type` VARCHAR(100) DEFAULT NULL,
  `client_table` ENUM('pharma','physician','pharmacist','medical_clinic') NOT NULL,
  `client_name` VARCHAR(255) DEFAULT NULL,
  `client_data` LONGTEXT DEFAULT NULL,
  `is_primary` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fw_project_clients_project_client` (`project_id`, `client_table`, `client_id`),
  KEY `idx_fw_project_clients_project_id` (`project_id`),
  KEY `idx_fw_project_clients_is_primary` (`project_id`, `is_primary`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backfill primary clients
INSERT IGNORE INTO `fw_project_clients`
  (`project_id`, `client_id`, `client_type`, `client_table`, `client_name`, `client_data`, `is_primary`, `sort_order`)
SELECT
  `id`,
  `client_id`,
  `client_type`,
  `client_table`,
  `client_name`,
  `client_data`,
  1,
  0
FROM `fw_projects`
WHERE `client_id` IS NOT NULL
  AND `client_table` IS NOT NULL
  AND `client_table` IN ('pharma', 'physician', 'pharmacist', 'medical_clinic');

-- Backfill secondary clients as first additional
INSERT IGNORE INTO `fw_project_clients`
  (`project_id`, `client_id`, `client_type`, `client_table`, `client_name`, `client_data`, `is_primary`, `sort_order`)
SELECT
  `id`,
  `client2_id`,
  `client2_type`,
  `client2_table`,
  `client2_name`,
  `client2_data`,
  0,
  1
FROM `fw_projects`
WHERE `client2_id` IS NOT NULL
  AND `client2_table` IS NOT NULL
  AND `client2_table` IN ('pharma', 'physician', 'pharmacist', 'medical_clinic');
