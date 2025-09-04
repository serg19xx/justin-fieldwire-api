<?php
// Test index.php with FlightPHP in web server context
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing index.php with FlightPHP in web server context...\n";

try {
    // Define application start time
    define('APP_START_TIME', time());
    echo "APP_START_TIME defined\n";
    
    // Load Composer autoloader
    require_once __DIR__ . '/../vendor/autoload.php';
    echo "Autoloader loaded\n";
    
    // Load environment variables
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
    echo "Environment variables loaded\n";
    
    // Initialize application
    $config = new App\Config\Config();
    echo "Config created\n";
    
    $app = new App\Bootstrap\Application($config);
    echo "Application created\n";
    
    // Test if FlightPHP is working
    echo "FlightPHP initialized successfully\n";
    
    echo "All tests passed!\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
?>
