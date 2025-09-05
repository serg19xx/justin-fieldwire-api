<?php

require_once __DIR__ . '/vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

try {
    echo "Testing PHPMailer SMTP...\n";
    
    // Create logger
    $logger = new Monolog\Logger('test');
    $logger->pushHandler(new Monolog\Handler\StreamHandler('php://stdout', Monolog\Logger::DEBUG));
    
    // Create EmailService
    $emailService = new App\Services\EmailService($logger);
    
    echo "✅ EmailService created\n";
    
    // Show SMTP settings
    echo "📧 SMTP Settings:\n";
    echo "  Host: " . ($_ENV['SMTP_HOST'] ?? 'NOT SET') . "\n";
    echo "  Port: " . ($_ENV['SMTP_PORT'] ?? 'NOT SET') . "\n";
    echo "  Username: " . ($_ENV['SMTP_USERNAME'] ?? 'NOT SET') . "\n";
    echo "  Password: " . (isset($_ENV['SMTP_PASSWORD']) ? 'SET' : 'NOT SET') . "\n";
    echo "  Encryption: " . ($_ENV['SMTP_ENCRYPTION'] ?? 'NOT SET') . "\n";
    
    // Test PHPMailer directly
    $to = "serg.kostyuk@gmail.com";
    $subject = "Test PHPMailer SMTP Email";
    $message = "This is a test email from FieldWire API to verify PHPMailer SMTP is working.";
    $toName = "Sergey Kostyuk";
    
    echo "\n📧 Sending test email to: $to\n";
    echo "📧 Subject: $subject\n";
    
    $result = $emailService->sendEmail($to, $subject, $message, $toName, 'phpmailer');
    
    if ($result) {
        echo "✅ Email sent successfully via PHPMailer SMTP!\n";
        echo "📬 Check your inbox at $to\n";
    } else {
        echo "❌ Failed to send email via PHPMailer SMTP\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
?>

