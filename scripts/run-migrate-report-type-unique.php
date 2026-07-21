<?php

declare(strict_types=1);

/**
 * Migrate operational reports unique key + create schedule fires table.
 * Idempotent — safe to re-run.
 *
 *   php scripts/run-migrate-report-type-unique.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use App\Database\Database;

$connection = Database::getConnection();

echo "Migrating fw_operational_daily_reports unique key...\n";

$hasOld = (bool) $connection->fetchOne(
    "SHOW INDEX FROM fw_operational_daily_reports WHERE Key_name = 'uniq_fw_op_daily_reports_date_project'"
);
$hasNew = (bool) $connection->fetchOne(
    "SHOW INDEX FROM fw_operational_daily_reports WHERE Key_name = 'uniq_fw_op_reports_date_project_type'"
);

if ($hasOld && !$hasNew) {
    $connection->executeStatement(
        'ALTER TABLE fw_operational_daily_reports DROP INDEX uniq_fw_op_daily_reports_date_project'
    );
    echo "  Dropped uniq_fw_op_daily_reports_date_project\n";
}

if (!$hasNew) {
    $connection->executeStatement(
        'ALTER TABLE fw_operational_daily_reports
         ADD UNIQUE KEY uniq_fw_op_reports_date_project_type (report_date, project_id, report_type)'
    );
    echo "  Added uniq_fw_op_reports_date_project_type\n";
} else {
    echo "  Unique key already includes report_type\n";
}

echo "Ensuring fw_report_schedule_fires...\n";
$connection->executeStatement(
    "CREATE TABLE IF NOT EXISTS fw_report_schedule_fires (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      event_type VARCHAR(64) NOT NULL,
      period_key VARCHAR(32) NOT NULL,
      fired_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      event_log_id BIGINT UNSIGNED NULL DEFAULT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uq_report_schedule_fire (event_type, period_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);
echo "Done.\n";
