<?php

declare(strict_types=1);

/**
 * One-shot: add fw_projects.marketing_strategy if missing.
 * Usage (on API host): php scripts/run-add-marketing-strategy.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$conn = App\Database\Database::getConnection();

$col = $conn->executeQuery(
    "SHOW COLUMNS FROM fw_projects LIKE 'marketing_strategy'"
)->fetchOne();

if ($col) {
    echo "COLUMN exists\n";
} else {
    $conn->executeStatement(
        'ALTER TABLE `fw_projects`
         ADD COLUMN `marketing_strategy` JSON NULL DEFAULT NULL AFTER `hr_vision`'
    );
    echo "COLUMN created\n";
}

$check = $conn->executeQuery(
    "SHOW COLUMNS FROM fw_projects LIKE 'marketing_strategy'"
)->fetchAssociative();

echo 'Field=' . ($check['Field'] ?? '?')
    . ' Type=' . ($check['Type'] ?? '?')
    . ' Null=' . ($check['Null'] ?? '?')
    . "\n";
