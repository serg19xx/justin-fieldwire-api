<?php

require_once __DIR__ . '/vendor/autoload.php';

// Load environment variables
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} catch (\Exception $e) {
    echo "ENV ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

echo "=== Email Domain Test ===\n";
echo "Current environment variables:\n";
echo "APP_URL: " . ($_ENV['APP_URL'] ?? 'NOT SET') . "\n";
echo "SENDGRID_FROM_EMAIL: " . ($_ENV['SENDGRID_FROM_EMAIL'] ?? 'NOT SET') . "\n";
echo "SENDGRID_FROM_NAME: " . ($_ENV['SENDGRID_FROM_NAME'] ?? 'NOT SET') . "\n";
echo "SENDGRID_API_KEY: " . (isset($_ENV['SENDGRID_API_KEY']) ? 'SET (length: ' . strlen($_ENV['SENDGRID_API_KEY']) . ')' : 'NOT SET') . "\n";

// Test SendGrid API directly
if (isset($_ENV['SENDGRID_API_KEY']) && $_ENV['SENDGRID_API_KEY'] !== 'your_sendgrid_api_key_here') {
    echo "\n=== Testing SendGrid API ===\n";
    
    $email = new \SendGrid\Mail\Mail();
    $email->setFrom($_ENV['SENDGRID_FROM_EMAIL'], $_ENV['SENDGRID_FROM_NAME']);
    $email->setSubject("Test Email - Domain Check");
    $email->addTo("serg.kostyuk@gmail.com", "Test User");
    
    // Create HTML content
    $htmlContent = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>FieldWire Verification Code</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f4f4f4; }
            .container { max-width: 600px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
            .header { text-align: center; border-bottom: 2px solid #007bff; padding-bottom: 20px; margin-bottom: 30px; }
            .header h1 { color: #007bff; margin: 0; }
            .code { background: #f8f9fa; border: 2px dashed #007bff; padding: 20px; text-align: center; font-size: 24px; font-weight: bold; color: #007bff; margin: 20px 0; border-radius: 5px; }
            .footer { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; color: #666; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>FieldWire</h1>
                <p>Your verification code</p>
            </div>
            
            <p>Hello,</p>
            <p>You have requested a verification code for your FieldWire account.</p>
            
            <div class="code">123456</div>
            
            <p>This code will expire in 10 minutes.</p>
            <p>If you did not request this code, please ignore this email.</p>
            
            <div class="footer">
                <p>This email was sent from FieldWire API</p>
                <p>Domain: ' . ($_ENV['APP_URL'] ?? 'localhost') . '</p>
            </div>
        </div>
    </body>
    </html>';
    
    $email->addContent("text/html", $htmlContent);
    $email->addContent("text/plain", "Your verification code is: 123456\n\nThis code will expire in 10 minutes.\n\nIf you did not request this code, please ignore this email.");
    
    $sendgrid = new \SendGrid($_ENV['SENDGRID_API_KEY']);
    
    try {
        $response = $sendgrid->send($email);
        echo "SendGrid Response Code: " . $response->statusCode() . "\n";
        echo "SendGrid Response Headers: " . json_encode($response->headers()) . "\n";
        echo "SendGrid Response Body: " . $response->body() . "\n";
        
        if ($response->statusCode() >= 200 && $response->statusCode() < 300) {
            echo "✅ Email sent successfully via SendGrid API!\n";
        } else {
            echo "❌ Email failed to send via SendGrid API\n";
        }
    } catch (Exception $e) {
        echo "❌ SendGrid Error: " . $e->getMessage() . "\n";
    }
} else {
    echo "\n❌ SendGrid API key not configured properly\n";
}

echo "\n=== Recommendations ===\n";
echo "1. Use a proper domain email (not @me.com or @gmail.com)\n";
echo "2. Set up SPF, DKIM, and DMARC records for your domain\n";
echo "3. Use a professional email address like noreply@medicalcontractor.ca\n";
echo "4. Consider using a dedicated email service like SendGrid with domain authentication\n";
