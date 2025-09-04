<?php
// Test Flight::start() in web server context
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing Flight::start() in web server context...\n";

try {
    // Load Composer autoloader
    require_once __DIR__ . '/../vendor/autoload.php';
    echo "Autoloader loaded\n";
    
    // Initialize FlightPHP
    \Flight::init();
    echo "FlightPHP initialized\n";
    
    // Create a simple route
    \Flight::route('GET /test', function() {
        echo "Test route works!\n";
    });
    echo "Test route registered\n";
    
    // Start FlightPHP
    echo "About to start FlightPHP...\n";
    \Flight::start();
    echo "FlightPHP started\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
?>
