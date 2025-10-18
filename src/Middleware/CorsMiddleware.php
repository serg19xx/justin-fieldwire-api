<?php

namespace App\Middleware;

use App\Config\Config;
use Flight;

class CorsMiddleware
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function handle(): void
    {
        // Get allowed origins from config
        $allowedOrigins = $this->config->get('cors.allowed_origins', [
            'http://localhost:3000',
            'http://localhost:3001', 
            'http://127.0.0.1:3000',
            'http://127.0.0.1:3001',
            'https://fieldwire.medicalcontractor.ca'
        ]);
        
        // Get the origin from the request
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        // Check if origin is allowed
        if (in_array($origin, $allowedOrigins)) {
            header('Access-Control-Allow-Origin: ' . $origin);
        } else {
            // For development, allow localhost origins
            if (strpos($origin, 'localhost') !== false || strpos($origin, '127.0.0.1') !== false) {
                header('Access-Control-Allow-Origin: ' . $origin);
            } else {
                // For other cases, don't set Access-Control-Allow-Origin at all
                // This will cause CORS to fail, but it's better than breaking credentials
                error_log('CORS: Origin not allowed: ' . $origin);
            }
        }
        
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400'); // 24 hours

        // Handle preflight requests
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit();
        }
    }
}
