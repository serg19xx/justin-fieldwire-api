<?php

// Simple Swagger endpoint that returns the existing JSON file
// This ensures Swagger works immediately without complex generation

try {
    // Set proper headers
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    
    // Handle preflight requests
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }

    // Check if swagger.json exists
    $swaggerFile = __DIR__ . '/swagger.json';
    if (!file_exists($swaggerFile)) {
        throw new Exception('swagger.json file not found');
    }

    // Read and return the existing swagger.json
    $swaggerContent = file_get_contents($swaggerFile);
    if ($swaggerContent === false) {
        throw new Exception('Failed to read swagger.json file');
    }

    // Validate JSON
    $decoded = json_decode($swaggerContent);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON in swagger.json: ' . json_last_error_msg());
    }

    echo $swaggerContent;
    
} catch (Exception $e) {
    error_log('Swagger error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Swagger failed to load',
        'message' => $e->getMessage()
    ]);
}
