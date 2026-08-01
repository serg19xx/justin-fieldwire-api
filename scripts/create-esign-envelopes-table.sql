-- BoldSign e-signature envelopes tracked in FieldWire.
-- Run once on the application database.

CREATE TABLE IF NOT EXISTS `fw_esign_envelopes` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL,
  `created_by_user_id` BIGINT(20) UNSIGNED NULL DEFAULT NULL,
  `boldsign_document_id` VARCHAR(64) NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `status` VARCHAR(64) NOT NULL DEFAULT 'pending',
  `signer_email` VARCHAR(255) NOT NULL,
  `signer_name` VARCHAR(255) NULL DEFAULT NULL,
  `source_file_name` VARCHAR(255) NULL DEFAULT NULL,
  `meta_json` JSON NULL,
  `last_event` VARCHAR(128) NULL DEFAULT NULL,
  `last_webhook_at` DATETIME NULL DEFAULT NULL,
  `completed_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fw_esign_boldsign_doc` (`boldsign_document_id`),
  KEY `idx_fw_esign_project` (`project_id`),
  KEY `idx_fw_esign_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
