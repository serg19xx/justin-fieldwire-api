<?php

declare(strict_types=1);

/**
 * Idempotent migration: geo columns for task field-work check-in on fw_prj_tasks.
 * Safe to run multiple times.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$conn = App\Database\Database::getConnection();

$tableExists = (bool) $conn->executeQuery(
    'SHOW TABLES LIKE ' . $conn->quote('fw_prj_tasks')
)->fetchOne();

if (!$tableExists) {
    fwrite(STDERR, "Table fw_prj_tasks does not exist.\n");
    exit(1);
}

$columns = [
    'field_work_start_lat' => 'ADD COLUMN `field_work_start_lat` DECIMAL(10,7) DEFAULT NULL',
    'field_work_start_lng' => 'ADD COLUMN `field_work_start_lng` DECIMAL(10,7) DEFAULT NULL',
    'field_work_end_lat' => 'ADD COLUMN `field_work_end_lat` DECIMAL(10,7) DEFAULT NULL',
    'field_work_end_lng' => 'ADD COLUMN `field_work_end_lng` DECIMAL(10,7) DEFAULT NULL',
];

foreach ($columns as $name => $ddl) {
    $exists = (bool) $conn->executeQuery(
        'SHOW COLUMNS FROM fw_prj_tasks LIKE ' . $conn->quote($name)
    )->fetchOne();
    if ($exists) {
        echo "Column {$name} already exists\n";
        continue;
    }
    $conn->executeStatement("ALTER TABLE fw_prj_tasks {$ddl}");
    echo "Column {$name} added\n";
}

echo "Done\n";
