<?php

// Define application start time
define('APP_START_TIME', time());

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Initialize application
$config = new App\Config\Config();
$app = new App\Bootstrap\Application($config);

// Start the application
Flight::start();
