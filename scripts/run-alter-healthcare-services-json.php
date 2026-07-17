<?php

declare(strict_types=1);

/**
 * One-shot: alter healthcare_services VARCHAR → JSON (multi-select).
 * Migrates legacy single-string values into JSON arrays.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$connection = App\Database\Database::getConnection();
$col = $connection->executeQuery(
    "SHOW COLUMNS FROM fw_projects LIKE 'healthcare_services'"
)->fetchAssociative();

if (!$col) {
    $connection->executeStatement(
        'ALTER TABLE `fw_projects`
         ADD COLUMN `healthcare_services` JSON NULL DEFAULT NULL AFTER `clinic_model_type`'
    );
    echo "COLUMN created as JSON\n";
    exit(0);
}

$type = strtolower((string) ($col['Type'] ?? ''));
if (str_contains($type, 'json')) {
    echo "COLUMN already JSON\n";
    exit(0);
}

// Wrap legacy single values into a JSON array when valid JSON is not already present
$rows = $connection->executeQuery(
    "SELECT id, healthcare_services FROM fw_projects
     WHERE healthcare_services IS NOT NULL AND healthcare_services <> ''"
)->fetchAllAssociative();

foreach ($rows as $row) {
    $raw = (string) $row['healthcare_services'];
    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        continue;
    }
    $connection->executeStatement(
        'UPDATE fw_projects SET healthcare_services = ? WHERE id = ?',
        [json_encode([$raw]), (int) $row['id']]
    );
}

$connection->executeStatement(
    'ALTER TABLE `fw_projects`
     MODIFY COLUMN `healthcare_services` JSON NULL DEFAULT NULL'
);

echo "COLUMN altered to JSON\n";
