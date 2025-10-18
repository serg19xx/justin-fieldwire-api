-- Add is_used column to fw_2fa_codes table for tracking used reset codes

ALTER TABLE fw_2fa_codes 
ADD COLUMN is_used TINYINT(1) DEFAULT 0 AFTER expires_at;

-- Update existing records to mark old codes as used
UPDATE fw_2fa_codes 
SET is_used = 1 
WHERE expires_at < UNIX_TIMESTAMP();

