<?php

declare(strict_types=1);

/**
 * Idempotent migration: add snapshot/archive columns to fw_operational_daily_reports.
 * Safe to run multiple times.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$conn = App\Database\Database::getConnection();

$tableExists = (bool) $conn->executeQuery(
    'SHOW TABLES LIKE ' . $conn->quote('fw_operational_daily_reports')
)->fetchOne();

if (!$tableExists) {
    fwrite(STDERR, "Table fw_operational_daily_reports does not exist. Run run-add-operational-daily-reports.php first.\n");
    exit(1);
}

$columns = [
    'report_type' => "ADD COLUMN `report_type` VARCHAR(20) NOT NULL DEFAULT 'daily' AFTER `project_id`",
    'scope' => "ADD COLUMN `scope` VARCHAR(20) NOT NULL DEFAULT 'project' AFTER `report_type`",
    'title' => "ADD COLUMN `title` VARCHAR(255) NULL DEFAULT NULL AFTER `scope`",
    'rendered_html' => "ADD COLUMN `rendered_html` MEDIUMTEXT NULL DEFAULT NULL AFTER `payload_json`",
];

foreach ($columns as $name => $ddl) {
    $exists = (bool) $conn->executeQuery(
        "SHOW COLUMNS FROM fw_operational_daily_reports LIKE " . $conn->quote($name)
    )->fetchOne();
    if ($exists) {
        echo "Column {$name} already exists\n";
        continue;
    }
    $conn->executeStatement("ALTER TABLE fw_operational_daily_reports {$ddl}");
    echo "Column {$name} added\n";
}

$indexExists = (bool) $conn->executeQuery(
    "SHOW INDEX FROM fw_operational_daily_reports WHERE Key_name = " . $conn->quote('idx_fw_op_daily_reports_type_date')
)->fetchOne();

if (!$indexExists) {
    $conn->executeStatement(
        'ALTER TABLE fw_operational_daily_reports
         ADD KEY `idx_fw_op_daily_reports_type_date` (`report_type`, `report_date`)'
    );
    echo "Index idx_fw_op_daily_reports_type_date added\n";
} else {
    echo "Index idx_fw_op_daily_reports_type_date already exists\n";
}

echo "Done\n";
