<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$conn = App\Database\Database::getConnection();

$exists = $conn->executeQuery(
    "SHOW TABLES LIKE 'fw_push_subscriptions'"
)->fetchOne();

if ($exists) {
    echo "TABLE exists\n";
    exit(0);
}

$conn->executeStatement(
    'CREATE TABLE `fw_push_subscriptions` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `user_id` INT NOT NULL,
      `endpoint` VARCHAR(500) NOT NULL,
      `p256dh` VARCHAR(255) NOT NULL,
      `auth` VARCHAR(255) NOT NULL,
      `user_agent` VARCHAR(512) NULL DEFAULT NULL,
      `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      UNIQUE KEY `uniq_fw_push_subscriptions_endpoint` (`endpoint`),
      KEY `idx_fw_push_subscriptions_user_id` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

echo "TABLE created\n";
