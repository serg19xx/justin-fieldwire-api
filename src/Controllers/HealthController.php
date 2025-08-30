<?php

namespace App\Controllers;

use App\Database\Database;
use Flight;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="System",
 *     description="System endpoints"
 * )
 * 
 * @OA\PathItem(
 *     path="/api/v1/health"
 * )
 * 
 * @OA\PathItem(
 *     path="/api/v1/version"
 * )
 */
class HealthController
{
    /**
     * @OA\Get(
     *     path="/api/v1/health",
     *     summary="Health check",
     *     description="Check the health status of the API",
     *     tags={"System"},
     *     @OA\Response(
     *         response=200,
     *         description="API is healthy",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="status", type="string", example="healthy"),
     *             @OA\Property(property="timestamp", type="string", format="date-time"),
     *             @OA\Property(
     *                 property="uptime",
     *                 type="object",
     *                 @OA\Property(property="seconds", type="integer"),
     *                 @OA\Property(property="formatted", type="string")
     *             ),
     *             @OA\Property(
     *                 property="memory_usage",
     *                 type="object",
     *                 @OA\Property(property="current", type="integer"),
     *                 @OA\Property(property="peak", type="integer"),
     *                 @OA\Property(property="limit", type="string")
     *             ),
     *             @OA\Property(property="version", type="string", example="1.0.0"),
     *             @OA\Property(
     *                 property="database",
     *                 type="object",
     *                 @OA\Property(property="status", type="string", example="not_configured")
     *             )
     *         )
     *     )
     * )
     */
    public function index(): void
    {
        $uptime = time() - $_SERVER['REQUEST_TIME'];
        $uptimeFormatted = gmdate('H:i:s', $uptime);
        $memoryUsage = [
            'current' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'limit' => ini_get('memory_limit'),
        ];

        // Test database connection
        $databaseStatus = 'unknown';
        try {
            $databaseStatus = Database::testConnection() ? 'connected' : 'disconnected';
        } catch (\Exception $e) {
            $databaseStatus = 'error';
        }
        
        Flight::json([
            'status' => 'healthy',
            'timestamp' => date('c'),
            'uptime' => [
                'seconds' => $uptime,
                'formatted' => $uptimeFormatted,
            ],
            'memory_usage' => $memoryUsage,
            'version' => '1.0.0',
            'database' => [
                'status' => $databaseStatus,
            ],
        ]);
    }

    /**
     * @OA\Get(
     *     path="/api/v1/version",
     *     summary="API version info",
     *     description="Get information about the API version",
     *     tags={"System"},
     *     @OA\Response(
     *         response=200,
     *         description="Version information",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="api_version", type="string", example="v1"),
     *             @OA\Property(property="status", type="string", example="stable"),
     *             @OA\Property(property="released", type="string", format="date"),
     *             @OA\Property(
     *                 property="endpoints",
     *                 type="object",
     *                 @OA\Property(property="health", type="string"),
     *                 @OA\Property(property="version", type="string")
     *             )
     *         )
     *     )
     * )
     */
    public function version(): void
    {
        Flight::json([
            'api_version' => 'v1',
            'status' => 'stable',
            'released' => '2025-08-28',
            'endpoints' => [
                'health' => '/api/v1/health',
                'version' => '/api/v1/version',
            ],
        ]);
    }

    private function getUptime(): array
    {
        $uptime = time() - $_SERVER['REQUEST_TIME'];

        return [
            'seconds' => $uptime,
            'formatted' => gmdate('H:i:s', $uptime),
        ];
    }

    private function getMemoryUsage(): array
    {
        return [
            'current' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'limit' => ini_get('memory_limit'),
        ];
    }
}
