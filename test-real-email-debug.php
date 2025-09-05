<?php

require_once 'vendor/autoload.php';

use App\Services\EmailService;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__, 'env.development');
$dotenv->load();

echo "=== TESTING REAL EMAIL SEND ===\n";
echo "FRONTEND_URL: " . ($_ENV['FRONTEND_URL'] ?? 'NOT SET') . "\n";

// Create logger
$logger = new Logger('test');
$logger->pushHandler(new StreamHandler('logs/app.log', Logger::DEBUG));

try {
    $emailService = new EmailService($logger);
    
    // Send real email to see what URL is generated
    $result = $emailService->sendWorkerInvitation(
        'serg.kostyuk@gmail.com',
        'Debug',
        'Test',
        'debug-token-12345',
        'phpmailer',
        'TempPass123!'
    );
    
    if ($result) {
        echo "✅ Email sent successfully!\n";
        echo "Check your email to see the URL in the message.\n";
    } else {
        echo "❌ Failed to send email\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
