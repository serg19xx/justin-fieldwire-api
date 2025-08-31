<?php

// Define application start time
define('APP_START_TIME', time());

// Direct file logging for debugging
file_put_contents(__DIR__ . '/../logs/app.log', date('Y-m-d H:i:s') . ' - index.php loaded' . PHP_EOL, FILE_APPEND);

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Initialize application
file_put_contents(__DIR__ . '/../logs/app.log', date('Y-m-d H:i:s') . ' - About to create Config' . PHP_EOL, FILE_APPEND);
$config = new App\Config\Config();
file_put_contents(__DIR__ . '/../logs/app.log', date('Y-m-d H:i:s') . ' - Config created, about to create Application' . PHP_EOL, FILE_APPEND);
try {
    $app = new App\Bootstrap\Application($config);
    file_put_contents(__DIR__ . '/../logs/app.log', date('Y-m-d H:i:s') . ' - Application created successfully' . PHP_EOL, FILE_APPEND);
} catch (Exception $e) {
    file_put_contents(__DIR__ . '/../logs/app.log', date('Y-m-d H:i:s') . ' - ERROR creating Application: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
    throw $e;
}

// Start the application
Flight::start();
