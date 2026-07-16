<?php

declare(strict_types=1);

/**
 * One-shot: add fw_projects.est_clinical_hours_mds_on_site if missing.
 * Usage (on API host): php scripts/run-add-est-clinical-hours-mds-on-site.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$conn = App\Database\Database::getConnection();

$col = $conn->executeQuery(
    "SHOW COLUMNS FROM fw_projects LIKE 'est_clinical_hours_mds_on_site'"
)->fetchOne();

if ($col) {
    echo "COLUMN exists\n";
} else {
    $conn->executeStatement(
        'ALTER TABLE `fw_projects`
         ADD COLUMN `est_clinical_hours_mds_on_site` VARCHAR(100) NULL DEFAULT NULL AFTER `monthly_budget_first_year`'
    );
    echo "COLUMN created\n";
}

$check = $conn->executeQuery(
    "SHOW COLUMNS FROM fw_projects LIKE 'est_clinical_hours_mds_on_site'"
)->fetchAssociative();

echo 'Field=' . ($check['Field'] ?? '?')
    . ' Type=' . ($check['Type'] ?? '?')
    . ' Null=' . ($check['Null'] ?? '?')
    . "\n";
