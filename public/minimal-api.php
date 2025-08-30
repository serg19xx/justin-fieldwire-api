<?php
header('Content-Type: application/json');

// Simple routing
$request_uri = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];

// Health check endpoint
if ($request_uri === '/api/v1/health' && $method === 'GET') {
    $response = [
        'status' => 'healthy',
        'timestamp' => date('c'),
        'php_version' => phpversion(),
        'server' => $_SERVER['SERVER_SOFTWARE'],
        'memory_usage' => [
            'current' => memory_get_usage(),
            'peak' => memory_get_peak_usage(),
            'limit' => ini_get('memory_limit')
        ],
        'version' => '1.0.0-minimal'
    ];
    
    echo json_encode($response);
    exit;
}

// API info endpoint
if ($request_uri === '/api' && $method === 'GET') {
    $response = [
        'name' => 'FieldWire API (Minimal)',
        'version' => '1.0.0',
        'description' => 'Minimal REST API without Composer dependencies',
        'php_version' => phpversion(),
        'endpoints' => [
            'health' => 'GET /api/v1/health',
            'info' => 'GET /api'
        ]
    ];
    
    echo json_encode($response);
    exit;
}

// Test database connection (without Composer)
if ($request_uri === '/api/v1/db-test' && $method === 'GET') {
    $response = [
        'status' => 'testing',
        'php_version' => phpversion()
    ];
    
    // Try to connect to database using basic PDO
    try {
        $host = 'medicalcontractor.ca';
        $dbname = 'yjyhtqh8_easyrx';
        $username = 'yjyhtqh8_fieldwire';
        $password = 'FieldWire2025';
        
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $response['database'] = 'connected';
        $response['message'] = 'Database connection successful';
    } catch (PDOException $e) {
        $response['database'] = 'error';
        $response['message'] = $e->getMessage();
    }
    
    echo json_encode($response);
    exit;
}

// 404 handler
http_response_code(404);
echo json_encode([
    'error' => [
        'code' => 404,
        'message' => 'Endpoint not found',
        'request_uri' => $request_uri,
        'method' => $method,
        'php_version' => phpversion()
    ]
]);
?>
