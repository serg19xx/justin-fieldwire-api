-- Add full_img_url field to fw_users table
ALTER TABLE fw_users ADD COLUMN full_img_url VARCHAR(500) NULL AFTER avatar_url;

-- Add index for better performance
CREATE INDEX idx_full_img_url ON fw_users(full_img_url);
