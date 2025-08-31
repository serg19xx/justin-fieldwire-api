<?php

namespace App\Controllers;

use App\Database\Database;
use Flight;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Database",
 *     description="Database operations"
 * )
 */
class DatabaseController
{
    /**
     * @OA\Get(
     *     path="/api/v1/database/tables",
     *     summary="Get all database tables",
     *     description="Retrieve a list of all tables in the database",
     *     tags={"Database"},
     *     @OA\Response(
     *         response=200,
     *         description="List of database tables",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="tables", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="count", type="integer", example=50),
     *             @OA\Property(property="database", type="string", example="yjyhtqh8_easyrx")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Database error",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="error", type="string"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     )
     * )
     */
    public function getTables(): void
    {
        try {
            $connection = Database::getConnection();
            
            // Get database name
            $databaseName = $connection->getDatabase();
            
            // Get all tables
            $sql = "SHOW TABLES";
            $result = $connection->executeQuery($sql);
            $tables = $result->fetchFirstColumn();
            
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Database tables retrieved successfully',
                'data' => [
                    'tables' => $tables,
                    'count' => count($tables),
                    'database' => $databaseName,
                    'timestamp' => date('c')
                ]
            ]);
            
        } catch (\Exception $e) {
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve database tables',
                'data' => null,
                'details' => $e->getMessage()
            ], 500);
        }
    }
}
