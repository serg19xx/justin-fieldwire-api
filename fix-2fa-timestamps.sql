-- Fix 2FA codes table to use Unix timestamps instead of datetime
-- This will make time comparison much simpler and avoid timezone issues

-- First, let's see the current structure
DESCRIBE fw_2fa_codes;

-- Add new columns for Unix timestamps
ALTER TABLE fw_2fa_codes 
ADD COLUMN expires_at_unix INT UNSIGNED,
ADD COLUMN created_at_unix INT UNSIGNED;

-- Convert existing datetime values to Unix timestamps
UPDATE fw_2fa_codes 
SET expires_at_unix = UNIX_TIMESTAMP(expires_at),
    created_at_unix = UNIX_TIMESTAMP(created_at);

-- Drop old datetime columns
ALTER TABLE fw_2fa_codes 
DROP COLUMN expires_at,
DROP COLUMN created_at;

-- Rename new columns to original names
ALTER TABLE fw_2fa_codes 
CHANGE COLUMN expires_at_unix expires_at INT UNSIGNED NOT NULL,
CHANGE COLUMN created_at_unix created_at INT UNSIGNED NOT NULL;

-- Add index for better performance
CREATE INDEX idx_expires_at ON fw_2fa_codes(expires_at);
CREATE INDEX idx_user_expires ON fw_2fa_codes(user_id, expires_at);

-- Show the new structure
DESCRIBE fw_2fa_codes;
