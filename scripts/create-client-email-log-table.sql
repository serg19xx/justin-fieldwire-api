-- Log of bulk emails sent to registry clients; a repeat send skips addresses that already got the same campaign.
CREATE TABLE IF NOT EXISTS `fw_client_email_log` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_type` VARCHAR(32) DEFAULT NULL,
  `client_id` BIGINT(20) UNSIGNED DEFAULT NULL,
  `email` VARCHAR(255) NOT NULL,
  `dedupe_hash` CHAR(64) NOT NULL,
  `sent_by` BIGINT(20) UNSIGNED DEFAULT NULL,
  `sent_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_client_email_log_email_hash` (`email`, `dedupe_hash`, `sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
