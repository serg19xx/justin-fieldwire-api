-- Optional note per schedule slot (align with API assignment_note).
ALTER TABLE `fw_worker_task_schedules`
  ADD COLUMN `assignment_note` VARCHAR(2000) DEFAULT NULL COMMENT 'Optional note for this slot (PM / planner)' AFTER `day_part`;
