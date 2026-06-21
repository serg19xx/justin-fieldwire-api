-- SMS meeting slot invites: PM sends numbered options; client replies 1/2/3; calendar event created on confirm.
CREATE TABLE IF NOT EXISTS `fw_sms_meeting_invites` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT(20) UNSIGNED NOT NULL,
  `client_type` VARCHAR(32) NOT NULL,
  `client_id` INT(10) UNSIGNED NOT NULL,
  `client_name` VARCHAR(255) NOT NULL,
  `client_phone` VARCHAR(32) NOT NULL,
  `client_phone_normalized` VARCHAR(20) NOT NULL,
  `meeting_date` DATE NOT NULL,
  `slot1_time` TIME NOT NULL,
  `slot2_time` TIME NOT NULL,
  `slot3_time` TIME NOT NULL,
  `duration_minutes` INT(10) UNSIGNED NOT NULL DEFAULT 30,
  `meeting_title` VARCHAR(255) NOT NULL,
  `timezone` VARCHAR(64) NOT NULL DEFAULT 'America/Toronto',
  `status` ENUM('pending','confirmed','expired','cancelled') NOT NULL DEFAULT 'pending',
  `selected_slot` TINYINT(3) UNSIGNED DEFAULT NULL,
  `calendar_event_id` BIGINT(20) UNSIGNED DEFAULT NULL,
  `confirmed_at` DATETIME(3) DEFAULT NULL,
  `expires_at` DATETIME(3) NOT NULL,
  `outbound_message_sid` VARCHAR(64) DEFAULT NULL,
  `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  KEY `idx_sms_invites_phone_status` (`client_phone_normalized`, `status`),
  KEY `idx_sms_invites_user_status` (`user_id`, `status`),
  KEY `idx_sms_invites_client` (`client_type`, `client_id`),
  CONSTRAINT `fk_sms_invites_user`
    FOREIGN KEY (`user_id`) REFERENCES `fw_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sms_invites_calendar_event`
    FOREIGN KEY (`calendar_event_id`) REFERENCES `fw_calendar_events` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
