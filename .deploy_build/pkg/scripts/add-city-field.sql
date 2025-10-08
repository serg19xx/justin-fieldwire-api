-- Add city field to fw_users table
ALTER TABLE fw_users ADD COLUMN city VARCHAR(100) DEFAULT NULL AFTER workforce_group;

-- Add index for better performance
CREATE INDEX idx_city ON fw_users(city);
