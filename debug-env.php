<?php

require_once __DIR__ . '/vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

echo "=== ENVIRONMENT VARIABLES ===\n";
echo "SMTP_USERNAME: " . ($_ENV['SMTP_USERNAME'] ?? 'NOT SET') . "\n";
echo "SMTP_PASSWORD: " . (isset($_ENV['SMTP_PASSWORD']) ? 'SET (length: ' . strlen($_ENV['SMTP_PASSWORD']) . ')' : 'NOT SET') . "\n";
echo "SMTP_HOST: " . ($_ENV['SMTP_HOST'] ?? 'NOT SET') . "\n";
echo "SMTP_PORT: " . ($_ENV['SMTP_PORT'] ?? 'NOT SET') . "\n";
echo "SMTP_ENCRYPTION: " . ($_ENV['SMTP_ENCRYPTION'] ?? 'NOT SET') . "\n";

echo "\n=== RAW .env FILE CONTENT ===\n";
$envContent = file_get_contents(__DIR__ . '/.env');
$lines = explode("\n", $envContent);
foreach ($lines as $line) {
    if (strpos($line, 'SMTP_') === 0) {
        echo $line . "\n";
    }
}
?>

