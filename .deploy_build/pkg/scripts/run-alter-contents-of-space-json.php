<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$connection = App\Database\Database::getConnection();
$col = $connection->executeQuery(
    "SHOW COLUMNS FROM fw_projects LIKE 'contents_of_space'"
)->fetchAssociative();

if (!$col) {
    $connection->executeStatement(
        'ALTER TABLE `fw_projects`
         ADD COLUMN `contents_of_space` JSON NULL DEFAULT NULL AFTER `operational_hours`'
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
     SET contents_of_space = NULL
     WHERE contents_of_space IS NOT NULL
       AND contents_of_space <> ''
       AND JSON_VALID(contents_of_space) = 0"
);

$connection->executeStatement(
    'ALTER TABLE `fw_projects`
     MODIFY COLUMN `contents_of_space` JSON NULL DEFAULT NULL'
);

echo "COLUMN altered to JSON\n";
