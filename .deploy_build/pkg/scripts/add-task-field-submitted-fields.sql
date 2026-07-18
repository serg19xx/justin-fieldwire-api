-- Foreman field submission (separate from official PM task status)
-- Run once on production DB before deploying submit endpoint.

ALTER TABLE fw_prj_tasks
  ADD COLUMN field_submitted_at DATETIME NULL DEFAULT NULL,
  ADD COLUMN field_submitted_by BIGINT UNSIGNED NULL DEFAULT NULL;
