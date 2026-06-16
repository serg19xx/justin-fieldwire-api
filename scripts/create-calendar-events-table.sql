-- Personal / project calendar events (not linked to fw_prj_tasks)
-- project_id NULL = global (personal) event owned by user_id

CREATE TABLE IF NOT EXISTS `fw_calendar_events` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT(20) UNSIGNED NOT NULL,
  `project_id` BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'NULL = personal/global event',
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT DEFAULT NULL,
  `location` VARCHAR(500) DEFAULT NULL,
  `start_at` DATETIME(3) NOT NULL,
  `end_at` DATETIME(3) DEFAULT NULL,
  `all_day` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_fw_calendar_events_user` (`user_id`),
  KEY `idx_fw_calendar_events_project` (`project_id`),
  KEY `idx_fw_calendar_events_start` (`start_at`),
  CONSTRAINT `fk_fw_calendar_events_user`
    FOREIGN KEY (`user_id`) REFERENCES `fw_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fw_calendar_events_project`
    FOREIGN KEY (`project_id`) REFERENCES `fw_projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
