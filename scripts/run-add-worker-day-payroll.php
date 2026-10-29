<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$conn = App\Database\Database::getConnection();

$columns = [
    'adjusted_start_time' => 'TIME DEFAULT NULL AFTER `work_end_photo`',
    'adjusted_end_time' => 'TIME DEFAULT NULL AFTER `adjusted_start_time`',
    'payroll_note' => 'TEXT DEFAULT NULL AFTER `adjusted_end_time`',
    'payroll_updated_by' => 'BIGINT(20) UNSIGNED DEFAULT NULL AFTER `payroll_note`',
    'payroll_updated_at' => 'DATETIME(3) DEFAULT NULL AFTER `payroll_updated_by`',
    'locked_at' => 'DATETIME(3) DEFAULT NULL AFTER `payroll_updated_at`',
    'locked_by' => 'BIGINT(20) UNSIGNED DEFAULT NULL AFTER `locked_at`',
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
