<?php

declare(strict_types=1);

/**
 * One-shot: add fw_projects.long_term_fm_team_size if missing.
 * Usage (on API host): php scripts/run-add-long-term-fm-team-size.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$conn = App\Database\Database::getConnection();

$col = $conn->executeQuery(
    "SHOW COLUMNS FROM fw_projects LIKE 'long_term_fm_team_size'"
)->fetchOne();

if ($col) {
    echo "COLUMN exists\n";
} else {
    $conn->executeStatement(
        'ALTER TABLE `fw_projects`
         ADD COLUMN `long_term_fm_team_size` VARCHAR(20) NULL DEFAULT NULL AFTER `healthcare_services`'
    );
    echo "COLUMN created\n";
}

$check = $conn->executeQuery(
    "SHOW COLUMNS FROM fw_projects LIKE 'long_term_fm_team_size'"
)->fetchAssociative();

echo 'Field=' . ($check['Field'] ?? '?')
    . ' Type=' . ($check['Type'] ?? '?')
    . ' Null=' . ($check['Null'] ?? '?')
    . "\n";
