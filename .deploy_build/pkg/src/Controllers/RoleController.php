<?php

namespace App\Controllers;

use App\Database\Database;
use Flight;
use Monolog\Logger;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Roles",
 *     description="Role management endpoints"
 * )
 */
class RoleController
{
    private Logger $logger;
    private Database $database;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        
        try {
            $this->database = new Database();
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize RoleController', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get all roles
     * GET /api/v1/roles
     *
     * @OA\Get(
     *     path="/api/v1/roles",
     *     summary="Get all roles",
     *     description="Retrieve a list of all available roles",
     *     tags={"Roles"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="category",
     *         in="query",
     *         description="Filter by role category",
     *         required=false,
     *         @OA\Schema(
     *             type="string",
     *             enum={"global", "project", "task"}
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Roles retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Roles retrieved successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="code", type="string", example="admin"),
     *                     @OA\Property(property="name", type="string", example="Administrator"),
     *                     @OA\Property(property="category", type="string", example="global"),
     *                     @OA\Property(property="description", type="string", example="Full system access")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=401),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Unauthorized"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=500),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Failed to retrieve roles"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     )
     * )
     */
    public function getRoles(): void
    {
        $this->logger->info('RoleController::getRoles called');
        
        try {
            $request = Flight::request();
            $category = $request->query['category'] ?? null;

            $connection = $this->database->getConnection();
            
            // Build SQL query
            $sql = "SELECT id, code, name, category, description FROM fw_glob_roles";
            $params = [];
            
            // Add category filter if provided
            if ($category && in_array($category, ['global', 'project', 'task'])) {
                $sql .= " WHERE category = ?";
                $params[] = $category;
            }
            
            $sql .= " ORDER BY category, name";
            
            // Execute query
            $result = $connection->executeQuery($sql, $params);
            $roles = $result->fetchAllAssociative();

            $this->logger->info('Roles retrieved', [
                'count' => count($roles),
                'category_filter' => $category
            ]);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Roles retrieved successfully',
                'data' => $roles
            ], 200);

        } catch (\Exception $e) {
            $this->logger->error('Failed to retrieve roles', [
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve roles',
                'data' => null
            ], 500);
        }
    }
}

