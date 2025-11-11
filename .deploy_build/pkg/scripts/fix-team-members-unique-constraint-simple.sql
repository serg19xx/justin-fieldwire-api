-- Fix unique constraint for fw_prj_team_members to prevent duplicate NULL values
-- Simple version - assumes task_id_coalesced column already exists
-- Date: 2025-01-27

-- Step 1: Drop the old unique constraint
ALTER TABLE `fw_prj_team_members`
DROP INDEX IF EXISTS `unique_project_task_user`,
DROP INDEX IF EXISTS `fw_prj_team_members_unique`;

-- Step 2: Add new unique constraint using the generated column task_id_coalesced
ALTER TABLE `fw_prj_team_members`
ADD UNIQUE KEY `unique_project_task_user` (`project_id`, `task_id_coalesced`, `user_id`);

