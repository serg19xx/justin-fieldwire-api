-- Messages per schedule slot (fw_worker_task_schedules row), two channels: foreman | pm.
-- Requires: fw_worker_task_schedules, fw_users.

CREATE TABLE IF NOT EXISTS `fw_worker_task_schedule_messages` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `worker_task_schedule_id` BIGINT(20) UNSIGNED NOT NULL COMMENT 'FK to fw_worker_task_schedules.id',
  `channel` ENUM('foreman','pm') NOT NULL COMMENT 'foreman = foreman/worker stream; pm = PM/worker stream',
  `author_user_id` BIGINT(20) UNSIGNED NOT NULL,
  `body` TEXT NOT NULL,
  `created_at` TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` TIMESTAMP(6) NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP(6),
  `deleted_at` TIMESTAMP(6) NULL DEFAULT NULL COMMENT 'Soft delete',
  PRIMARY KEY (`id`),
  KEY `idx_fw_wts_msg_schedule_channel` (`worker_task_schedule_id`, `channel`),
  KEY `idx_fw_wts_msg_schedule_channel_id` (`worker_task_schedule_id`, `channel`, `id`),
  KEY `idx_fw_wts_messages_author` (`author_user_id`),
  CONSTRAINT `fk_fw_wts_messages_schedule`
    FOREIGN KEY (`worker_task_schedule_id`) REFERENCES `fw_worker_task_schedules` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_fw_wts_messages_author`
    FOREIGN KEY (`author_user_id`) REFERENCES `fw_users` (`id`)
    ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
