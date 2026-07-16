<?php

declare(strict_types=1);

/**
 * One-shot: add fw_projects.monthly_budget_first_year if missing.
 * Usage (on API host): php scripts/run-add-monthly-budget-first-year.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$conn = App\Database\Database::getConnection();

$col = $conn->executeQuery(
    "SHOW COLUMNS FROM fw_projects LIKE 'monthly_budget_first_year'"
)->fetchOne();

if ($col) {
    echo "COLUMN exists\n";
} else {
    $conn->executeStatement(
        'ALTER TABLE `fw_projects`
         ADD COLUMN `monthly_budget_first_year` VARCHAR(100) NULL DEFAULT NULL AFTER `long_term_fm_team_size`'
    );
    echo "COLUMN created\n";
}

$check = $conn->executeQuery(
    "SHOW COLUMNS FROM fw_projects LIKE 'monthly_budget_first_year'"
)->fetchAssociative();

echo 'Field=' . ($check['Field'] ?? '?')
    . ' Type=' . ($check['Type'] ?? '?')
    . ' Null=' . ($check['Null'] ?? '?')
    . "\n";
