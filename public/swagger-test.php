<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Simple test OpenAPI specification
$openapi = [
    'openapi' => '3.0.0',
    'info' => [
        'title' => 'FieldWire API Test',
        'version' => '1.0.0',
        'description' => 'Test OpenAPI specification'
    ],
    'servers' => [
        [
            'url' => 'http://localhost:8000',
            'description' => 'Development server'
        ]
    ],
    'paths' => [
        '/test' => [
            'get' => [
                'summary' => 'Test endpoint',
                'responses' => [
                    '200' => [
                        'description' => 'Success',
                        'content' => [
                            'application/json' => [
                                'schema' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'message' => [
                                            'type' => 'string',
                                            'example' => 'Hello World'
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
];

echo json_encode($openapi, JSON_PRETTY_PRINT);
