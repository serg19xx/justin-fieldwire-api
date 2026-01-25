-- Migration: Add client_name field to fw_projects table
-- Purpose: Store client name directly in projects table for easier access
-- Date: 2025-01-25

-- Add client_name field
ALTER TABLE `fw_projects` 
ADD COLUMN `client_name` VARCHAR(255) NULL AFTER `client_data`;

-- Add index for better query performance
CREATE INDEX `idx_client_name` ON `fw_projects` (`client_name`);
