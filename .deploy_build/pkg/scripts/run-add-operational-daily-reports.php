<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$conn = App\Database\Database::getConnection();

$exists = (bool) $conn->executeQuery(
    'SHOW TABLES LIKE ' . $conn->quote('fw_operational_daily_reports')
)->fetchOne();

if ($exists) {
    echo "TABLE fw_operational_daily_reports exists\n";
    exit(0);
}

$sql = file_get_contents(__DIR__ . '/add-operational-daily-reports-table.sql');
if ($sql === false) {
    fwrite(STDERR, "Cannot read SQL file\n");
    exit(1);
}

$conn->executeStatement($sql);
echo "TABLE fw_operational_daily_reports created\n";
