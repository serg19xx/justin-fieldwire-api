<?php

declare(strict_types=1);

/**
 * Send a test SMS meeting invite (uses CLIENTS_COMMS_TEST_* redirect from .env).
 *
 * Usage:
 *   php test-meeting-invite.php physician 9736
 *   php test-meeting-invite.php --simulate-reply=2
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Database\Database;
use App\Services\SmsMeetingInviteService;
use App\Services\TwilioService;
use Dotenv\Dotenv;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$logger = new Logger('test');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

$simulateReply = null;
$clientType = 'physician';
$clientId = 1;

foreach ($argv as $i => $arg) {
    if ($i === 0) {
        continue;
    }
    if (str_starts_with($arg, '--simulate-reply=')) {
        $simulateReply = (int) substr($arg, 17);
        continue;
    }
    if ($i === 1) {
        $clientType = $arg;
        continue;
    }
    if ($i === 2) {
        $clientId = (int) $arg;
    }
}

$service = new SmsMeetingInviteService($logger, new TwilioService($logger));

if ($simulateReply !== null) {
    echo "Simulating inbound reply: {$simulateReply}\n";
    $result = $service->handleInboundSms([
        'From' => $_ENV['CLIENTS_COMMS_TEST_PHONE'] ?? '+15145158863',
        'Body' => (string) $simulateReply,
    ]);
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
    exit($result['success'] ? 0 : 1);
}

try {
    $conn = Database::getConnection();
    $tables = $conn->executeQuery("SHOW TABLES LIKE 'fw_sms_meeting_invites'")->fetchOne();
    if ($tables === false) {
        echo "Creating fw_sms_meeting_invites table...\n";
        $sql = file_get_contents(__DIR__ . '/scripts/create-sms-meeting-invites-table.sql');
        if ($sql === false) {
            throw new RuntimeException('Migration SQL not found');
        }
        $conn->executeStatement($sql);
        echo "Table created.\n";
    }
} catch (Throwable $e) {
    echo "DB setup failed: " . $e->getMessage() . "\n";
    exit(1);
}

$tomorrow = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');

$result = $service->sendInvite(92, $clientType, $clientId, [
    'meeting_date' => $tomorrow,
    'slots' => ['10:00', '14:00', '15:30'],
    'duration_minutes' => 30,
    'title' => 'FieldWire test call',
    'timezone' => 'America/Toronto',
]);

if (!$result['success']) {
    echo "FAILED: " . ($result['error'] ?? 'unknown') . "\n";
    exit(1);
}

echo "OK — invite id: " . ($result['invite_id'] ?? '?') . "\n";
echo "Sent to: " . ($result['sent_to'] ?? '') . "\n";
echo "Original client phone: " . ($result['original_to'] ?? '') . "\n";
echo "Test mode: " . (($result['test_mode'] ?? false) ? 'yes' : 'no') . "\n";
echo "\nReply from {$result['sent_to']} with 1, 2, or 3.\n";
echo "Simulate locally: php test-meeting-invite.php --simulate-reply=2\n";
