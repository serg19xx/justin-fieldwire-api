-- PM payroll review per worker per calendar day (Schedule page).
-- adjusted_* override the phone punches for paid hours; the original punches are kept untouched.
-- locked_at set = the PM confirmed the day is accurate; nothing for that worker/day can change until unlocked.

ALTER TABLE `fw_worker_day_work`
  ADD COLUMN `adjusted_start_time` TIME DEFAULT NULL AFTER `work_end_photo`,
  ADD COLUMN `adjusted_end_time` TIME DEFAULT NULL AFTER `adjusted_start_time`,
  ADD COLUMN `payroll_note` TEXT DEFAULT NULL AFTER `adjusted_end_time`,
  ADD COLUMN `payroll_updated_by` BIGINT(20) UNSIGNED DEFAULT NULL AFTER `payroll_note`,
  ADD COLUMN `payroll_updated_at` DATETIME(3) DEFAULT NULL AFTER `payroll_updated_by`,
  ADD COLUMN `locked_at` DATETIME(3) DEFAULT NULL AFTER `payroll_updated_at`,
  ADD COLUMN `locked_by` BIGINT(20) UNSIGNED DEFAULT NULL AFTER `locked_at`;
