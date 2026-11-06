-- Attendance automation: site arrivals, one-tap SMS action links and the PM digest log.

CREATE TABLE IF NOT EXISTS `fw_worker_day_site_events` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT(20) UNSIGNED NOT NULL,
  `work_date` DATE NOT NULL,
  `project_id` BIGINT(20) UNSIGNED NOT NULL,
  `arrived_at` DATETIME(3) NOT NULL,
  `lat` DECIMAL(10,7) DEFAULT NULL,
  `lng` DECIMAL(10,7) DEFAULT NULL,
  `source` VARCHAR(16) NOT NULL DEFAULT 'app',
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fw_worker_day_site_events` (`user_id`, `work_date`, `project_id`),
  CONSTRAINT `fk_fw_worker_day_site_events_user`
    FOREIGN KEY (`user_id`) REFERENCES `fw_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `fw_worker_action_tokens` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `token_hash` CHAR(64) NOT NULL,
  `user_id` BIGINT(20) UNSIGNED NOT NULL,
  `work_date` DATE NOT NULL,
  `action` VARCHAR(16) NOT NULL,
  `project_id` BIGINT(20) UNSIGNED DEFAULT NULL,
  `expires_at` DATETIME(3) NOT NULL,
  `last_used_at` DATETIME(3) DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fw_worker_action_tokens_hash` (`token_hash`),
  KEY `idx_fw_worker_action_tokens_user_day` (`user_id`, `work_date`),
  CONSTRAINT `fk_fw_worker_action_tokens_user`
    FOREIGN KEY (`user_id`) REFERENCES `fw_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `fw_attendance_digests` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `digest_date` DATE NOT NULL,
  `kind` VARCHAR(16) NOT NULL,
  `issue_count` INT NOT NULL DEFAULT 0,
  `recipients` TEXT DEFAULT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fw_attendance_digests` (`digest_date`, `kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
