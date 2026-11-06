<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$conn = App\Database\Database::getConnection();

$sql = (string) file_get_contents(__DIR__ . '/create-client-sms-log-table.sql');
$conn->executeStatement($sql);

echo 'TABLE fw_client_sms_log: ' . ($conn->fetchOne("SHOW TABLES LIKE 'fw_client_sms_log'") ? 'ok' : 'missing') . "\n";
