<?php

// Define application start time
define('APP_START_TIME', time());

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Simple health check endpoint for PHP 7.4
if ($_SERVER['REQUEST_URI'] === '/api/v1/health') {
    header('Content-Type: application/json');
    
    $response = [
        'status' => 'healthy',
        'timestamp' => date('c'),
        'uptime' => [
            'seconds' => time() - APP_START_TIME,
            'formatted' => gmdate('H:i:s', time() - APP_START_TIME)
        ],
        'memory_usage' => [
            'current' => memory_get_usage(),
            'peak' => memory_get_peak_usage(),
            'limit' => ini_get('memory_limit')
        ],
        'version' => '1.0.0',
        'php_version' => phpversion(),
        'database' => [
            'status' => 'checking...'
        ]
    ];
    
    // Try database connection
    try {
        $pdo = new PDO(
            "mysql:host=" . $_ENV['DB_HOST'] . 
            ";dbname=" . $_ENV['DB_NAME'] . 
            ";charset=" . $_ENV['DB_CHARSET'],
            $_ENV['DB_USERNAME'],
            $_ENV['DB_PASSWORD']
        );
        $response['database']['status'] = 'connected';
    } catch (PDOException $e) {
        $response['database']['status'] = 'error: ' . $e->getMessage();
    }
    
    echo json_encode($response);
    exit;
}

// API info endpoint
if ($_SERVER['REQUEST_URI'] === '/api') {
    header('Content-Type: application/json');
    
    $response = [
        'name' => 'FieldWire API',
        'version' => '1.0.0',
        'description' => 'REST API built with FlightPHP (PHP 7.4 compatible)',
        'php_version' => phpversion(),
        'documentation' => [
            'swagger_ui' => '/api/docs',
            'openapi_spec' => '/api/swagger/spec'
        ],
        'versions' => [
            'v1' => [
                'status' => 'stable',
                'endpoints' => [
                    'health' => 'GET /api/v1/health',
                    'version' => 'GET /api/v1/version',
                    'database_tables' => 'GET /api/v1/database/tables'
                ]
            ]
        ]
    ];
    
    echo json_encode($response);
    exit;
}

// 404 handler
header('Content-Type: application/json');
http_response_code(404);
echo json_encode([
    'error' => [
        'code' => 404,
        'message' => 'Endpoint not found',
        'php_version' => phpversion()
    ]
]);
?>
