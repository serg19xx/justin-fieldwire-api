-- Photo taken on site at Start day / End day (required by the worker app for every punch).
-- Paths are relative to public/ (uploads/worker-day-photos/...), served via authenticated endpoints.

ALTER TABLE `fw_worker_day_work`
  ADD COLUMN `work_start_photo` VARCHAR(500) DEFAULT NULL AFTER `work_start_at`,
  ADD COLUMN `work_end_photo` VARCHAR(500) DEFAULT NULL AFTER `work_end_at`;
