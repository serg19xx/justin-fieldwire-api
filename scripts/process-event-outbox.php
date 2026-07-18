<?php

declare(strict_types=1);

/**
 * CRON / CLI worker for fw_event_outbox.
 *
 * Usage:
 *   php scripts/process-event-outbox.php [limit]
 *
 * Example crontab (every minute):
 *   * * * * * cd /path/to/justin-fieldwire-api && php scripts/process-event-outbox.php 100 >> storage/logs/outbox-cron.log 2>&1
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use App\Services\EventOutboxProcessor;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

$limit = isset($argv[1]) ? (int) $argv[1] : 100;
if ($limit <= 0) {
    $limit = 100;
}

$logger = new Logger('outbox-cron');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

$processor = new EventOutboxProcessor($logger);
$stats = $processor->processPending($limit);

echo sprintf(
    "[%s] outbox processed=%d sent=%d skipped=%d errors=%d\n",
    date('c'),
    $stats['processed'],
    $stats['sent'],
    $stats['skipped'],
    $stats['errors']
);

exit($stats['errors'] > 0 && $stats['sent'] === 0 && $stats['skipped'] === 0 ? 1 : 0);
