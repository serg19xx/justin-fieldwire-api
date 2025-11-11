-- Remove task_lead_id and team_members fields from fw_prj_tasks table
-- This data is now stored in fw_prj_team_members table
-- Date: 2025-01-27

ALTER TABLE `fw_prj_tasks` 
DROP COLUMN IF EXISTS `task_lead_id`,
DROP COLUMN IF EXISTS `team_members`;

