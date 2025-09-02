<?php

namespace App\Controllers;

use Flight;
use Monolog\Logger;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Health",
 *     description="API health and status endpoints"
 * )
 */
class HealthController
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @OA\Get(
     *     path="/api/v1/health",
     *     summary="Get API health status",
     *     tags={"Health"},
     *     @OA\Response(
     *         response=200,
     *         description="API is healthy",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="status", type="string", example="healthy"),
     *             @OA\Property(property="timestamp", type="string", format="date-time"),
     *             @OA\Property(property="version", type="string", example="1.0.0"),
     *             @OA\Property(property="uptime", type="string", example="running")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="API is unhealthy",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="status", type="string", example="unhealthy"),
     *             @OA\Property(property="error", type="string")
     *         )
     *     )
     * )
     */
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

    /**
     * @OA\Get(
     *     path="/api/v1/version",
     *     summary="Get API version information",
     *     tags={"Health"},
     *     @OA\Response(
     *         response=200,
     *         description="API version info",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="api_version", type="string", example="v1"),
     *             @OA\Property(property="status", type="string", example="stable"),
     *             @OA\Property(property="released", type="string", example="2025-08-28"),
     *             @OA\Property(
     *                 property="endpoints",
     *                 type="object",
     *                 @OA\Property(property="health", type="string", example="/api/v1/health"),
     *                 @OA\Property(property="version", type="string", example="/api/v1/version")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Failed to get version info",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="error", type="string"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     )
     * )
     */
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
