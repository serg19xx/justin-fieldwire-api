-- Migration: Add new fields to fw_projects table
-- Purpose: Add purchase_or_lease, notes, and client-related fields to projects
-- Date: 2025-01-24

-- Add purchase_or_lease field
ALTER TABLE `fw_projects` 
ADD COLUMN `purchase_or_lease` ENUM('Purchase', 'Lease') DEFAULT 'Purchase' AFTER `status`;

-- Add notes field
ALTER TABLE `fw_projects` 
ADD COLUMN `notes` TEXT NULL AFTER `purchase_or_lease`;

-- Add client_id field
ALTER TABLE `fw_projects` 
ADD COLUMN `client_id` BIGINT UNSIGNED NULL AFTER `notes`;

-- Add client_type field
ALTER TABLE `fw_projects` 
ADD COLUMN `client_type` VARCHAR(100) NULL AFTER `client_id`;

-- Add client_table field
ALTER TABLE `fw_projects` 
ADD COLUMN `client_table` ENUM('pharma', 'physician', 'pharmacist', 'medical_clinic') NULL AFTER `client_type`;

-- Add client_data field (JSON for MySQL 5.7+, TEXT for older versions)
-- Check MySQL version first - if < 5.7, use TEXT instead of JSON
ALTER TABLE `fw_projects` 
ADD COLUMN `client_data` JSON NULL AFTER `client_table`;

-- Add indexes for better query performance
CREATE INDEX `idx_purchase_or_lease` ON `fw_projects` (`purchase_or_lease`);
CREATE INDEX `idx_client_id` ON `fw_projects` (`client_id`);
CREATE INDEX `idx_client_table` ON `fw_projects` (`client_table`);
