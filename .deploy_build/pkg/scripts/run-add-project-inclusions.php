<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$conn = App\Database\Database::getConnection();

$col = $conn->executeQuery(
    "SHOW COLUMNS FROM fw_projects LIKE 'project_inclusions'"
)->fetchOne();

if ($col) {
    echo "COLUMN exists\n";
    exit(0);
}

$after = $conn->executeQuery(
    "SHOW COLUMNS FROM fw_projects LIKE 'healthcare_services'"
)->fetchOne();

if ($after) {
    $conn->executeStatement(
        'ALTER TABLE `fw_projects`
         ADD COLUMN `project_inclusions` JSON NULL DEFAULT NULL AFTER `healthcare_services`'
    );
} else {
    $conn->executeStatement(
        'ALTER TABLE `fw_projects`
         ADD COLUMN `project_inclusions` JSON NULL DEFAULT NULL'
    );
}

echo "COLUMN created\n";
