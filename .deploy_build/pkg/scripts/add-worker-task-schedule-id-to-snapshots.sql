-- Link each snapshot row to the live slot PK (fw_worker_task_schedules.id) at publish time.
-- Run if fw_worker_task_schedule_snapshots already exists without worker_task_schedule_id.

ALTER TABLE `fw_worker_task_schedule_snapshots`
  ADD COLUMN `worker_task_schedule_id` BIGINT(20) UNSIGNED DEFAULT NULL
    COMMENT 'fw_worker_task_schedules.id at publish (for messages / unified entry id)'
    AFTER `id`,
  ADD KEY `idx_fw_wtss_live_slot` (`worker_task_schedule_id`),
  ADD CONSTRAINT `fk_fw_wtss_live_slot`
    FOREIGN KEY (`worker_task_schedule_id`) REFERENCES `fw_worker_task_schedules` (`id`) ON DELETE SET NULL;

-- Re-publish affected weeks so snapshots get worker_task_schedule_id populated.
