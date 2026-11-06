-- Log of SMS sent to registry clients; bulk sends skip a phone that already got the same text recently.
CREATE TABLE IF NOT EXISTS `fw_client_sms_log` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_type` VARCHAR(32) DEFAULT NULL,
  `client_id` BIGINT(20) UNSIGNED DEFAULT NULL,
  `phone` VARCHAR(32) NOT NULL,
  `message_hash` CHAR(64) NOT NULL,
  `sent_by` BIGINT(20) UNSIGNED DEFAULT NULL,
  `sent_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_client_sms_log_phone_hash` (`phone`, `message_hash`, `sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
