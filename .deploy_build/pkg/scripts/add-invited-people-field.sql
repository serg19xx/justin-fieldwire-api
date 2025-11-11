-- Add invited_people field to fw_prj_team_members table
-- This field stores JSON data for external guests invited to milestones
-- Date: 2025-11-06

ALTER TABLE `fw_prj_team_members`
ADD COLUMN `invited_people` TEXT NULL DEFAULT NULL AFTER `role_in_project`;

-- Add index for better performance when filtering by role_in_project = 'invited'
ALTER TABLE `fw_prj_team_members`
ADD INDEX `idx_role_in_project` (`role_in_project`);

-- Note: For invited people:
-- - user_id should be NULL (external people not in database) or user ID (if person exists in system)
-- - role_in_project should be 'invited'
-- - invited_people should contain JSON string with person data: {name, email, company, phone, notes, avatar}
-- - The field is TEXT type to store JSON as string (compatible with older MySQL versions)

