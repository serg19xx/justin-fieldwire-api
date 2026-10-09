-- SMS reminders to clock in / clock out on the worker Timesheet (at most one per kind per worker per day).

CREATE TABLE IF NOT EXISTS `fw_worker_day_reminders` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT(20) UNSIGNED NOT NULL,
  `work_date` DATE NOT NULL,
  `kind` VARCHAR(16) NOT NULL,
  `scheduled_time` TIME DEFAULT NULL,
  `phone` VARCHAR(32) DEFAULT NULL,
  `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fw_worker_day_reminders` (`user_id`, `work_date`, `kind`),
  CONSTRAINT `fk_fw_worker_day_reminders_user`
    FOREIGN KEY (`user_id`) REFERENCES `fw_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
