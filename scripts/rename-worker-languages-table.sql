-- Rename fw_worker_languages table to fw_user_languages
RENAME TABLE fw_worker_languages TO fw_user_languages;

-- Update foreign key constraint name if needed
-- (The constraint names should remain the same, but the table reference will be updated)
