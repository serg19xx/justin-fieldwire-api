<?php
// Test real index.php in web server context
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing real index.php in web server context...\n";

try {
    // Define application start time
    define('APP_START_TIME', time());
    echo "APP_START_TIME defined\n";
    
    // Direct file logging for debugging
    file_put_contents(__DIR__ . '/../logs/app.log', date('Y-m-d H:i:s') . ' - test-real-index.php loaded' . PHP_EOL, FILE_APPEND);
    
    // Load Composer autoloader
    require_once __DIR__ . '/../vendor/autoload.php';
    echo "Autoloader loaded\n";
    
    // Load environment variables
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
    echo "Environment variables loaded\n";
    
    // Initialize application
    file_put_contents(__DIR__ . '/../logs/app.log', date('Y-m-d H:i:s') . ' - About to create Config' . PHP_EOL, FILE_APPEND);
    $config = new App\Config\Config();
    echo "Config created\n";
    
    file_put_contents(__DIR__ . '/../logs/app.log', date('Y-m-d H:i:s') . ' - Config created, about to create Application' . PHP_EOL, FILE_APPEND);
    $app = new App\Bootstrap\Application($config);
    echo "Application created\n";
    
    file_put_contents(__DIR__ . '/../logs/app.log', date('Y-m-d H:i:s') . ' - Application created successfully' . PHP_EOL, FILE_APPEND);
    
    echo "All tests passed!\n";
    
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    file_put_contents(__DIR__ . '/../logs/app.log', date('Y-m-d H:i:s') . ' - ERROR creating Application: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
}
?>
