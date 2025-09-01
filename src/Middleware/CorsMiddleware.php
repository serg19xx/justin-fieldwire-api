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
        // Skip CORS handling if not running in web context
        if (!isset($_SERVER['REQUEST_METHOD'])) {
            return;
        }

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigins = $this->config->get('cors.allowed_origins', []);

        // Allow all origins in development
        if ($this->config->get('app.env') === 'development' || empty($allowedOrigins)) {
            header('Access-Control-Allow-Origin: *');
        } elseif (in_array($origin, $allowedOrigins)) {
            header("Access-Control-Allow-Origin: {$origin}");
        }

        header('Access-Control-Allow-Methods: ' . implode(',', $this->config->get('cors.allowed_methods', [])));
        header('Access-Control-Allow-Headers: ' . implode(',', $this->config->get('cors.allowed_headers', [])));
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400'); // 24 hours

        // Handle preflight requests
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit();
        }
    }
}
