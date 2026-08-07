-- Per-day actual work on a task (separate from Justin's project-day timesheet).
-- One start/end pair per user per task per calendar day.

CREATE TABLE IF NOT EXISTS `fw_prj_task_day_work` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` BIGINT(20) UNSIGNED NOT NULL,
  `task_id` BIGINT(20) UNSIGNED NOT NULL,
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
  UNIQUE KEY `uq_fw_prj_task_day_work` (`task_id`, `user_id`, `work_date`),
  KEY `idx_fw_prj_task_day_work_project_date` (`project_id`, `work_date`),
  KEY `idx_fw_prj_task_day_work_user_date` (`user_id`, `work_date`),
  CONSTRAINT `fk_fw_prj_task_day_work_project`
    FOREIGN KEY (`project_id`) REFERENCES `fw_projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fw_prj_task_day_work_task`
    FOREIGN KEY (`task_id`) REFERENCES `fw_prj_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fw_prj_task_day_work_user`
    FOREIGN KEY (`user_id`) REFERENCES `fw_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
