<?php

namespace App\Routes;

use App\Controllers\HealthController;
use App\Controllers\DatabaseController;
use Flight;

class ApiRoutes
{
    public function register(): void
    {
        // API v1 routes
        $this->registerV1Routes();
        
        // Swagger routes
        $this->registerSwaggerRoutes();

        // API documentation
        Flight::route('GET /api', function () {
            Flight::json([
                'name' => 'FieldWire API',
                'version' => '1.0.0',
                'description' => 'REST API built with FlightPHP',
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
            ]);
        });

        // 404 handler for API routes
        Flight::map('notFound', function () {
            Flight::json([
                'error' => [
                    'code' => 404,
                    'message' => 'Endpoint not found',
                ],
            ], 404);
        });
    }

    private function registerV1Routes(): void
    {
        // Health check endpoint
        Flight::route('GET /api/v1/health', [new HealthController(), 'index']);
        
        // Version info endpoint
        Flight::route('GET /api/v1/version', [new HealthController(), 'version']);
        
        // Legacy route for backward compatibility
        Flight::route('GET /api/health', [new HealthController(), 'index']);
        
        // Database tables
        Flight::route('GET /api/v1/database/tables', [new DatabaseController(), 'getTables']);
    }

    private function registerSwaggerRoutes(): void
    {
        // OpenAPI specification
        Flight::route('GET /api/swagger/spec', function () {
            $spec = [
                'openapi' => '3.0.0',
                'info' => [
                    'title' => 'FieldWire API',
                    'version' => '1.0.0',
                    'description' => 'REST API built with FlightPHP',
                    'contact' => [
                        'email' => 'support@fieldwire.com',
                        'name' => 'FieldWire Support'
                    ],
                    'license' => [
                        'name' => 'MIT',
                        'url' => 'https://opensource.org/licenses/MIT'
                    ]
                ],
                'servers' => [
                    [
                        'url' => 'http://localhost:8000',
                        'description' => 'Development server'
                    ]
                ],
                'paths' => [
                    '/api/v1/health' => [
                        'get' => [
                            'summary' => 'Health check',
                            'description' => 'Check the health status of the API',
                            'tags' => ['System'],
                            'responses' => [
                                '200' => [
                                    'description' => 'API is healthy',
                                    'content' => [
                                        'application/json' => [
                                            'schema' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'status' => ['type' => 'string', 'example' => 'healthy'],
                                                    'timestamp' => ['type' => 'string', 'format' => 'date-time'],
                                                    'uptime' => [
                                                        'type' => 'object',
                                                        'properties' => [
                                                            'seconds' => ['type' => 'integer'],
                                                            'formatted' => ['type' => 'string']
                                                        ]
                                                    ],
                                                    'memory_usage' => [
                                                        'type' => 'object',
                                                        'properties' => [
                                                            'current' => ['type' => 'integer'],
                                                            'peak' => ['type' => 'integer'],
                                                            'limit' => ['type' => 'string']
                                                        ]
                                                    ],
                                                    'version' => ['type' => 'string', 'example' => '1.0.0']
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ],
                    '/api/v1/version' => [
                        'get' => [
                            'summary' => 'API version info',
                            'description' => 'Get information about the API version',
                            'tags' => ['System'],
                            'responses' => [
                                '200' => [
                                    'description' => 'Version information',
                                    'content' => [
                                        'application/json' => [
                                            'schema' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'api_version' => ['type' => 'string', 'example' => 'v1'],
                                                    'status' => ['type' => 'string', 'example' => 'stable'],
                                                    'released' => ['type' => 'string', 'format' => 'date'],
                                                    'endpoints' => [
                                                        'type' => 'object',
                                                        'properties' => [
                                                            'health' => ['type' => 'string'],
                                                            'version' => ['type' => 'string']
                                                        ]
                                                    ]
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                'tags' => [
                    [
                        'name' => 'System',
                        'description' => 'System endpoints'
                    ]
                ]
            ];
            
            Flight::json($spec);
        });
        
        // Swagger UI
        Flight::route('GET /api/docs', function () {
            $swaggerUi = file_get_contents(__DIR__ . '/../../public/swagger-ui.html');
            echo $swaggerUi;
        });
    }
}
