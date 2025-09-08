<?php

require_once __DIR__ . '/vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

try {
    echo "Testing SendGrid email sending...\n";
    
    // Create logger
    $logger = new Monolog\Logger('test');
    $logger->pushHandler(new Monolog\Handler\StreamHandler('php://stdout', Monolog\Logger::DEBUG));
    
    // Create EmailService
    $emailService = new App\Services\EmailService($logger);
    
    echo "✅ EmailService created\n";
    
    // Test SendGrid directly
    $to = "serg.kostyuk@gmail.com";
    $subject = "Test SendGrid Email";
    $message = "This is a test email from FieldWire API to verify SendGrid is working.";
    $toName = "Sergey Kostyuk";
    
    echo "📧 Sending test email to: $to\n";
    echo "📧 Subject: $subject\n";
    
    $result = $emailService->sendEmail($to, $subject, $message, $toName, 'sendgrid');
    
    if ($result) {
        echo "✅ Email sent successfully via SendGrid!\n";
        echo "📬 Check your inbox at $to\n";
    } else {
        echo "❌ Failed to send email via SendGrid\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
?>

