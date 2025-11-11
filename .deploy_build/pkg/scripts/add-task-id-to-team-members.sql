-- Add task_id field to fw_prj_team_members table
-- This allows using one table for both project team members and task assignees
-- Date: 2025-01-27

ALTER TABLE `fw_prj_team_members` 
ADD COLUMN `task_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL AFTER `project_id`,
ADD INDEX `idx_task_id` (`task_id`),
ADD CONSTRAINT `fk_team_members_task` FOREIGN KEY (`task_id`) REFERENCES `fw_prj_tasks` (`id`) ON DELETE CASCADE;

-- Drop existing unique constraint if exists (project_id, user_id) to allow multiple assignments
-- Note: We need to check if there's a unique constraint first
-- If unique constraint exists on (project_id, user_id), we need to change it to allow task assignments

-- After adding task_id, we need:
-- - Unique constraint on (project_id, user_id) WHERE task_id IS NULL (for project team members only)
-- - Unique constraint on (project_id, task_id, user_id) WHERE task_id IS NOT NULL (for task assignments)
-- But MySQL doesn't support partial unique indexes, so we'll use:
-- - Unique constraint on (project_id, task_id, user_id) - this allows:
--   * One user per project as team member (task_id = NULL)
--   * One user per task assignment (task_id = specific task_id)
--   * Same user can be assigned to multiple tasks (different task_id values)

-- Remove old unique constraint if it exists (check first)
-- ALTER TABLE `fw_prj_team_members` DROP INDEX IF EXISTS `unique_project_user`;

-- Add new unique constraint that allows multiple task assignments
ALTER TABLE `fw_prj_team_members` 
ADD UNIQUE KEY `unique_project_task_user` (`project_id`, `task_id`, `user_id`);

-- Update existing records: task_id should be NULL for project team members
-- (no changes needed, NULL is default)

