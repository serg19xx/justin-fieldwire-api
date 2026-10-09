<?php

declare(strict_types=1);

/**
 * SMS reminders to clock in / clock out on the worker Timesheet.
 *
 * Usage:
 *   php scripts/run-work-day-reminders.php --dry-run
 *   php scripts/run-work-day-reminders.php --dry-run --at="2026-10-08 08:06"
 *   php scripts/run-work-day-reminders.php --send
 *
 * Intended cron: every 5 minutes with --send.
 * --dry-run (default) prints who would get a text and records nothing.
 * --at simulates the wall-clock time (America/Toronto); only allowed with --dry-run.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use App\Database\Database;
use App\Services\WorkClockService;
use App\Services\WorkerDayReminderService;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

$opts = getopt('', ['dry-run', 'send', 'at:', 'help']);

if (isset($opts['help'])) {
    echo <<<HELP
Usage:
  php scripts/run-work-day-reminders.php [--dry-run|--send] [--at="YYYY-MM-DD HH:MM"]

Options:
  --dry-run   Print reminders that are due now, send nothing (default)
  --send      Send due reminders by SMS and record them (one per kind per worker per day)
  --at        Simulated local time for --dry-run
  --help      Show this help

HELP;
    exit(0);
}

$send = isset($opts['send']);
if ($send && isset($opts['dry-run'])) {
    fwrite(STDERR, "Use either --dry-run or --send, not both.\n");
    exit(1);
}

$clock = new WorkClockService();
$now = null;
if (isset($opts['at']) && is_string($opts['at']) && $opts['at'] !== '') {
    if ($send) {
        fwrite(STDERR, "--at is only allowed with --dry-run.\n");
        exit(1);
    }
    try {
        $now = new DateTimeImmutable($opts['at'], $clock->timezone());
    } catch (Throwable) {
        fwrite(STDERR, "Invalid --at value.\n");
        exit(1);
    }
}

$logger = new Logger('work-day-reminders');
$logger->pushHandler(new StreamHandler('php://stderr', Logger::WARNING));

try {
    $service = new WorkerDayReminderService($logger, null, $clock);
    $result = $service->run(Database::getConnection(), $send, $now);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

$failed = 0;
foreach ($result['actions'] as $action) {
    if ($action['status'] !== 'sent' && $action['status'] !== 'dry_run') {
        $failed++;
    }
}

echo sprintf(
    "[%s] work day reminders date=%s now=%s mode=%s checked=%d due=%d failed=%d%s\n",
    date('c'),
    $result['work_date'],
    $result['now'],
    $send ? 'send' : 'dry-run',
    $result['checked'],
    count($result['actions']),
    $failed,
    $result['skipped_reason'] !== null ? ' skipped=' . $result['skipped_reason'] : ''
);

foreach ($result['actions'] as $action) {
    echo sprintf(
        "  user=%d kind=%s scheduled=%s phone=%s status=%s\n    %s\n",
        $action['user_id'],
        $action['kind'],
        $action['scheduled_time'],
        $action['phone'] ?? '-',
        $action['status'],
        $action['body']
    );
}

exit($failed > 0 ? 1 : 0);
