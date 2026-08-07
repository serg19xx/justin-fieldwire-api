-- Geo check-in for task field work (separate from schedule slot trip columns).
-- Prefer: php scripts/run-add-task-field-work-geo-columns.php (idempotent).
ALTER TABLE `fw_prj_tasks`
  ADD COLUMN `field_work_start_lat` DECIMAL(10,7) DEFAULT NULL,
  ADD COLUMN `field_work_start_lng` DECIMAL(10,7) DEFAULT NULL,
  ADD COLUMN `field_work_end_lat` DECIMAL(10,7) DEFAULT NULL,
  ADD COLUMN `field_work_end_lng` DECIMAL(10,7) DEFAULT NULL;
