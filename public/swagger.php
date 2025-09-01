<?php

require_once __DIR__ . '/../vendor/autoload.php';

use OpenApi\Generator;

// Set error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // Debug: Check what directories we're scanning
    $scanDirs = [
        __DIR__ . '/../src/Controllers',
        __DIR__ . '/../src/Swagger'
    ];
    
    error_log('Scanning directories: ' . implode(', ', $scanDirs));
    
    // Check if directories exist
    foreach ($scanDirs as $dir) {
        if (!is_dir($dir)) {
            error_log('Directory does not exist: ' . $dir);
        } else {
            error_log('Directory exists: ' . $dir);
            $files = glob($dir . '/*.php');
            error_log('PHP files in ' . $dir . ': ' . implode(', ', $files));
        }
    }

    // Generate OpenAPI specification
    $openapi = Generator::scan($scanDirs);

    // Debug: Check what was generated
    if (!$openapi) {
        throw new Exception('Failed to generate OpenAPI specification - Generator returned null');
    }

    // Debug: Log the generated object
    error_log('Generated OpenAPI object class: ' . get_class($openapi));
    error_log('Generated OpenAPI object properties: ' . print_r(get_object_vars($openapi), true));

    // Ensure OpenAPI version is set
    if (empty($openapi->openapi)) {
        $openapi->openapi = '3.0.0';
    }

    // Output as JSON with proper headers
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    
    // Handle preflight requests
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit();
    }

    // Generate JSON with proper formatting
    $json = $openapi->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    
    // Debug: Log the generated JSON
    error_log('Generated OpenAPI JSON: ' . substr($json, 0, 1000) . '...');
    
    echo $json;
    
} catch (Exception $e) {
    error_log('Swagger generation error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Failed to generate OpenAPI specification',
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
