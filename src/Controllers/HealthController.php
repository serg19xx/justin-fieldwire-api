<?php

namespace App\Controllers;

use Flight;
use Monolog\Logger;

class HealthController
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function getHealth(): void
    {
        try {
            // Simple health check
            $healthData = [
                'status' => 'healthy',
                'timestamp' => date('c'),
                'version' => '1.0.0',
                'uptime' => 'running'
            ];

            Flight::json($healthData);
            
        } catch (\Exception $e) {
            $this->logger->error('Health check failed', [
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'status' => 'unhealthy',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getVersion(): void
    {
        try {
            $versionData = [
                'api_version' => 'v1',
                'status' => 'stable',
                'released' => '2025-08-28',
                'endpoints' => [
                    'health' => '/api/v1/health',
                    'version' => '/api/v1/version',
                ],
            ];

            Flight::json($versionData);
            
        } catch (\Exception $e) {
            $this->logger->error('Version check failed', [
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error' => 'Failed to get version info',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
