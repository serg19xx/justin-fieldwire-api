<?php

// Define application start time
define('APP_START_TIME', time());

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Initialize application
try {
    $config = new App\Config\Config();
    $app = new App\Bootstrap\Application($config);
} catch (\Exception $e) {
    file_put_contents(__DIR__ . '/../logs/app.log', date('Y-m-d H:i:s') . ' - ERROR creating Application: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
    throw $e;
}

// Handle all routes through FlightPHP
Flight::route('*', function() {
    // Get the request URI
    $uri = $_SERVER['REQUEST_URI'];
    
    // Remove query string
    $uri = strtok($uri, '?');
    
    // Handle specific routes
    if ($uri === '/docs') {
        // Serve Swagger UI
        require_once __DIR__ . '/swagger-ui.php';
        return;
    }
    
    if ($uri === '/swagger.json') {
        // Serve Swagger JSON
        require_once __DIR__ . '/swagger.php';
        return;
    }
    
    // For all other routes, let FlightPHP handle them
    // This will trigger the 404 handler if route not found
    Flight::notFound();
});

// Start the application
Flight::start();
