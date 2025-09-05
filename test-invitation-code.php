<?php

require_once 'vendor/autoload.php';

use App\Services\EmailService;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__, 'env.development');
$dotenv->load();

// Create logger
$logger = new Logger('test');
$logger->pushHandler(new StreamHandler('logs/app.log', Logger::DEBUG));

try {
    echo "Testing EmailService with invitation code...\n";
    
    $emailService = new EmailService($logger);
    
    // Test sending invitation email with invitation code
    $result = $emailService->sendWorkerInvitation(
        'serg.kostyuk@gmail.com',
        'Test',
        'User',
        'INV-12345-ABCDE', // Test invitation code
        'phpmailer',
        'TempPass123!'
    );
    
    if ($result) {
        echo "✅ Email sent successfully with invitation code!\n";
        echo "Invitation code: INV-12345-ABCDE\n";
        echo "Temporary password: TempPass123!\n";
    } else {
        echo "❌ Failed to send email\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
