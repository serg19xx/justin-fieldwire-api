<?php

declare(strict_types=1);

/**
 * Idempotent migration: allow slot 'reference' (PM pictures) on fw_task_field_photos.
 * Safe to run multiple times.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$conn = App\Database\Database::getConnection();

$column = $conn->executeQuery(
    'SHOW COLUMNS FROM fw_task_field_photos LIKE ' . $conn->quote('slot')
)->fetchAssociative();

if (!$column) {
    fwrite(STDERR, "Column fw_task_field_photos.slot does not exist.\n");
    exit(1);
}

if (str_contains((string) $column['Type'], "'reference'")) {
    echo "Slot 'reference' already allowed\n";
} else {
    $conn->executeStatement(
        "ALTER TABLE fw_task_field_photos MODIFY COLUMN `slot` ENUM('before','after','reference') NOT NULL"
    );
    echo "Slot 'reference' added\n";
}

echo "Done\n";
