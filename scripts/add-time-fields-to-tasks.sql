-- Migration: Add time fields to fw_prj_tasks table

-- Purpose: Enable time specification for tasks and milestones for iCal export

-- Date: 2025-01-XX



-- Add start_time and end_time fields

ALTER TABLE `fw_prj_tasks` 

ADD COLUMN `start_time` TIME DEFAULT '08:00:00' AFTER `start_planned`,

ADD COLUMN `end_time` TIME DEFAULT '17:00:00' AFTER `end_planned`;



-- Add index for time-based queries if needed

-- CREATE INDEX `idx_start_datetime` ON `fw_prj_tasks` (`start_planned`, `start_time`);



-- Update existing records to have default times (optional, but recommended)

UPDATE `fw_prj_tasks` 

SET `start_time` = '08:00:00', `end_time` = '17:00:00' 

WHERE `start_time` IS NULL OR `end_time` IS NULL;

