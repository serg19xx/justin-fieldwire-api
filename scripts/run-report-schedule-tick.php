<?php

declare(strict_types=1);

/**
 * Schedule tick for event-driven operational reports.
 *
 * Evaluates any enabled rule that has create_report + crontab-like time_conditions.
 * Periodicity comes from time_conditions.frequency (daily|weekly|monthly).
 *
 * Run every 5-15 minutes via crontab. Fires only when the window matches and the
 * period has not fired yet (fw_report_schedule_fires).
 *
 * Usage:
 *   php scripts/run-report-schedule-tick.php
 *   php scripts/run-report-schedule-tick.php --dry-run
 *
 * Crontab example (every 10 minutes):
 *   0,10,20,30,40,50 * * * * cd /path/to/justin-fieldwire-api && php scripts/run-report-schedule-tick.php >> storage/logs/report-schedule-tick.log 2>&1
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use App\Services\ReportScheduleTickService;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

$opts = getopt('', ['dry-run', 'help']);

if (isset($opts['help'])) {
    echo <<<HELP
Usage:
  php scripts/run-report-schedule-tick.php [--dry-run]

Options:
  --dry-run   Print matching rules without claiming fires / logging events
  --help      Show this help

HELP;
    exit(0);
}

$logger = new Logger('report-schedule-tick');
$logger->pushHandler(new StreamHandler('php://stderr', Logger::INFO));

$dryRun = isset($opts['dry-run']);

try {
    if ($dryRun) {
        echo "[" . date('c') . "] dry-run: would evaluate REPORT_* schedule rules\n";
        exit(0);
    }

    $service = new ReportScheduleTickService($logger);
    $result = $service->run();
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

echo sprintf(
    "[%s] report schedule tick checked=%d fired=%d skipped=%d errors=%d\n",
    date('c'),
    $result['checked'],
    $result['fired'],
    $result['skipped'],
    $result['errors']
);

foreach ($result['details'] as $detail) {
    echo sprintf(
        "  - %s: %s%s\n",
        $detail['event_type'] ?? '?',
        $detail['status'] ?? '?',
        isset($detail['period_key']) ? ' (' . $detail['period_key'] . ')' : ''
    );
}

exit(($result['errors'] ?? 0) > 0 ? 1 : 0);
