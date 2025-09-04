<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Simple working OpenAPI specification
$openapi = [
    'openapi' => '3.0.0',
    'info' => [
        'title' => 'FieldWire API',
        'version' => '1.0.0',
        'description' => 'REST API for FieldWire application - Field management and communication platform',
        'contact' => [
            'email' => 'support@fieldwire.com',
            'name' => 'FieldWire Support'
        ],
        'license' => [
            'name' => 'Proprietary',
            'url' => 'https://fieldwire.com/license'
        ]
    ],
    'servers' => [
        [
            'url' => 'http://localhost:8000',
            'description' => 'Development server'
        ],
        [
            'url' => 'https://api.fieldwire.com',
            'description' => 'Production server'
        ]
    ],
    'security' => [
        [
            'bearerAuth' => []
        ]
    ],
    'paths' => [
        '/health' => [
            'get' => [
                'summary' => 'Get API health status',
                'description' => 'Check if the API is running and healthy',
                'tags' => ['Health'],
                'responses' => [
                    '200' => [
                        'description' => 'API is healthy',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'status' => [
                                            'type' => 'string',
                                            'example' => 'healthy'
                                        ],
                                        'timestamp' => [
                                            'type' => 'string',
                                            'format' => 'date-time'
                                        ],
                                        'version' => [
                                            'type' => 'string',
                                            'example' => '1.0.0'
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ],
                    '500' => [
                        'description' => 'API is unhealthy',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'status' => [
                                            'type' => 'string',
                                            'example' => 'unhealthy'
                                        ],
                                        'error' => [
                                            'type' => 'string',
                                            'example' => 'Database connection failed'
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ],
        '/auth/login' => [
            'post' => [
                'summary' => 'User login',
                'description' => 'Authenticate user with email and password',
                'tags' => ['Authentication'],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'required' => ['email', 'password'],
                                'properties' => [
                                    'email' => [
                                        'type' => 'string',
                                        'format' => 'email',
                                        'example' => 'user@example.com'
                                    ],
                                    'password' => [
                                        'type' => 'string',
                                        'example' => 'password123'
                                    ]
                                ]
                            ]
                        ]
                    ]
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Login successful',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'success' => [
                                            'type' => 'boolean',
                                            'example' => true
                                        ],
                                        'token' => [
                                            'type' => 'string',
                                            'example' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...'
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ],
                    '401' => [
                        'description' => 'Invalid credentials'
                    ]
                ]
            ]
        ],
        '/profile' => [
            'get' => [
                'summary' => 'Get user profile',
                'description' => 'Retrieve current user profile information',
                'tags' => ['Profile'],
                'security' => [
                    [
                        'bearerAuth' => []
                    ]
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Profile retrieved successfully',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'user' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'id' => [
                                                    'type' => 'integer',
                                                    'example' => 1
                                                ],
                                                'email' => [
                                                    'type' => 'string',
                                                    'example' => 'user@example.com'
                                                ],
                                                'name' => [
                                                    'type' => 'string',
                                                    'example' => 'John Doe'
                                                ]
                                            ]
                                        ]
                                    ]
                                ]
                            ]
                        ]
                    ],
                    '401' => [
                        'description' => 'Unauthorized'
                    ]
                ]
            ]
        ]
    ],
    'tags' => [
        [
            'name' => 'Health',
            'description' => 'API health and status endpoints'
        ],
        [
            'name' => 'Authentication',
            'description' => 'User authentication and authorization endpoints'
        ],
        [
            'name' => 'Profile',
            'description' => 'User profile management'
        ]
    ],
    'components' => [
        'securitySchemes' => [
            'bearerAuth' => [
                'type' => 'http',
                'scheme' => 'bearer',
                'bearerFormat' => 'JWT'
            ]
        ]
    ]
];

echo json_encode($openapi, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
