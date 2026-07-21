-- Allow weekly/monthly rows alongside daily for the same report_date + project_id.
-- Safe / idempotent: drops old unique if present, adds composite unique with report_type.

ALTER TABLE fw_operational_daily_reports
  DROP INDEX uniq_fw_op_daily_reports_date_project;

ALTER TABLE fw_operational_daily_reports
  ADD UNIQUE KEY uniq_fw_op_reports_date_project_type (report_date, project_id, report_type);

-- Idempotency for schedule tick fires
CREATE TABLE IF NOT EXISTS fw_report_schedule_fires (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_type VARCHAR(64) NOT NULL,
  period_key VARCHAR(32) NOT NULL,
  fired_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  event_log_id BIGINT UNSIGNED NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_report_schedule_fire (event_type, period_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
