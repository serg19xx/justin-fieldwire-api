-- Day-level Start day / End day per worker (worker app Timesheet and Tasks home).
-- One start/end pair per user per calendar day, independent of tasks and projects.

CREATE TABLE IF NOT EXISTS `fw_worker_day_work` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT(20) UNSIGNED NOT NULL,
  `work_date` DATE NOT NULL,
  `work_start_lat` DECIMAL(10,7) DEFAULT NULL,
  `work_start_lng` DECIMAL(10,7) DEFAULT NULL,
  `work_start_at` DATETIME(3) DEFAULT NULL,
  `work_end_lat` DECIMAL(10,7) DEFAULT NULL,
  `work_end_lng` DECIMAL(10,7) DEFAULT NULL,
  `work_end_at` DATETIME(3) DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fw_worker_day_work` (`user_id`, `work_date`),
  CONSTRAINT `fk_fw_worker_day_work_user`
    FOREIGN KEY (`user_id`) REFERENCES `fw_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
