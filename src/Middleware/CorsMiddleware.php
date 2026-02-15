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
        $configOrigins = $this->config->get('cors.allowed_origins', []);
        $defaultOrigins = [
            'http://localhost:3000',
            'http://localhost:3001',
            'http://127.0.0.1:3000',
            'http://127.0.0.1:3001',
            'https://fieldwire.medicalcontractor.ca',
            'https://www.fieldwire.medicalcontractor.ca',
            'https://medicalcontractor.ca',
            'https://www.medicalcontractor.ca',
        ];
        
        // Merge config origins with defaults, filter out empty values
        $allowedOrigins = array_filter(array_merge($defaultOrigins, $configOrigins));
        
        // Get the origin from the request
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        
        // Extract origin from referer if HTTP_ORIGIN is not set
        if (empty($origin) && !empty($_SERVER['HTTP_REFERER'])) {
            $parsedUrl = parse_url($_SERVER['HTTP_REFERER']);
            if ($parsedUrl && isset($parsedUrl['scheme']) && isset($parsedUrl['host'])) {
                $origin = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
                if (isset($parsedUrl['port'])) {
                    $origin .= ':' . $parsedUrl['port'];
                }
            }
        }
        
        // Check if origin is allowed
        $originAllowed = false;
        if (!empty($origin)) {
            // Normalize origin (remove path if present)
            $originParsed = parse_url($origin);
            if ($originParsed && isset($originParsed['host'])) {
                $normalizedOrigin = $originParsed['scheme'] . '://' . $originParsed['host'];
                if (isset($originParsed['port'])) {
                    $normalizedOrigin .= ':' . $originParsed['port'];
                }
                $origin = $normalizedOrigin;
            }
            
            // Exact match
            if (in_array($origin, $allowedOrigins, true)) {
                $originAllowed = true;
            }
            // Allow any subdomain of medicalcontractor.ca (https only for production)
            elseif (preg_match('/^https:\/\/([a-z0-9-]+\.)*medicalcontractor\.ca(:\d+)?$/', $origin)) {
                $originAllowed = true;
            }
            // For development, allow localhost origins (any port)
            elseif (preg_match('/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/', $origin)) {
                $originAllowed = true;
            }
            // Allow same domain (for API calls from same domain)
            elseif (isset($_SERVER['HTTP_HOST'])) {
                $requestHost = $_SERVER['HTTP_HOST'];
                $originHost = parse_url($origin, PHP_URL_HOST);
                if ($originHost === $requestHost) {
                    $originAllowed = true;
                }
            }
        }
        
        if ($originAllowed && !empty($origin)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            error_log('CORS: Allowed origin - ' . $origin);
        } else {
            // For development, if origin is empty but request is from localhost, allow it
            if (empty($origin) && (isset($_SERVER['HTTP_HOST']) && (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false || strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false))) {
                $defaultOrigin = 'http://' . $_SERVER['HTTP_HOST'];
                header('Access-Control-Allow-Origin: ' . $defaultOrigin);
                error_log('CORS: Using default origin for localhost - ' . $defaultOrigin);
            } else {
                // Log for debugging
                error_log('CORS: Origin not allowed - Origin: ' . ($origin ?: 'empty') . ', Allowed: ' . implode(', ', $allowedOrigins) . ', HTTP_ORIGIN: ' . ($_SERVER['HTTP_ORIGIN'] ?? 'not set') . ', HTTP_REFERER: ' . ($_SERVER['HTTP_REFERER'] ?? 'not set'));
            }
        }
        
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Max-Age: 86400'); // 24 hours
        header('Access-Control-Expose-Headers: Content-Length, X-JSON');

        // Handle preflight requests
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit();
        }
    }
}
