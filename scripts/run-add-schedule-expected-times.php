<?php

declare(strict_types=1);

/**
 * Idempotent migration: expected start/finish times on fw_worker_task_schedules.
 * Safe to run multiple times.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$conn = App\Database\Database::getConnection();

$tableExists = (bool) $conn->executeQuery(
    'SHOW TABLES LIKE ' . $conn->quote('fw_worker_task_schedules')
)->fetchOne();

if (!$tableExists) {
    fwrite(STDERR, "Table fw_worker_task_schedules does not exist.\n");
    exit(1);
}

$columns = [
    'expected_start_time' =>
        'ADD COLUMN `expected_start_time` TIME DEFAULT NULL COMMENT \'PM expected day start\' AFTER `distance_km`',
    'expected_end_time' =>
        'ADD COLUMN `expected_end_time` TIME DEFAULT NULL COMMENT \'PM expected day finish\' AFTER `expected_start_time`',
];

foreach ($columns as $name => $ddl) {
    $exists = (bool) $conn->executeQuery(
        'SHOW COLUMNS FROM fw_worker_task_schedules LIKE ' . $conn->quote($name)
    )->fetchOne();
    if ($exists) {
        echo "Column {$name} already exists\n";
        continue;
    }
    $conn->executeStatement("ALTER TABLE fw_worker_task_schedules {$ddl}");
    echo "Column {$name} added\n";
}

echo "Done\n";
