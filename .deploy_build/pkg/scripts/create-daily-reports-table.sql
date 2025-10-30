-- Таблица для ежедневных отчетов по проектам
-- Date: 2025-10-26

CREATE TABLE `fw_daily_project_reports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `report_date` date NOT NULL,
  `total_projects_created` int(11) NOT NULL DEFAULT 0,
  `projects_by_priority` json DEFAULT NULL,
  `projects_by_status` json DEFAULT NULL,
  `projects_by_manager` json DEFAULT NULL,
  `projects_by_creator` json DEFAULT NULL,
  `active_projects_count` int(11) NOT NULL DEFAULT 0,
  `completed_projects_count` int(11) NOT NULL DEFAULT 0,
  `overdue_projects_count` int(11) NOT NULL DEFAULT 0,
  `report_generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `report_sent_at` timestamp NULL DEFAULT NULL,
  `recipients` json DEFAULT NULL,
  `status` enum('generated','sent','failed') NOT NULL DEFAULT 'generated',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_report_date` (`report_date`),
  KEY `idx_report_date` (`report_date`),
  KEY `idx_status` (`status`),
  KEY `idx_report_generated_at` (`report_generated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
