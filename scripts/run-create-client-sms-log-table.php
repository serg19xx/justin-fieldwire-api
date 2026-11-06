<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$conn = App\Database\Database::getConnection();

$tables = [
    'fw_client_sms_log' => 'create-client-sms-log-table.sql',
    'fw_client_email_log' => 'create-client-email-log-table.sql',
];

foreach ($tables as $table => $file) {
    $conn->executeStatement((string) file_get_contents(__DIR__ . '/' . $file));
    echo "TABLE {$table}: " . ($conn->fetchOne("SHOW TABLES LIKE '{$table}'") ? 'ok' : 'missing') . "\n";
}
