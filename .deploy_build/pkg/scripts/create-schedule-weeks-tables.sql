-- Weekly work schedule: draft/published weeks and per-slot assignments
-- day_part: am = morning, pm = afternoon, full = full day
-- week_start: Monday date (ISO week); normalize in app when client sends any date in the week

CREATE TABLE IF NOT EXISTS `fw_schedule_weeks` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` BIGINT(20) UNSIGNED NOT NULL,
  `week_start` DATE NOT NULL COMMENT 'Monday of the schedule week (ISO)',
  `status` ENUM('draft','published') NOT NULL DEFAULT 'draft',
  `published_at` DATETIME(3) DEFAULT NULL,
  `published_by` BIGINT(20) UNSIGNED DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fw_schedule_weeks_project_week` (`project_id`, `week_start`),
  KEY `idx_fw_schedule_weeks_project` (`project_id`),
  KEY `idx_fw_schedule_weeks_status` (`status`),
  CONSTRAINT `fk_fw_schedule_weeks_project`
    FOREIGN KEY (`project_id`) REFERENCES `fw_projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `fw_worker_task_schedules` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `schedule_week_id` BIGINT(20) UNSIGNED NOT NULL,
  `project_id` BIGINT(20) UNSIGNED NOT NULL,
  `user_id` BIGINT(20) UNSIGNED NOT NULL,
  `task_id` BIGINT(20) UNSIGNED NOT NULL,
  `work_date` DATE NOT NULL,
  `day_part` ENUM('am','pm','full') NOT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fw_worker_task_schedules_slot` (`schedule_week_id`, `user_id`, `work_date`, `day_part`),
  KEY `idx_fw_wts_project` (`project_id`),
  KEY `idx_fw_wts_user_date` (`user_id`, `work_date`),
  KEY `idx_fw_wts_task` (`task_id`),
  CONSTRAINT `fk_fw_wts_week`
    FOREIGN KEY (`schedule_week_id`) REFERENCES `fw_schedule_weeks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fw_wts_project`
    FOREIGN KEY (`project_id`) REFERENCES `fw_projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fw_wts_user`
    FOREIGN KEY (`user_id`) REFERENCES `fw_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fw_wts_task`
    FOREIGN KEY (`task_id`) REFERENCES `fw_prj_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
