-- Decouple schedule slots from tasks: presence on job site (project), not task assignment.
-- Tasks remain independent. task_id becomes optional (legacy rows may keep a value).

-- Live slots
ALTER TABLE `fw_worker_task_schedules`
  DROP FOREIGN KEY `fk_fw_wts_task`;

ALTER TABLE `fw_worker_task_schedules`
  MODIFY COLUMN `task_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL;

ALTER TABLE `fw_worker_task_schedules`
  ADD CONSTRAINT `fk_fw_wts_task`
    FOREIGN KEY (`task_id`) REFERENCES `fw_prj_tasks` (`id`) ON DELETE SET NULL;

-- Publish snapshots
ALTER TABLE `fw_worker_task_schedule_snapshots`
  DROP FOREIGN KEY `fk_fw_wtss_task`;

ALTER TABLE `fw_worker_task_schedule_snapshots`
  MODIFY COLUMN `task_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL;

ALTER TABLE `fw_worker_task_schedule_snapshots`
  ADD CONSTRAINT `fk_fw_wtss_task`
    FOREIGN KEY (`task_id`) REFERENCES `fw_prj_tasks` (`id`) ON DELETE SET NULL;
