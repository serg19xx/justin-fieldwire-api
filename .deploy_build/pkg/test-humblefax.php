<?php

declare(strict_types=1);

/**
 * Verify HumbleFax credentials (creates a tmp fax draft — does NOT send unless --send is passed).
 *
 * Usage:
 *   php test-humblefax.php
 *   php test-humblefax.php --send=12895551234
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Services\HumbleFaxService;
use Dotenv\Dotenv;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$logger = new Logger('test');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

$service = new HumbleFaxService($logger);

echo "HumbleFax configured: " . ($service->isConfigured() ? 'YES' : 'NO') . "\n";

if (!$service->isConfigured()) {
    echo "Set HUMBLEFAX_ACCESS_KEY, HUMBLEFAX_SECRET_KEY, HUMBLEFAX_CALLER_ID in .env\n";
    exit(1);
}

$sendArg = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--send=')) {
        $sendArg = substr($arg, 7);
    }
}

// Auth check: create tmp fax with a placeholder recipient (not sent yet)
$recipient = $sendArg !== null && $sendArg !== ''
    ? $sendArg
    : '12025550100';

$created = $service->createTmpFax(
    [$recipient],
    [
        'toName' => 'API test',
        'subject' => 'FieldWire HumbleFax credential test',
        'message' => 'Draft only — not sent unless --send=NUMBER is used.',
        'includeCoversheet' => true,
    ],
);

if (!$created['success']) {
    echo "❌ createTmpFax failed: " . ($created['error'] ?? 'unknown') . "\n";
    exit(1);
}

echo "✅ Auth OK — tmpFax id: " . $created['tmp_fax_id'] . "\n";

if ($sendArg === null || $sendArg === '') {
    echo "Draft created only. To send a real fax: php test-humblefax.php --send=1XXXXXXXXXX\n";
    exit(0);
}

$sent = $service->sendTmpFax($created['tmp_fax_id']);
if (!$sent['success']) {
    echo "❌ send failed: " . ($sent['error'] ?? 'unknown') . "\n";
    exit(1);
}

echo "✅ Fax queued for send to {$sendArg}\n";
echo json_encode($sent['data'] ?? [], JSON_PRETTY_PRINT) . "\n";
