-- Notification dispatcher foundation tables
-- Idempotent via scripts/run-add-notification-dispatcher.php

CREATE TABLE IF NOT EXISTS `fw_notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `recipient_id` BIGINT UNSIGNED NOT NULL,
  `sender_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `type` VARCHAR(80) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `data` JSON NULL DEFAULT NULL,
  `status` ENUM('pending','sent','delivered','failed','skipped') NOT NULL DEFAULT 'pending',
  `channel` ENUM('email','sms','push','dashboard') NOT NULL DEFAULT 'email',
  `priority` ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  `event_log_id` BIGINT UNSIGNED NULL DEFAULT NULL,
  `correlation_id` VARCHAR(64) NULL DEFAULT NULL,
  `idempotency_key` VARCHAR(191) NULL DEFAULT NULL,
  `provider` VARCHAR(64) NULL DEFAULT NULL,
  `provider_message_id` VARCHAR(191) NULL DEFAULT NULL,
  `url` VARCHAR(500) NULL DEFAULT NULL,
  `scheduled_at` TIMESTAMP NULL DEFAULT NULL,
  `sent_at` TIMESTAMP NULL DEFAULT NULL,
  `delivered_at` TIMESTAMP NULL DEFAULT NULL,
  `failed_at` TIMESTAMP NULL DEFAULT NULL,
  `next_attempt_at` TIMESTAMP NULL DEFAULT NULL,
  `last_attempt_at` TIMESTAMP NULL DEFAULT NULL,
  `failure_reason` TEXT NULL DEFAULT NULL,
  `retry_count` INT NOT NULL DEFAULT 0,
  `max_retries` INT NOT NULL DEFAULT 3,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_fw_notifications_idempotency` (`idempotency_key`),
  KEY `idx_fw_notifications_recipient` (`recipient_id`),
  KEY `idx_fw_notifications_sender` (`sender_id`),
  KEY `idx_fw_notifications_type` (`type`),
  KEY `idx_fw_notifications_status` (`status`),
  KEY `idx_fw_notifications_channel` (`channel`),
  KEY `idx_fw_notifications_correlation` (`correlation_id`),
  KEY `idx_fw_notifications_next_attempt` (`next_attempt_at`),
  KEY `idx_fw_notifications_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fw_notification_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `notification_id` BIGINT UNSIGNED NOT NULL,
  `attempt_no` INT NOT NULL DEFAULT 1,
  `provider` VARCHAR(64) NULL DEFAULT NULL,
  `status` ENUM('sent','failed','skipped') NOT NULL,
  `provider_message_id` VARCHAR(191) NULL DEFAULT NULL,
  `error_code` VARCHAR(64) NULL DEFAULT NULL,
  `error_message` TEXT NULL DEFAULT NULL,
  `is_retryable` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_fw_notification_attempts_notification` (`notification_id`),
  KEY `idx_fw_notification_attempts_status` (`status`),
  CONSTRAINT `fk_fw_notification_attempts_notification`
    FOREIGN KEY (`notification_id`) REFERENCES `fw_notifications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fw_notification_preferences` (
  `user_id` BIGINT UNSIGNED NOT NULL,
  `outbound_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `email_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `sms_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `push_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `fw_notification_event_preferences` (
  `user_id` BIGINT UNSIGNED NOT NULL,
  `event_type` VARCHAR(80) NOT NULL,
  `email_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `sms_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `push_enabled` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `event_type`),
  KEY `idx_fw_notification_event_preferences_event` (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
