-- Expected (planned) day start/finish for schedule timesheet rows.
-- Actual clock-in/out remains work_start_at / work_end_at.

ALTER TABLE `fw_worker_task_schedules`
  ADD COLUMN `expected_start_time` TIME DEFAULT NULL COMMENT 'PM expected day start' AFTER `distance_km`,
  ADD COLUMN `expected_end_time` TIME DEFAULT NULL COMMENT 'PM expected day finish' AFTER `expected_start_time`;
