<?php

declare(strict_types=1);

/**
 * Smoke checks for NotificationDispatcher without sending real email/SMS.
 * Usage: php scripts/smoke-notification-dispatcher.php [user_id]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use App\Database\Database;
use App\Services\NotificationDispatcher;
use App\Services\NotificationPreferenceService;
use App\ValueObjects\NotificationRequest;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

$userId = isset($argv[1]) ? (int) $argv[1] : 0;
$conn = Database::getConnection();

if ($userId <= 0) {
    $userId = (int) $conn->fetchOne(
        'SELECT id FROM fw_users WHERE archived_at IS NULL ORDER BY id ASC LIMIT 1'
    );
}

if ($userId <= 0) {
    fwrite(STDERR, "No user found for smoke test\n");
    exit(1);
}

$logger = new Logger('smoke-notifications');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::WARNING));

$prefs = new NotificationPreferenceService($logger);
$original = $prefs->getForUser($userId);

$dispatcher = new NotificationDispatcher($logger);

$correlation = 'smoke-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));

echo "Using user_id={$userId}\n";

// 1) Master opt-out should skip email
$prefs->updateForUser($userId, ['outbound_enabled' => false]);
$r1 = $dispatcher->dispatch(new NotificationRequest(
    recipientUserId: $userId,
    type: 'smoke_outbound_off',
    title: 'Smoke outbound off',
    message: 'Should be skipped',
    channels: ['email'],
    correlationId: $correlation . '-off',
));
echo 'outbound_off overall=' . $r1->overallStatus . ' channel=' . $r1->channels[0]->status
    . ' code=' . ($r1->channels[0]->errorCode ?? '') . "\n";
if ($r1->channels[0]->status !== 'skipped') {
    $prefs->updateForUser($userId, $original);
    fwrite(STDERR, "FAIL: expected skipped when outbound disabled\n");
    exit(2);
}

// 2) Channel opt-out
$prefs->updateForUser($userId, [
    'outbound_enabled' => true,
    'email_enabled' => false,
    'sms_enabled' => true,
    'push_enabled' => true,
]);
$r2 = $dispatcher->dispatch(new NotificationRequest(
    recipientUserId: $userId,
    type: 'smoke_email_off',
    title: 'Smoke email off',
    message: 'Should be skipped',
    channels: ['email'],
    correlationId: $correlation . '-email-off',
));
echo 'email_off overall=' . $r2->overallStatus . ' channel=' . $r2->channels[0]->status
    . ' code=' . ($r2->channels[0]->errorCode ?? '') . "\n";
if ($r2->channels[0]->status !== 'skipped') {
    $prefs->updateForUser($userId, $original);
    fwrite(STDERR, "FAIL: expected skipped when email disabled\n");
    exit(3);
}

// 3) Event opt-in defaults OFF. This also verifies idempotency without external delivery.
$prefs->updateForUser($userId, [
    'outbound_enabled' => true,
    'email_enabled' => true,
    'sms_enabled' => true,
    'push_enabled' => true,
]);

$r3 = $dispatcher->dispatch(new NotificationRequest(
    recipientUserId: $userId,
    type: 'SMOKE_EVENT_NOT_CONFIGURED',
    title: 'Smoke idempotency',
    message: 'First dispatch',
    channels: ['email'],
    correlationId: $correlation . '-idem',
));
echo 'idem_first overall=' . $r3->overallStatus . ' channel=' . $r3->channels[0]->status
    . ' dup=' . ($r3->channels[0]->wasDuplicate ? '1' : '0') . "\n";
if ($r3->channels[0]->status !== 'skipped') {
    $prefs->updateForUser($userId, $original);
    fwrite(STDERR, "FAIL: a new event must default to skipped\n");
    exit(4);
}

$r4 = $dispatcher->dispatch(new NotificationRequest(
    recipientUserId: $userId,
    type: 'SMOKE_EVENT_NOT_CONFIGURED',
    title: 'Smoke idempotency',
    message: 'Second dispatch',
    channels: ['email'],
    correlationId: $correlation . '-idem',
));
echo 'idem_second overall=' . $r4->overallStatus . ' channel=' . $r4->channels[0]->status
    . ' dup=' . ($r4->channels[0]->wasDuplicate ? '1' : '0') . "\n";

if (!$r4->channels[0]->wasDuplicate) {
    $prefs->updateForUser($userId, $original);
    fwrite(STDERR, "FAIL: expected duplicate on second idempotent dispatch\n");
    exit(5);
}

$notifCount = (int) $conn->fetchOne(
    'SELECT COUNT(*) FROM fw_notifications WHERE correlation_id = ? AND channel = ?',
    [$correlation . '-idem', 'email']
);
echo "idem_rows={$notifCount}\n";
if ($notifCount !== 1) {
    $prefs->updateForUser($userId, $original);
    fwrite(STDERR, "FAIL: expected exactly 1 notification row for idempotency key group\n");
    exit(6);
}

// Restore original preferences
$prefs->updateForUser($userId, $original);
echo "OK preferences restored\n";
echo "SMOKE PASSED\n";
