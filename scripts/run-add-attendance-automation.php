<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$conn = App\Database\Database::getConnection();

$columns = [
    'start_source' => "VARCHAR(8) DEFAULT NULL AFTER `locked_by`",
    'end_source' => "VARCHAR(8) DEFAULT NULL AFTER `start_source`",
    'attendance_flags' => "VARCHAR(191) DEFAULT NULL AFTER `end_source`",
    'scheduled_start_time' => 'TIME DEFAULT NULL AFTER `attendance_flags`',
    'scheduled_end_time' => 'TIME DEFAULT NULL AFTER `scheduled_start_time`',
    'extended_until' => 'TIME DEFAULT NULL AFTER `scheduled_end_time`',
    'extended_open' => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `extended_until`',
    'auto_closed_at' => 'DATETIME(3) DEFAULT NULL AFTER `extended_open`',
];

$existing = array_map('strtolower', $conn->fetchFirstColumn('SHOW COLUMNS FROM `fw_worker_day_work`'));

foreach ($columns as $name => $definition) {
    if (in_array($name, $existing, true)) {
        echo "COLUMN {$name} exists\n";
        continue;
    }
    $conn->executeStatement("ALTER TABLE `fw_worker_day_work` ADD COLUMN `{$name}` {$definition}");
    echo "COLUMN {$name} added\n";
}

$sql = (string) file_get_contents(__DIR__ . '/create-attendance-tables.sql');
foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
    if (preg_match('/CREATE TABLE/i', $statement) === 1) {
        $conn->executeStatement($statement);
    }
}

foreach (['fw_worker_day_site_events', 'fw_worker_action_tokens', 'fw_attendance_digests'] as $table) {
    echo "TABLE {$table}: " . ($conn->fetchOne("SHOW TABLES LIKE '{$table}'") ? 'ok' : 'missing') . "\n";
}
