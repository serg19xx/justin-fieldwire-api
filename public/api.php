<?php
header('Content-Type: application/json');

// Simple routing
$request_uri = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight requests
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Database configuration for production
$host = 'localhost'; // Production uses localhost
$dbname = 'yjyhtqh8_easyrx';
$username = 'yjyhtqh8_fieldwire';
$password = 'FieldWire2025';

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
        'version' => '1.0.0',
        'database' => [
            'status' => 'checking...'
        ]
    ];
    
    // Try database connection
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $response['database']['status'] = 'connected';
    } catch (PDOException $e) {
        $response['database']['status'] = 'error: ' . $e->getMessage();
    }
    
    echo json_encode($response);
    exit;
}

// API info endpoint
if ($request_uri === '/api' && $method === 'GET') {
    $response = [
        'name' => 'FieldWire API',
        'version' => '1.0.0',
        'description' => 'REST API built with pure PHP (no Composer dependencies)',
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

// Version info endpoint
if ($request_uri === '/api/v1/version' && $method === 'GET') {
    $response = [
        'api_version' => 'v1',
        'status' => 'stable',
        'released' => '2025-08-30',
        'php_version' => phpversion(),
        'endpoints' => [
            'health' => 'GET /api/v1/health',
            'version' => 'GET /api/v1/version',
            'database_tables' => 'GET /api/v1/database/tables'
        ]
    ];
    
    echo json_encode($response);
    exit;
}

// Database tables endpoint
if ($request_uri === '/api/v1/database/tables' && $method === 'GET') {
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $response = [
            'status' => 'success',
            'database' => $dbname,
            'tables' => $tables,
            'count' => count($tables)
        ];
    } catch (PDOException $e) {
        $response = [
            'status' => 'error',
            'message' => $e->getMessage()
        ];
        http_response_code(500);
    }
    
    echo json_encode($response);
    exit;
}

// Swagger UI endpoint
if ($request_uri === '/api/docs' && $method === 'GET') {
    header('Content-Type: text/html');
    echo '<!DOCTYPE html>
<html>
<head>
    <title>FieldWire API - Swagger UI</title>
    <link rel="stylesheet" type="text/css" href="https://unpkg.com/swagger-ui-dist@4.15.5/swagger-ui.css" />
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="https://unpkg.com/swagger-ui-dist@4.15.5/swagger-ui-bundle.js"></script>
    <script src="https://unpkg.com/swagger-ui-dist@4.15.5/swagger-ui-standalone-preset.js"></script>
    <script>
        window.onload = function() {
            SwaggerUIBundle({
                url: "/api/swagger/spec",
                dom_id: "#swagger-ui",
                presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
                layout: "StandaloneLayout"
            });
        };
    </script>
</body>
</html>';
    exit;
}

// OpenAPI specification
if ($request_uri === '/api/swagger/spec' && $method === 'GET') {
    $spec = [
        'openapi' => '3.0.0',
        'info' => [
            'title' => 'FieldWire API',
            'version' => '1.0.0',
            'description' => 'REST API built with pure PHP'
        ],
        'servers' => [
            [
                'url' => 'https://fwapi.medicalcontractor.ca',
                'description' => 'Production server'
            ]
        ],
        'paths' => [
            '/api/v1/health' => [
                'get' => [
                    'summary' => 'Health check',
                    'responses' => [
                        '200' => [
                            'description' => 'API is healthy',
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'status' => ['type' => 'string'],
                                            'timestamp' => ['type' => 'string'],
                                            'php_version' => ['type' => 'string']
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ]
    ];
    
    echo json_encode($spec);
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
