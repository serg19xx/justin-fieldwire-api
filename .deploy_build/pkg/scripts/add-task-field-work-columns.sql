-- Foreman field work: start/end timestamps and foreman notes (separate from PM task notes).
-- Run once on production DB before deploying API + foreman task UI.

ALTER TABLE fw_prj_tasks
  ADD COLUMN field_work_started_at DATETIME NULL DEFAULT NULL,
  ADD COLUMN field_work_ended_at DATETIME NULL DEFAULT NULL,
  ADD COLUMN field_notes TEXT NULL DEFAULT NULL;
