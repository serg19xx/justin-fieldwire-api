-- Reasons when actual start/end differs from plan (foreman field report).
ALTER TABLE fw_prj_tasks
  ADD COLUMN field_work_start_reason TEXT NULL DEFAULT NULL,
  ADD COLUMN field_work_end_reason TEXT NULL DEFAULT NULL;
