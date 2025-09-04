<?php

namespace App\Middleware;

use Monolog\Logger;

class LoggingMiddleware
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function handle(): void
    {
        $startTime = microtime(true);

        // Log request
        $this->logger->info('Request started', [
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
            'uri' => $_SERVER['REQUEST_URI'] ?? 'UNKNOWN',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN',
        ]);

        // Register shutdown function to log response
        register_shutdown_function(function () use ($startTime) {
            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);

            $this->logger->info('Request completed', [
                'duration_ms' => $duration,
                'status_code' => http_response_code(),
            ]);
        });
    }
}
