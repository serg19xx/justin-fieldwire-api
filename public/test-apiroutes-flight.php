<?php
// Test ApiRoutes in FlightPHP context
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing ApiRoutes in FlightPHP context...\n";

try {
    // Load Composer autoloader
    require_once __DIR__ . '/../vendor/autoload.php';
    echo "Autoloader loaded\n";
    
    // Initialize FlightPHP
    \Flight::init();
    echo "FlightPHP initialized\n";
    
    // Create logger
    $logger = new \Monolog\Logger('test');
    echo "Logger created\n";
    
    // Create ApiRoutes
    $routes = new \App\Routes\ApiRoutes($logger);
    echo "ApiRoutes created successfully\n";
    
    echo "All tests passed!\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
?>
