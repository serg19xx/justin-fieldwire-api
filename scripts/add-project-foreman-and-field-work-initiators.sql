-- Project-level foreman (required for new projects via API validation)
ALTER TABLE `fw_projects`
  ADD COLUMN `project_foreman_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `prj_manager`,
  ADD KEY `idx_fw_projects_project_foreman_id` (`project_foreman_id`);

-- Who actually initiated field work start/end (dual initiator audit)
ALTER TABLE `fw_prj_tasks`
  ADD COLUMN `field_work_started_by` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `field_work_started_at`,
  ADD COLUMN `field_work_ended_by` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `field_work_ended_at`;
