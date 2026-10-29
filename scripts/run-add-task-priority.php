<?php

declare(strict_types=1);

/**
 * Idempotent migration: PM-set priority on fw_prj_tasks.
 * Safe to run multiple times.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$conn = App\Database\Database::getConnection();

$exists = (bool) $conn->executeQuery(
    'SHOW COLUMNS FROM fw_prj_tasks LIKE ' . $conn->quote('priority')
)->fetchOne();

if ($exists) {
    echo "Column priority already exists\n";
} else {
    $conn->executeStatement(
        "ALTER TABLE fw_prj_tasks ADD COLUMN `priority` ENUM('urgent','high','medium','low') DEFAULT NULL COMMENT 'PM-set task priority' AFTER `category`"
    );
    echo "Column priority added\n";
}

echo "Done\n";
