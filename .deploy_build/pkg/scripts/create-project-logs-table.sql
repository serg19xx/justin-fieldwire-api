-- Таблица для логирования создания проектов
-- Date: 2025-10-26

CREATE TABLE `fw_project_creation_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint(20) unsigned NOT NULL,
  `created_by_user_id` bigint(20) unsigned NOT NULL,
  `project_manager_id` bigint(20) unsigned DEFAULT NULL,
  `project_name` varchar(255) NOT NULL,
  `project_priority` varchar(50) NOT NULL,
  `project_status` varchar(50) NOT NULL,
  `project_start_date` date NOT NULL,
  `project_end_date` date DEFAULT NULL,
  `project_address` text DEFAULT NULL,
  `creation_timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `notification_sent` tinyint(1) NOT NULL DEFAULT 0,
  `notification_sent_at` timestamp NULL DEFAULT NULL,
  `notification_recipient_id` bigint(20) unsigned DEFAULT NULL,
  `notification_type` enum('admin_to_manager','manager_to_admin') DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_project_id` (`project_id`),
  KEY `idx_created_by_user_id` (`created_by_user_id`),
  KEY `idx_project_manager_id` (`project_manager_id`),
  KEY `idx_creation_timestamp` (`creation_timestamp`),
  KEY `idx_notification_sent` (`notification_sent`),
  CONSTRAINT `fk_project_creation_logs_project` FOREIGN KEY (`project_id`) REFERENCES `fw_projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_project_creation_logs_created_by` FOREIGN KEY (`created_by_user_id`) REFERENCES `fw_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_project_creation_logs_manager` FOREIGN KEY (`project_manager_id`) REFERENCES `fw_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_project_creation_logs_recipient` FOREIGN KEY (`notification_recipient_id`) REFERENCES `fw_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
