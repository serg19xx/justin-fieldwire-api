<?php

declare(strict_types=1);

/**
 * One-shot: add total_doctors + financials columns if missing.
 * Usage (on API host): php scripts/run-add-financials-fields.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$conn = App\Database\Database::getConnection();

$columns = [
    'total_doctors' => 'marketing_strategy',
    'project_fee_per_doctor' => 'total_doctors',
    'cost_per_sq_ft' => 'project_fee_per_doctor',
    'mark_up' => 'cost_per_sq_ft',
];

foreach ($columns as $column => $after) {
    $exists = $conn->executeQuery(
        "SHOW COLUMNS FROM fw_projects LIKE '{$column}'"
    )->fetchOne();

    if ($exists) {
        echo "COLUMN {$column} exists\n";
        continue;
    }

    $afterExists = $conn->executeQuery(
        "SHOW COLUMNS FROM fw_projects LIKE '{$after}'"
    )->fetchOne();

    if ($afterExists) {
        $conn->executeStatement(
            "ALTER TABLE `fw_projects`
             ADD COLUMN `{$column}` VARCHAR(100) NULL DEFAULT NULL AFTER `{$after}`"
        );
    } else {
        $conn->executeStatement(
            "ALTER TABLE `fw_projects`
             ADD COLUMN `{$column}` VARCHAR(100) NULL DEFAULT NULL"
        );
    }
    echo "COLUMN {$column} created\n";
}
