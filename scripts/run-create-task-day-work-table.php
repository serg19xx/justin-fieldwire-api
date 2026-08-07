<?php

declare(strict_types=1);

/**
 * Idempotent migration: fw_prj_task_day_work (per-task per-day actual clock).
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$conn = App\Database\Database::getConnection();

$exists = (bool) $conn->executeQuery(
    'SHOW TABLES LIKE ' . $conn->quote('fw_prj_task_day_work')
)->fetchOne();

if ($exists) {
    echo "Table fw_prj_task_day_work already exists\n";
    echo "Done\n";
    exit(0);
}

$sql = file_get_contents(__DIR__ . '/create-task-day-work-table.sql');
if ($sql === false || trim($sql) === '') {
    fwrite(STDERR, "Could not read create-task-day-work-table.sql\n");
    exit(1);
}

// Strip SQL comments for simple execute
$statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql) ?: []));
foreach ($statements as $statement) {
    $clean = preg_replace('/^--.*$/m', '', $statement);
    $clean = trim((string) $clean);
    if ($clean === '') {
        continue;
    }
    $conn->executeStatement($clean);
}

echo "Table fw_prj_task_day_work created\n";
echo "Done\n";
