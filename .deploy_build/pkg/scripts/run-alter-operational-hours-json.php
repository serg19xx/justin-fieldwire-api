<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$connection = App\Database\Database::getConnection();
$col = $connection->executeQuery(
    "SHOW COLUMNS FROM fw_projects LIKE 'operational_hours'"
)->fetchAssociative();

if (!$col) {
    $connection->executeStatement(
        'ALTER TABLE `fw_projects`
         ADD COLUMN `operational_hours` JSON NULL DEFAULT NULL AFTER `hr_vision`'
    );
    echo "COLUMN created as JSON\n";
    exit(0);
}

$type = strtolower((string) ($col['Type'] ?? ''));
if (str_contains($type, 'json')) {
    echo "COLUMN already JSON\n";
    exit(0);
}

// Clear legacy free-text values that are not valid JSON objects
$connection->executeStatement(
    "UPDATE fw_projects
     SET operational_hours = NULL
     WHERE operational_hours IS NOT NULL
       AND operational_hours <> ''
       AND JSON_VALID(operational_hours) = 0"
);

$connection->executeStatement(
    'ALTER TABLE `fw_projects`
     MODIFY COLUMN `operational_hours` JSON NULL DEFAULT NULL'
);

echo "COLUMN altered to JSON\n";
