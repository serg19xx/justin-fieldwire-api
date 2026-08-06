<?php

namespace App\Config;

class Config
{
    private array $config;

    public function __construct()
    {
        try {
            $this->config = [
                'app' => [
                    'name' => $_ENV['APP_NAME'] ?? 'FieldWire API',
                    'env' => $_ENV['APP_ENV'] ?? 'production',
                    'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'url' => $_ENV['APP_URL'] ?? 'http://localhost:8000',
                ],
                'logging' => [
                    'level' => $_ENV['LOG_LEVEL'] ?? 'info',
                    'channel' => $_ENV['LOG_CHANNEL'] ?? 'file',
                    'log_file' => $_ENV['LOG_FILE'] ?? 'logs/app.log',
                ],
                'cors' => [
                    'allowed_origins' => explode(',', $_ENV['CORS_ALLOWED_ORIGINS'] ?? ''),
                    'allowed_methods' => explode(',', $_ENV['CORS_ALLOWED_METHODS'] ?? 'GET,POST,PUT,DELETE,OPTIONS'),
                    'allowed_headers' => explode(',', $_ENV['CORS_ALLOWED_HEADERS'] ?? 'Content-Type,Authorization'),
                ],
            ];
        } catch (Exception $e) {
            file_put_contents(__DIR__ . '/../../logs/app.log', date('Y-m-d H:i:s') . ' - ERROR in Config constructor: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
            throw $e;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    public function all(): array
    {
        return $this->config;
    }

    public function getDatabase(): array
    {
        return [
            'host' => $this->env('DB_HOST', 'localhost'),
            'port' => (int) $this->env('DB_PORT', '3306'),
            'name' => $this->env('DB_NAME', 'fieldwire_api'),
            'username' => $this->env('DB_USERNAME', 'root'),
            'password' => $this->env('DB_PASSWORD', ''),
            'charset' => $this->env('DB_CHARSET', 'utf8mb4'),
            'collation' => $this->env('DB_COLLATION', 'utf8mb4_unicode_ci'),
        ];
    }

    private function env(string $key, string $default = ''): string
    {
        if (array_key_exists($key, $_ENV) && $_ENV[$key] !== null && (string) $_ENV[$key] !== '') {
            return (string) $_ENV[$key];
        }
        if (array_key_exists($key, $_SERVER) && $_SERVER[$key] !== null && (string) $_SERVER[$key] !== '') {
            return (string) $_SERVER[$key];
        }
        $fromGetenv = getenv($key);
        if ($fromGetenv !== false && $fromGetenv !== '') {
            return (string) $fromGetenv;
        }
        return $default;
    }
}
