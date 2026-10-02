<?php
// Buffer all accidental output so API JSON stays valid (PHP 8.5 vendor deprecations, notices, etc.).
ob_start();

define('APP_START_TIME', time());

require_once __DIR__ . '/../vendor/autoload.php';

try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
} catch (\Exception $e) {
    error_log('ENV ERROR: ' . $e->getMessage());
}

$appEnv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'development';

// Never print errors to the response body — that breaks JSON clients (local SendGrid list, etc.).
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

// CORS headers are handled by CorsMiddleware in Application.php

try {
    $config = new App\Config\Config();
    $app = new App\Bootstrap\Application($config);
} catch (\Exception $e) {
    error_log('APP ERROR: ' . $e->getMessage());
    error_log('STACK: ' . $e->getTraceAsString());

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit();
}

// Handle all routes through FlightPHP
Flight::route('*', function () {
    $uri = $_SERVER['REQUEST_URI'];
    $uri = strtok($uri, '?');

    // Do not block API routes.
    if (str_starts_with($uri, '/api/')) {
        return;
    }

    if ($uri === '/docs') {
        require_once __DIR__ . '/swagger-ui.php';
        return;
    }

    if ($uri === '/swagger.json') {
        require_once __DIR__ . '/swagger.php';
        return;
    }

    Flight::notFound();
});

// Drop any buffered noise before Flight writes the JSON/body.
if (ob_get_length()) {
    ob_clean();
}

Flight::start();
