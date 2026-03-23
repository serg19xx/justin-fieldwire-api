-- Migration: Add second client set (client2_*) to fw_projects table
-- Same structure as client_id, client_type, client_table, client_name, client_data
-- Date: 2026-01-24

ALTER TABLE `fw_projects`
ADD COLUMN `client2_id` bigint(20) unsigned DEFAULT NULL AFTER `client_data`,
ADD COLUMN `client2_type` varchar(100) DEFAULT NULL AFTER `client2_id`,
ADD COLUMN `client2_table` enum('patient','driver','pharma','physician','pharmacist','medical_clinic') DEFAULT 'patient' AFTER `client2_type`,
ADD COLUMN `client2_name` varchar(250) DEFAULT NULL AFTER `client2_table`,
ADD COLUMN `client2_data` longtext DEFAULT NULL AFTER `client2_name`;

CREATE INDEX `idx_client2_id` ON `fw_projects` (`client2_id`);
CREATE INDEX `idx_client2_table` ON `fw_projects` (`client2_table`);
CREATE INDEX `idx_client2_name` ON `fw_projects` (`client2_name`);
