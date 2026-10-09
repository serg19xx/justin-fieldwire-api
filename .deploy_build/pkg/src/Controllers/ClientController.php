<?php

namespace App\Controllers;

use App\Database\Database;
use Doctrine\DBAL\Exception;
use Flight;
use Monolog\Logger;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Clients",
 *     description="Client management endpoints for different client types"
 * )
 */
class ClientController
{
    private Logger $logger;
    private Database $database;

    // Маппинг типов таблиц на их реальные имена в БД и поля name
    private const CLIENT_TABLES = [
        'pharma' => [
            'table' => 'pharma',
            'name_field' => 'operName',
            'id_field' => 'id'
        ],
        'physician' => [
            'table' => 'physician',
            'name_field' => 'fullName',
            'id_field' => 'id'
        ],
        'pharmacist' => [
            'table' => 'pharmacist',
            'name_field' => 'fullName',
            'id_field' => 'id'
        ],
        'medical_clinic' => [
            'table' => 'medical_clinic',
            'name_field' => 'clinicName',
            'id_field' => 'id'
        ]
    ];

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        
        try {
            $this->database = new Database();
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize ClientController', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Поиск клиентов с пагинацией
     * GET /api/v1/clients/{clientTable}
     *
     * @OA\Get(
     *     path="/api/v1/clients/{clientTable}",
     *     summary="Search clients with pagination",
     *     description="Search clients in the specified table with search query and pagination support",
     *     tags={"Clients"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="clientTable",
     *         in="path",
     *         description="Client table type",
     *         required=true,
     *         @OA\Schema(type="string", enum={"pharma", "physician", "pharmacist", "medical_clinic"})
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Search query for filtering by name",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Page number (starting from 1)",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, default=1)
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         description="Number of records per page",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, maximum=100, default=20)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Clients retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="clients", type="array", @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Pharmacy Name")
     *                 )),
     *                 @OA\Property(property="total", type="integer", example=150),
     *                 @OA\Property(property="page", type="integer", example=1),
     *                 @OA\Property(property="limit", type="integer", example=20),
     *                 @OA\Property(property="total_pages", type="integer", example=8)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid client table type",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="error", type="string", example="Invalid client table type"),
     *             @OA\Property(property="error_code", type="integer", example=400)
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */
    public function searchClients(string $clientTable): void
    {
        $this->logger->info('ClientController::searchClients called', ['clientTable' => $clientTable]);
        
        try {
            // Валидация типа таблицы
            if (!isset(self::CLIENT_TABLES[$clientTable])) {
                Flight::json([
                    'success' => false,
                    'error' => 'Invalid client table type. Allowed values: pharma, physician, pharmacist, medical_clinic',
                    'error_code' => 400
                ], 400);
                return;
            }

            $tableConfig = self::CLIENT_TABLES[$clientTable];
            $tableName = $tableConfig['table'];
            $nameField = $tableConfig['name_field'];
            $idField = $tableConfig['id_field'];

            $request = Flight::request();
            $search = $request->query['search'] ?? null;
            $page = max(1, (int)($request->query['page'] ?? 1));
            $limit = min(max(1, (int)($request->query['limit'] ?? 20)), 100);
            $offset = ($page - 1) * $limit;

            $connection = $this->database->getConnection();

            // Exclude rows with empty display name (NULL, blank, whitespace-only).
            $whereConditions = [
                "{$nameField} IS NOT NULL",
                "TRIM({$nameField}) <> ''",
            ];
            $params = [];

            if (is_string($search) && trim($search) !== '') {
                $whereConditions[] = "{$nameField} LIKE ?";
                $params[] = '%' . trim($search) . '%';
            }

            $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

            // Получаем общее количество
            $countSql = "SELECT COUNT(*) as total FROM {$tableName} {$whereClause}";
            $countResult = $connection->executeQuery($countSql, $params);
            $total = (int)$countResult->fetchOne();

            // Получаем клиентов
            $sql = "SELECT {$idField} as id, {$nameField} as name 
                    FROM {$tableName} 
                    {$whereClause} 
                    ORDER BY {$nameField} ASC 
                    LIMIT {$limit} OFFSET {$offset}";

            $result = $connection->executeQuery($sql, $params);
            $clients = $result->fetchAllAssociative();

            // Форматируем ответ
            $formattedClients = array_map(function($client) {
                return [
                    'id' => (int)$client['id'],
                    'name' => $client['name']
                ];
            }, $clients);

            $totalPages = ceil($total / $limit);

            Flight::json([
                'success' => true,
                'data' => [
                    'clients' => $formattedClients,
                    'total' => $total,
                    'page' => $page,
                    'limit' => $limit,
                    'total_pages' => $totalPages
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to search clients', [
                'clientTable' => $clientTable,
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'success' => false,
                'error' => 'Failed to search clients',
                'error_code' => 500
            ], 500);
        }
    }

    /**
     * Получение клиента по ID
     * GET /api/v1/clients/{clientTable}/{clientId}
     *
     * @OA\Get(
     *     path="/api/v1/clients/{clientTable}/{clientId}",
     *     summary="Get client by ID",
     *     description="Get full client information by ID from the specified table",
     *     tags={"Clients"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="clientTable",
     *         in="path",
     *         description="Client table type",
     *         required=true,
     *         @OA\Schema(type="string", enum={"pharma", "physician", "pharmacist", "medical_clinic"})
     *     ),
     *     @OA\Parameter(
     *         name="clientId",
     *         in="path",
     *         description="Client ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Client retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="client", type="object",
     *                     @OA\Property(property="id", type="integer", example=123),
     *                     @OA\Property(property="name", type="string", example="Pharmacy Name"),
     *                     @OA\Property(property="data", type="object",
     *                         @OA\Property(property="address", type="string", nullable=true, example="123 Main St"),
     *                         @OA\Property(property="phone", type="string", nullable=true, example="+1-555-1234"),
     *                         @OA\Property(property="email", type="string", nullable=true, example="pharmacy@example.com")
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid client table type",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="error", type="string", example="Invalid client table type"),
     *             @OA\Property(property="error_code", type="integer", example=400)
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Client not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="error", type="string", example="Client not found"),
     *             @OA\Property(property="error_code", type="integer", example=404)
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error"
     *     )
     * )
     */
    public function getClientById(string $clientTable, int $clientId): void
    {
        $this->logger->info('ClientController::getClientById called', [
            'clientTable' => $clientTable,
            'clientId' => $clientId
        ]);
        
        try {
            // Валидация типа таблицы
            if (!isset(self::CLIENT_TABLES[$clientTable])) {
                Flight::json([
                    'success' => false,
                    'error' => 'Invalid client table type. Allowed values: pharma, physician, pharmacist, medical_clinic',
                    'error_code' => 400
                ], 400);
                return;
            }

            $tableConfig = self::CLIENT_TABLES[$clientTable];
            $tableName = $tableConfig['table'];
            $idField = $tableConfig['id_field'];

            $connection = $this->database->getConnection();

            // Получаем все поля клиента
            // Для каждой таблицы получаем все поля
            $sql = "SELECT * FROM {$tableName} WHERE {$idField} = ?";
            $result = $connection->executeQuery($sql, [$clientId]);
            $client = $result->fetchAssociative();

            if (!$client) {
                Flight::json([
                    'success' => false,
                    'error' => 'Client not found',
                    'error_code' => 404
                ], 404);
                return;
            }

            $nameField = $tableConfig['name_field'];
            
            // Извлекаем id и name на верхний уровень
            $clientId = (int)$client[$idField];
            $clientName = $client[$nameField] ?? null;
            
            // Остальные поля помещаем в data
            $clientData = [];
            foreach ($client as $key => $value) {
                // Пропускаем id и name, они уже на верхнем уровне
                if ($key === $idField || $key === $nameField) {
                    continue;
                }
                
                // Преобразуем числовые ID поля в int (включая pharmId и другие поля с суффиксом Id)
                if (str_ends_with($key, 'Id') || $key === 'id' || preg_match('/Id$/', $key)) {
                    $clientData[$key] = $value !== null ? (int)$value : null;
                } else {
                    $clientData[$key] = $value;
                }
            }

            Flight::json([
                'success' => true,
                'data' => [
                    'client' => [
                        'id' => $clientId,
                        'name' => $clientName,
                        'data' => $clientData
                    ]
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to get client by ID', [
                'clientTable' => $clientTable,
                'clientId' => $clientId,
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'success' => false,
                'error' => 'Failed to get client',
                'error_code' => 500
            ], 500);
        }
    }
}
