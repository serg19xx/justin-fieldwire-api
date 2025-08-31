-- Create table for storing 2FA verification codes
CREATE TABLE IF NOT EXISTS `two_factor_codes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `code` varchar(6) NOT NULL,
  `expires_at` timestamp NOT NULL,
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `code` (`code`),
  KEY `expires_at` (`expires_at`),
  KEY `used` (`used`),
  CONSTRAINT `fk_two_factor_codes_user_id` FOREIGN KEY (`user_id`) REFERENCES `fw_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Add index for faster lookups
CREATE INDEX `idx_two_factor_codes_user_code` ON `two_factor_codes` (`user_id`, `code`);
CREATE INDEX `idx_two_factor_codes_expires` ON `two_factor_codes` (`expires_at`);
