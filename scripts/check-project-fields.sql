-- Script to check if new project fields exist in fw_projects table
-- Run this before executing the migration to see which fields are missing

-- Check for purchase_or_lease
SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN '✓ Field purchase_or_lease exists'
        ELSE '✗ Field purchase_or_lease is MISSING'
    END as status
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'fw_projects' 
  AND COLUMN_NAME = 'purchase_or_lease';

-- Check for notes
SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN '✓ Field notes exists'
        ELSE '✗ Field notes is MISSING'
    END as status
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'fw_projects' 
  AND COLUMN_NAME = 'notes';

-- Check for client_id
SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN '✓ Field client_id exists'
        ELSE '✗ Field client_id is MISSING'
    END as status
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'fw_projects' 
  AND COLUMN_NAME = 'client_id';

-- Check for client_type
SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN '✓ Field client_type exists'
        ELSE '✗ Field client_type is MISSING'
    END as status
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'fw_projects' 
  AND COLUMN_NAME = 'client_type';

-- Check for client_table
SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN '✓ Field client_table exists'
        ELSE '✗ Field client_table is MISSING'
    END as status
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'fw_projects' 
  AND COLUMN_NAME = 'client_table';

-- Check for client_data
SELECT 
    CASE 
        WHEN COUNT(*) > 0 THEN '✓ Field client_data exists'
        ELSE '✗ Field client_data is MISSING'
    END as status
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'fw_projects' 
  AND COLUMN_NAME = 'client_data';

-- Summary: Show all columns in fw_projects table
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'fw_projects'
ORDER BY ORDINAL_POSITION;
