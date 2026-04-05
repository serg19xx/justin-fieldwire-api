-- If fw_worker_task_schedule_messages existed without channel (legacy), add channel + indexes.
-- Existing rows become channel 'foreman' via default; adjust manually if needed.

ALTER TABLE `fw_worker_task_schedule_messages`
  ADD COLUMN `channel` ENUM('foreman','pm') NOT NULL DEFAULT 'foreman' COMMENT 'foreman | pm' AFTER `worker_task_schedule_id`;

ALTER TABLE `fw_worker_task_schedule_messages`
  ADD KEY `idx_fw_wts_msg_schedule_channel` (`worker_task_schedule_id`, `channel`),
  ADD KEY `idx_fw_wts_msg_schedule_channel_id` (`worker_task_schedule_id`, `channel`, `id`);

-- Optional: enforce no default after backfill
-- ALTER TABLE `fw_worker_task_schedule_messages` ALTER COLUMN `channel` DROP DEFAULT;
