-- Create refresh tokens table
CREATE TABLE IF NOT EXISTS `fw_refresh_tokens` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT(20) UNSIGNED NOT NULL,
  `token` VARCHAR(255) NOT NULL,
  `expires_at` INT(11) NOT NULL COMMENT 'Unix timestamp',
  `created_at` INT(11) NOT NULL COMMENT 'Unix timestamp',
  `last_used_at` INT(11) NULL COMMENT 'Unix timestamp',
  `revoked` TINYINT(1) NOT NULL DEFAULT 0,
  `revoked_at` INT(11) NULL COMMENT 'Unix timestamp',
  `user_agent` VARCHAR(255) NULL,
  `ip_address` VARCHAR(45) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `user_id` (`user_id`),
  KEY `expires_at` (`expires_at`),
  KEY `revoked` (`revoked`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Refresh tokens for JWT authentication';

-- Add foreign key constraint
ALTER TABLE `fw_refresh_tokens`
  ADD CONSTRAINT `fw_refresh_tokens_user_id_foreign` 
  FOREIGN KEY (`user_id`) 
  REFERENCES `fw_users` (`id`) 
  ON DELETE CASCADE;

