<?php

declare(strict_types=1);

/**
 * Generate (and optionally send) daily operational reports from live DB data.
 *
 * Usage:
 *   php scripts/run-daily-operational-report.php --dry-run
 *   php scripts/run-daily-operational-report.php --date=2026-07-17 --dry-run
 *   php scripts/run-daily-operational-report.php --date=2026-07-17 --send
 *
 * Default date: yesterday (server timezone).
 * --dry-run prints report text to stdout and does not email.
 * --send generates + delivers via NotificationDispatcher (email).
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use App\Services\DailyOperationalReportService;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

$opts = getopt('', ['date::', 'dry-run', 'send', 'help']);

if (isset($opts['help'])) {
    echo <<<HELP
Usage:
  php scripts/run-daily-operational-report.php [--date=YYYY-MM-DD] [--dry-run|--send]

Options:
  --date=YYYY-MM-DD   Report date (default: yesterday)
  --dry-run           Generate + print to stdout (no email)
  --send              Generate + email ONLY the global summary to Admin/PM
  --help              Show this help

HELP;
    exit(0);
}

$date = isset($opts['date']) && is_string($opts['date']) && $opts['date'] !== ''
    ? $opts['date']
    : (new DateTimeImmutable('yesterday'))->format('Y-m-d');

$send = isset($opts['send']);
$dryRun = isset($opts['dry-run']) || !$send;

if ($send && isset($opts['dry-run'])) {
    fwrite(STDERR, "Use either --dry-run or --send, not both.\n");
    exit(1);
}

$logger = new Logger('daily-op-report');
$logger->pushHandler(new StreamHandler('php://stderr', Logger::INFO));

try {
    $service = new DailyOperationalReportService($logger);
    $result = $service->run($date, $dryRun, $send);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

echo sprintf(
    "[%s] daily operational report date=%s dry_run=%s generated=%d sent=%d failed=%d\n",
    date('c'),
    $result['date'],
    $result['dry_run'] ? 'yes' : 'no',
    $result['generated'],
    $result['sent'],
    $result['failed']
);

foreach ($result['reports'] as $report) {
    $scope = $report['scope'] ?? 'project';
    $label = $scope === 'global'
        ? 'GLOBAL SUMMARY'
        : "project #{$report['project_id']} {$report['project_name']}";
    echo "\n========== {$label} (id={$report['id']}, status={$report['status']}) ==========\n";
    echo $report['text'] . "\n";
}

if ($result['reports'] === []) {
    echo "No reports generated for this date (no Active projects / no activity).\n";
}

exit($result['failed'] > 0 ? 1 : 0);
