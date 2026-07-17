<?php

declare(strict_types=1);

/**
 * One-shot: add fw_projects.daily_patient_volumes if missing.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$conn = App\Database\Database::getConnection();

$col = $conn->executeQuery(
    "SHOW COLUMNS FROM fw_projects LIKE 'daily_patient_volumes'"
)->fetchOne();

if ($col) {
    echo "COLUMN exists\n";
    exit(0);
}

$after = $conn->executeQuery(
    "SHOW COLUMNS FROM fw_projects LIKE 'est_clinical_hours_mds_on_site'"
)->fetchOne();

if ($after) {
    $conn->executeStatement(
        'ALTER TABLE `fw_projects`
         ADD COLUMN `daily_patient_volumes` VARCHAR(100) NULL DEFAULT NULL AFTER `est_clinical_hours_mds_on_site`'
    );
} else {
    $conn->executeStatement(
        'ALTER TABLE `fw_projects`
         ADD COLUMN `daily_patient_volumes` VARCHAR(100) NULL DEFAULT NULL'
    );
}

echo "COLUMN created\n";
