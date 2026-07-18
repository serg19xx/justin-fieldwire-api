<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$connection = App\Database\Database::getConnection();
$exists = $connection->executeQuery(
    "SHOW COLUMNS FROM fw_projects LIKE 'operational_hours'"
)->fetchOne();

if (!$exists) {
    $connection->executeStatement(
        'ALTER TABLE `fw_projects`
         ADD COLUMN `operational_hours` JSON NULL DEFAULT NULL AFTER `hr_vision`'
    );
}

echo $exists ? "COLUMN exists\n" : "COLUMN created\n";
