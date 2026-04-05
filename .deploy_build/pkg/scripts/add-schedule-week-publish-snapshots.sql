-- Snapshot of slots at each publish; GET /me/schedule reads from here so workers keep seeing
-- the last published plan after POST .../reopen-as-draft (week returns to draft).
-- Run after fw_worker_task_schedules exists and includes assignment_note if you use that column.

CREATE TABLE IF NOT EXISTS `fw_worker_task_schedule_snapshots` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `worker_task_schedule_id` BIGINT(20) UNSIGNED DEFAULT NULL COMMENT 'fw_worker_task_schedules.id at publish',
  `schedule_week_id` BIGINT(20) UNSIGNED NOT NULL,
  `project_id` BIGINT(20) UNSIGNED NOT NULL,
  `user_id` BIGINT(20) UNSIGNED NOT NULL,
  `task_id` BIGINT(20) UNSIGNED NOT NULL,
  `work_date` DATE NOT NULL,
  `day_part` ENUM('am','pm','full') NOT NULL,
  `assignment_note` VARCHAR(2000) DEFAULT NULL,
  `snapshot_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) COMMENT 'Time of publish that created this row',
  PRIMARY KEY (`id`),
  KEY `idx_fw_wtss_week` (`schedule_week_id`),
  KEY `idx_fw_wtss_user_date` (`user_id`, `work_date`),
  KEY `idx_fw_wtss_live_slot` (`worker_task_schedule_id`),
  CONSTRAINT `fk_fw_wtss_week`
    FOREIGN KEY (`schedule_week_id`) REFERENCES `fw_schedule_weeks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fw_wtss_live_slot`
    FOREIGN KEY (`worker_task_schedule_id`) REFERENCES `fw_worker_task_schedules` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fw_wtss_project`
    FOREIGN KEY (`project_id`) REFERENCES `fw_projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fw_wtss_user`
    FOREIGN KEY (`user_id`) REFERENCES `fw_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_fw_wtss_task`
    FOREIGN KEY (`task_id`) REFERENCES `fw_prj_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- After deploy: re-publish each active week once if you need workers to see pre-migration published weeks (snapshots start empty).
