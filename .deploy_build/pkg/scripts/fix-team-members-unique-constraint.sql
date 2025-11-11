-- Fix unique constraint for fw_prj_team_members to prevent duplicate NULL values
-- MySQL doesn't treat multiple NULL values as equal in unique constraints
-- Solution: Use a generated column with COALESCE to replace NULL with 0
-- Date: 2025-01-27

-- Step 1: Add a generated column that replaces NULL with 0 (if it doesn't exist)
-- Проверяем, существует ли столбец, если нет - создаем
SET @col_exists = 0;
SELECT COUNT(*) INTO @col_exists 
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'fw_prj_team_members' 
  AND COLUMN_NAME = 'task_id_coalesced';

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE `fw_prj_team_members` ADD COLUMN `task_id_coalesced` BIGINT(20) UNSIGNED GENERATED ALWAYS AS (COALESCE(`task_id`, 0)) STORED AFTER `task_id`',
    'SELECT "Column task_id_coalesced already exists" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Step 2: Drop the old unique constraint
-- Удаляем старый уникальный ключ (может называться по-разному)
ALTER TABLE `fw_prj_team_members`
DROP INDEX IF EXISTS `unique_project_task_user`,
DROP INDEX IF EXISTS `fw_prj_team_members_unique`;

-- Step 3: Add new unique constraint using the generated column
ALTER TABLE `fw_prj_team_members`
ADD UNIQUE KEY `unique_project_task_user` (`project_id`, `task_id_coalesced`, `user_id`);

-- Step 4: Add index on task_id_coalesced for performance (if it doesn't exist)
-- Индекс уже может существовать, поэтому используем IF NOT EXISTS через проверку
SET @idx_exists = 0;
SELECT COUNT(*) INTO @idx_exists 
FROM information_schema.STATISTICS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'fw_prj_team_members' 
  AND INDEX_NAME = 'idx_task_id_coalesced';

SET @sql = IF(@idx_exists = 0,
    'ALTER TABLE `fw_prj_team_members` ADD INDEX `idx_task_id_coalesced` (`task_id_coalesced`)',
    'SELECT "Index idx_task_id_coalesced already exists" AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

