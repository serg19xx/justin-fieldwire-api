<?php

declare(strict_types=1);

/**
 * One-shot: add fw_projects.clinic_model_type if missing.
 * Usage (on API host): php scripts/run-add-clinic-model-type.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$conn = App\Database\Database::getConnection();

$col = $conn->executeQuery(
    "SHOW COLUMNS FROM fw_projects LIKE 'clinic_model_type'"
)->fetchOne();

if ($col) {
    echo "COLUMN exists\n";
} else {
    $conn->executeStatement(
        'ALTER TABLE `fw_projects`
         ADD COLUMN `clinic_model_type` VARCHAR(100) NULL DEFAULT NULL AFTER `level`'
    );
    echo "COLUMN created\n";
}

$check = $conn->executeQuery(
    "SHOW COLUMNS FROM fw_projects LIKE 'clinic_model_type'"
)->fetchAssociative();

echo 'Field=' . ($check['Field'] ?? '?')
    . ' Type=' . ($check['Type'] ?? '?')
    . ' Null=' . ($check['Null'] ?? '?')
    . "\n";
