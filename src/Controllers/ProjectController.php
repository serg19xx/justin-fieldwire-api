<?php

namespace App\Controllers;

use App\Database\Database;
use App\Services\EventLoggingService;
use Doctrine\DBAL\Exception;
use Flight;
use Monolog\Logger;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Projects",
 *     description="Project management endpoints"
 * )
 */
class ProjectController
{
    /** Allowed project status values for POST/PUT /api/v1/projects */
    private const ALLOWED_PROJECT_STATUSES = [
        'Initial Contact Lead',
        'Dead Lead',
        'Waiting On Direction',
        'Actively Looking For A Location',
        'Securing Location',
        'Project Secured',
        'Construction',
        'Completed Project',
    ];

    /** Allowed project level values (DB enum: note "Bacics" spelling) */
    private const ALLOWED_PROJECT_LEVELS = [
        'Bacics',
        'Full Service',
        'Medical Nice',
        'High End',
        'Extravagant',
    ];

    private Logger $logger;
    private Database $database;
    private EventLoggingService $eventLoggingService;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        
        try {
            $this->database = new Database();
            $this->eventLoggingService = new EventLoggingService($this->logger);
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize ProjectController', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Получить список всех проектов
     * GET /api/v1/projects
     *
     * @OA\Get(
     *     path="/api/v1/projects",
     *     summary="Get all projects",
     *     description="Retrieve a paginated list of all projects",
     *     tags={"Projects"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Page number for pagination",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, default=1)
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         description="Number of items per page",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, maximum=100, default=20)
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filter by project status",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="priority",
     *         in="query",
     *         description="Filter by project priority",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Search by project name or address",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="prj_manager",
     *         in="query",
     *         description="Filter by project manager ID",
     *         required=false,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Projects retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Projects retrieved successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="projects", type="array", @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="prj_name", type="string", example="Office Building Construction"),
     *                     @OA\Property(property="address", type="string", example="123 Main St, City, State"),
     *                     @OA\Property(property="date_start", type="string", format="date", example="2025-01-01"),
     *                     @OA\Property(property="date_end", type="string", format="date", example="2025-12-31"),
     *                     @OA\Property(property="priority", type="string", example="High"),
     *                     @OA\Property(property="status", type="string", example="Project Secured", description="One of: Initial Contact Lead, Dead Lead, Waiting On Direction, Actively Looking For A Location, Securing Location, Project Secured, Construction, Completed Project"),
     *                     @OA\Property(property="purchase_or_lease", type="string", enum={"Purchase","Lease"}, example="Purchase"),
     *                     @OA\Property(property="notes", type="string", nullable=true, example="Additional project notes"),
     *                     @OA\Property(property="client_id", type="integer", nullable=true, example=1),
     *                     @OA\Property(property="client_type", type="string", nullable=true, example="pharmacy"),
     *                     @OA\Property(property="client_table", type="string", enum={"pharma","physician","pharmacist","medical_clinic"}, nullable=true, example="pharma"),
     *                     @OA\Property(property="client_name", type="string", nullable=true, example="Pharmacy Name"),
     *                     @OA\Property(property="client_data", type="object", nullable=true, example={"id":1,"name":"Pharmacy Name","address":"123 Main St"}),
     *                     @OA\Property(property="client2_id", type="integer", nullable=true, example=2),
     *                     @OA\Property(property="client2_type", type="string", nullable=true, example="physician"),
     *                     @OA\Property(property="client2_table", type="string", enum={"pharma","physician","pharmacist","medical_clinic"}, nullable=true, example="physician"),
     *                     @OA\Property(property="client2_name", type="string", nullable=true, example="Second Client Name"),
     *                     @OA\Property(property="client2_data", type="object", nullable=true, example={"id":2,"name":"Second Client","address":"456 Oak St"}),
     *                     @OA\Property(property="prj_manager", type="integer", nullable=true, example=1),
     *                     @OA\Property(property="created_by", type="integer", nullable=true, example=47),
     *                     @OA\Property(property="created_by_name", type="string", nullable=true, example="John Doe"),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time")
     *                 )),
     *                 @OA\Property(property="pagination", type="object",
     *                     @OA\Property(property="current_page", type="integer", example=1),
     *                     @OA\Property(property="per_page", type="integer", example=20),
     *                     @OA\Property(property="total", type="integer", example=100),
     *                     @OA\Property(property="last_page", type="integer", example=5)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - Invalid or missing token",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=401),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Unauthorized"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     )
     * )
     */
    public function getProjects(): void
    {
        $this->logger->info('ProjectController::getProjects called');
        
        try {
            $request = Flight::request();
            $page = (int)($request->query['page'] ?? 1);
            $limit = min((int)($request->query['limit'] ?? 20), 100);
            $status = $request->query['status'] ?? null;
            $priority = $request->query['priority'] ?? null;
            $search = $request->query['search'] ?? null;
            $prjManager = $request->query['prj_manager'] ?? null;

            $offset = ($page - 1) * $limit;

            // Базовый SQL запрос
            $sql = "SELECT
                        p.id, p.prj_name, p.address, p.date_start, p.date_end,
                        p.priority, p.status, p.purchase_or_lease, p.notes, p.client_id, p.client_type, p.client_table, p.client_data, p.client_name,
                        p.client2_id, p.client2_type, p.client2_table, p.client2_data, p.client2_name,
                        p.description, p.area, p.level, p.prj_manager, p.created_by, p.created_at, p.updated_at,
                        u.first_name, u.last_name,
                        creator.first_name as created_by_first_name, creator.last_name as created_by_last_name
                    FROM fw_projects p
                    LEFT JOIN fw_v_users u ON p.prj_manager = u.id
                    LEFT JOIN fw_v_users creator ON p.created_by = creator.id
                    WHERE 1=1";

            $params = [];

            // Фильтр по статусу
            if ($status) {
                $sql .= " AND p.status = ?";
                $params[] = $status;
            }

            // Фильтр по приоритету
            if ($priority) {
                $sql .= " AND p.priority = ?";
                $params[] = $priority;
            }

            // Поиск по названию или адресу
            if ($search) {
                $sql .= " AND (p.prj_name LIKE ? OR p.address LIKE ?)";
                $searchTerm = "%{$search}%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            // Фильтр по менеджеру проекта
            if ($prjManager && $prjManager !== '0') {
                $sql .= " AND p.prj_manager = ?";
                $params[] = (int)$prjManager;
            }

            // Подсчет общего количества
            $countSql = "SELECT COUNT(*) as total FROM fw_projects p WHERE 1=1";
            $countParams = [];
            
            if ($status) {
                $countSql .= " AND p.status = ?";
                $countParams[] = $status;
            }
            
            if ($priority) {
                $countSql .= " AND p.priority = ?";
                $countParams[] = $priority;
            }
            
            if ($search) {
                $countSql .= " AND (p.prj_name LIKE ? OR p.address LIKE ?)";
                $searchTerm = "%{$search}%";
                $countParams[] = $searchTerm;
                $countParams[] = $searchTerm;
            }
            
            if ($prjManager && $prjManager !== '0') {
                $countSql .= " AND p.prj_manager = ?";
                $countParams[] = (int)$prjManager;
            }

            $connection = $this->database->getConnection();
            $countResult = $connection->executeQuery($countSql, $countParams);
            $total = $countResult->fetchOne();

            // Добавляем сортировку и пагинацию
            $sql .= " ORDER BY p.created_at DESC LIMIT {$limit} OFFSET {$offset}";

            $result = $connection->executeQuery($sql, $params);
            $projects = $result->fetchAllAssociative();

            // Форматируем данные
            $formattedProjects = array_map(function($project) {
                $clientData = $this->parseClientData($project['client_data'] ?? null);
                $client2Data = $this->parseClientData($project['client2_data'] ?? null);
                return [
                    'id' => (int)$project['id'],
                    'prj_name' => $project['prj_name'],
                    'address' => $project['address'],
                    'date_start' => $project['date_start'],
                    'date_end' => $project['date_end'],
                    'priority' => $project['priority'],
                    'status' => $project['status'],
                    'purchase_or_lease' => $project['purchase_or_lease'],
                    'notes' => $project['notes'] ?? null,
                    'client_id' => $project['client_id'] ? (int)$project['client_id'] : null,
                    'client_type' => $project['client_type'] ?? null,
                    'client_table' => $project['client_table'] ?? null,
                    'client_name' => $this->getClientNameWithFallback($project, $clientData),
                    'client_data' => $clientData,
                    'client2_id' => $project['client2_id'] ? (int)$project['client2_id'] : null,
                    'client2_type' => $project['client2_type'] ?? null,
                    'client2_table' => $project['client2_table'] ?? null,
                    'client2_name' => $this->getClient2NameWithFallback($project, $client2Data),
                    'client2_data' => $client2Data,
                    'description' => $project['description'] ?? null,
                    'area' => isset($project['area']) && $project['area'] !== null ? (int)$project['area'] : null,
                    'level' => $project['level'] ?? null,
                    'prj_manager' => $project['prj_manager'] ? (int)$project['prj_manager'] : null,
                    'created_by' => $project['created_by'] ? (int)$project['created_by'] : null,
                    'created_by_name' => $project['created_by_first_name'] && $project['created_by_last_name']
                        ? $project['created_by_first_name'] . ' ' . $project['created_by_last_name']
                        : null,
                    'manager_name' => $project['first_name'] && $project['last_name']
                        ? $project['first_name'] . ' ' . $project['last_name']
                        : null,
                    'created_at' => $project['created_at'],
                    'updated_at' => $project['updated_at']
                ];
            }, $projects);

            $lastPage = ceil($total / $limit);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Projects retrieved successfully',
                'data' => [
                    'projects' => $formattedProjects,
                    'pagination' => [
                        'current_page' => $page,
                        'per_page' => $limit,
                        'total' => (int)$total,
                        'last_page' => $lastPage
                    ]
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to retrieve projects', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'query_params' => [
                    'page' => $page,
                    'limit' => $limit,
                    'status' => $status,
                    'priority' => $priority,
                    'search' => $search,
                    'prj_manager' => $prjManager
                ]
            ]);

            // Проверяем, не связана ли ошибка с отсутствующими полями
            $errorMessage = $e->getMessage();
            if (strpos($errorMessage, 'Unknown column') !== false) {
                $this->logger->warning('Possible missing database columns. Please run migration script: scripts/add-project-client-fields.sql');
            }

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve projects: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Получить проект по ID
     * GET /api/v1/projects/{id}
     *
     * @OA\Get(
     *     path="/api/v1/projects/{id}",
     *     summary="Get project by ID",
     *     description="Retrieve a specific project by its ID",
     *     tags={"Projects"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Project ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Project retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Project retrieved successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="project", type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="prj_name", type="string", example="Office Building Construction"),
     *                     @OA\Property(property="address", type="string", example="123 Main St, City, State"),
     *                     @OA\Property(property="date_start", type="string", format="date", example="2025-01-01"),
     *                     @OA\Property(property="date_end", type="string", format="date", example="2025-12-31"),
     *                     @OA\Property(property="priority", type="string", example="High"),
     *                     @OA\Property(property="status", type="string", example="Project Secured", description="One of: Initial Contact Lead, Dead Lead, Waiting On Direction, Actively Looking For A Location, Securing Location, Project Secured, Construction, Completed Project"),
     *                     @OA\Property(property="purchase_or_lease", type="string", enum={"Purchase","Lease"}, example="Purchase"),
     *                     @OA\Property(property="notes", type="string", nullable=true, example="Additional project notes"),
     *                     @OA\Property(property="client_id", type="integer", nullable=true, example=1),
     *                     @OA\Property(property="client_type", type="string", nullable=true, example="pharmacy"),
     *                     @OA\Property(property="client_table", type="string", enum={"pharma","physician","pharmacist","medical_clinic"}, nullable=true, example="pharma"),
     *                     @OA\Property(property="client_name", type="string", nullable=true, example="Pharmacy Name"),
     *                     @OA\Property(property="client_data", type="object", nullable=true, example={"id":1,"name":"Pharmacy Name","address":"123 Main St"}),
     *                     @OA\Property(property="client2_id", type="integer", nullable=true, example=2),
     *                     @OA\Property(property="client2_type", type="string", nullable=true, example="physician"),
     *                     @OA\Property(property="client2_table", type="string", enum={"pharma","physician","pharmacist","medical_clinic"}, nullable=true, example="physician"),
     *                     @OA\Property(property="client2_name", type="string", nullable=true, example="Second Client Name"),
     *                     @OA\Property(property="client2_data", type="object", nullable=true, example={"id":2,"name":"Second Client","address":"456 Oak St"}),
     *                     @OA\Property(property="prj_manager", type="integer", nullable=true, example=1),
     *                     @OA\Property(property="created_by", type="integer", nullable=true, example=47),
     *                     @OA\Property(property="created_by_name", type="string", nullable=true, example="John Doe"),
     *                     @OA\Property(property="manager_name", type="string", nullable=true, example="John Doe"),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Project not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=404),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Project not found"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     )
     * )
     */
    public function getProject(int $id): void
    {
        $this->logger->info('ProjectController::getProject called', ['id' => $id]);
        
        try {
            $connection = $this->database->getConnection();
            
            $sql = "SELECT
                        p.id, p.prj_name, p.address, p.date_start, p.date_end,
                        p.priority, p.status, p.purchase_or_lease, p.notes, p.client_id, p.client_type, p.client_table, p.client_data, p.client_name,
                        p.client2_id, p.client2_type, p.client2_table, p.client2_data, p.client2_name,
                        p.description, p.area, p.level, p.prj_manager, p.created_by, p.created_at, p.updated_at,
                        u.first_name, u.last_name,
                        creator.first_name as created_by_first_name, creator.last_name as created_by_last_name
                    FROM fw_projects p
                    LEFT JOIN fw_v_users u ON p.prj_manager = u.id
                    LEFT JOIN fw_v_users creator ON p.created_by = creator.id
                    WHERE p.id = ?";
            
            $result = $connection->executeQuery($sql, [$id]);
            $project = $result->fetchAssociative();

            if (!$project) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Project not found',
                    'data' => null
                ], 404);
                return;
            }

            $clientData = $this->parseClientData($project['client_data'] ?? null);
            $client2Data = $this->parseClientData($project['client2_data'] ?? null);
            
            $formattedProject = [
                'id' => (int)$project['id'],
                'prj_name' => $project['prj_name'],
                'address' => $project['address'],
                'date_start' => $project['date_start'],
                'date_end' => $project['date_end'],
                'priority' => $project['priority'],
                'status' => $project['status'],
                'purchase_or_lease' => $project['purchase_or_lease'],
                'notes' => $project['notes'] ?? null,
                'client_id' => $project['client_id'] ? (int)$project['client_id'] : null,
                'client_type' => $project['client_type'] ?? null,
                'client_table' => $project['client_table'] ?? null,
                'client_name' => $this->getClientNameWithFallback($project, $clientData),
                'client_data' => $clientData,
                'client2_id' => $project['client2_id'] ? (int)$project['client2_id'] : null,
                'client2_type' => $project['client2_type'] ?? null,
                'client2_table' => $project['client2_table'] ?? null,
                'client2_name' => $this->getClient2NameWithFallback($project, $client2Data),
                'client2_data' => $client2Data,
                'description' => $project['description'] ?? null,
                'area' => isset($project['area']) && $project['area'] !== null ? (int)$project['area'] : null,
                'level' => $project['level'] ?? null,
                'prj_manager' => $project['prj_manager'] ? (int)$project['prj_manager'] : null,
                'created_by' => $project['created_by'] ? (int)$project['created_by'] : null,
                'created_by_name' => $project['created_by_first_name'] && $project['created_by_last_name']
                    ? $project['created_by_first_name'] . ' ' . $project['created_by_last_name']
                    : null,
                'manager_name' => $project['first_name'] && $project['last_name']
                    ? $project['first_name'] . ' ' . $project['last_name']
                    : null,
                'created_at' => $project['created_at'],
                'updated_at' => $project['updated_at']
            ];

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Project retrieved successfully',
                'data' => [
                    'project' => $formattedProject
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to retrieve project', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Проверяем, не связана ли ошибка с отсутствующими полями
            $errorMessage = $e->getMessage();
            if (strpos($errorMessage, 'Unknown column') !== false) {
                $this->logger->warning('Possible missing database columns. Please run migration script: scripts/add-project-client-fields.sql', [
                    'project_id' => $id
                ]);
            }

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve project: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Создать новый проект
     * POST /api/v1/projects
     *
     * @OA\Post(
     *     path="/api/v1/projects",
     *     summary="Create new project",
     *     description="Create a new project",
     *     tags={"Projects"},
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"prj_name", "address", "created_by"},
     *             @OA\Property(property="prj_name", type="string", example="Office Building Construction"),
     *             @OA\Property(property="address", type="string", example="123 Main St, City, State"),
     *             @OA\Property(property="date_start", type="string", format="date", nullable=true, example="2025-01-01"),
     *             @OA\Property(property="date_end", type="string", format="date", nullable=true, example="2025-12-31"),
     *             @OA\Property(property="priority", type="string", example="High"),
     *             @OA\Property(property="status", type="string", example="Project Secured", description="One of: Initial Contact Lead, Dead Lead, Waiting On Direction, Actively Looking For A Location, Securing Location, Project Secured, Construction, Completed Project"),
     *             @OA\Property(property="purchase_or_lease", type="string", enum={"Purchase","Lease"}, example="Purchase"),
     *             @OA\Property(property="notes", type="string", nullable=true, example="Additional project notes"),
     *             @OA\Property(property="client_id", type="integer", nullable=true, example=1),
     *             @OA\Property(property="client_type", type="string", nullable=true, example="pharmacy"),
     *             @OA\Property(property="client_table", type="string", enum={"pharma","physician","pharmacist","medical_clinic"}, nullable=true, example="pharma"),
     *             @OA\Property(property="client_name", type="string", nullable=true, example="Pharmacy Name"),
     *             @OA\Property(property="client_data", type="object", nullable=true, example={"id":1,"name":"Pharmacy Name","address":"123 Main St"}),
     *             @OA\Property(property="client2_id", type="integer", nullable=true, example=2, description="Optional second client ID"),
     *             @OA\Property(property="client2_type", type="string", nullable=true, example="physician"),
     *             @OA\Property(property="client2_table", type="string", enum={"pharma","physician","pharmacist","medical_clinic"}, nullable=true, example="physician"),
     *             @OA\Property(property="client2_name", type="string", nullable=true, example="Second Client Name"),
     *             @OA\Property(property="client2_data", type="object", nullable=true, example={"id":2,"name":"Second Client","address":"456 Oak St"}),
     *             @OA\Property(property="prj_manager", type="integer", example=1),
     *             @OA\Property(property="created_by", type="integer", example=47)
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Project created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Project created successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="project", type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="prj_name", type="string", example="Office Building Construction"),
     *                     @OA\Property(property="address", type="string", example="123 Main St, City, State"),
     *                     @OA\Property(property="date_start", type="string", format="date", example="2025-01-01"),
     *                     @OA\Property(property="date_end", type="string", format="date", example="2025-12-31"),
     *                     @OA\Property(property="priority", type="string", example="High"),
     *                     @OA\Property(property="status", type="string", example="Project Secured", description="One of: Initial Contact Lead, Dead Lead, Waiting On Direction, Actively Looking For A Location, Securing Location, Project Secured, Construction, Completed Project"),
     *                     @OA\Property(property="purchase_or_lease", type="string", enum={"Purchase","Lease"}, example="Purchase"),
     *                     @OA\Property(property="notes", type="string", nullable=true, example="Additional project notes"),
     *                     @OA\Property(property="client_id", type="integer", nullable=true, example=1),
     *                     @OA\Property(property="client_type", type="string", nullable=true, example="pharmacy"),
     *                     @OA\Property(property="client_table", type="string", enum={"pharma","physician","pharmacist","medical_clinic"}, nullable=true, example="pharma"),
     *                     @OA\Property(property="client_name", type="string", nullable=true, example="Pharmacy Name"),
     *                     @OA\Property(property="client_data", type="object", nullable=true, example={"id":1,"name":"Pharmacy Name","address":"123 Main St"}),
     *                     @OA\Property(property="client2_id", type="integer", nullable=true, example=2),
     *                     @OA\Property(property="client2_type", type="string", nullable=true, example="physician"),
     *                     @OA\Property(property="client2_table", type="string", enum={"pharma","physician","pharmacist","medical_clinic"}, nullable=true, example="physician"),
     *                     @OA\Property(property="client2_name", type="string", nullable=true, example="Second Client Name"),
     *                     @OA\Property(property="client2_data", type="object", nullable=true, example={"id":2,"name":"Second Client","address":"456 Oak St"}),
     *                     @OA\Property(property="prj_manager", type="integer", nullable=true, example=1),
     *                     @OA\Property(property="created_by", type="integer", nullable=true, example=47),
     *                     @OA\Property(property="created_by_name", type="string", nullable=true, example="John Doe"),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=400),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Validation failed"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     )
     * )
     */
    public function createProject(): void
    {
        $this->logger->info('ProjectController::createProject called');
        
        try {
            $request = Flight::request();
            $data = json_decode($request->getBody(), true);

            // Валидация данных
            $validation = $this->validateProjectData($data);
            if (!$validation['valid']) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => $validation['message'],
                    'data' => null
                ], 400);
                return;
            }

            $connection = $this->database->getConnection();
            
            // Получаем имя клиента если указаны client_id и client_table
            $clientName = null;
            if (!empty($data['client_id']) && !empty($data['client_table'])) {
                $clientName = $this->getClientName($data['client_table'], (int)$data['client_id']);
            }
            $client2Name = null;
            if (!empty($data['client2_id']) && !empty($data['client2_table'])) {
                $client2Name = $this->getClientName($data['client2_table'], (int)$data['client2_id']);
            }
            
            $sql = "INSERT INTO fw_projects (prj_name, address, date_start, date_end, priority, status, purchase_or_lease, notes, client_id, client_type, client_table, client_data, client_name, client2_id, client2_type, client2_table, client2_data, client2_name, area, level, prj_manager, created_by, description)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $params = [
                $data['prj_name'],
                $data['address'],
                $data['date_start'] ?? null,
                $data['date_end'] ?? null,
                $data['priority'] ?? null,
                $data['status'] ?? null,
                $data['purchase_or_lease'] ?? 'Purchase',
                $data['notes'] ?? null,
                $data['client_id'] ?? null,
                $data['client_type'] ?? null,
                $data['client_table'] ?? null,
                $this->encodeClientData($data['client_data'] ?? null),
                $clientName,
                $data['client2_id'] ?? null,
                $data['client2_type'] ?? null,
                $data['client2_table'] ?? null,
                $this->encodeClientData($data['client2_data'] ?? null),
                $client2Name,
                isset($data['area']) && $data['area'] !== null ? (int)$data['area'] : null,
                $data['level'] ?? null,
                $data['prj_manager'] ?? null,
                $data['created_by'] ?? null,
                $data['description'] ?? null
            ];

            $connection->executeStatement($sql, $params);
            $projectId = $connection->lastInsertId();

            // Получаем созданный проект с информацией о создателе
            $result = $connection->executeQuery(
                "SELECT p.*, creator.first_name as created_by_first_name, creator.last_name as created_by_last_name
                 FROM fw_projects p
                 LEFT JOIN fw_v_users creator ON p.created_by = creator.id
                 WHERE p.id = ?",
                [$projectId]
            );
            $project = $result->fetchAssociative();

            // Копируем стандартную структуру папок из проекта-образца (project_id = 0) в новый проект
            $this->logger->info('About to copy default folder structure', ['project_id' => $projectId]);
            $this->copyDefaultFolderStructure($projectId, $connection);
            $this->logger->info('Finished copying default folder structure', ['project_id' => $projectId]);

            // Логируем событие создания проекта
            $this->logProjectCreationEvent($project, $data);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Project created successfully',
                'data' => [
                    'project' => [
                        'id' => (int)$project['id'],
                        'prj_name' => $project['prj_name'],
                        'address' => $project['address'],
                        'date_start' => $project['date_start'],
                        'date_end' => $project['date_end'],
                        'priority' => $project['priority'],
                        'status' => $project['status'],
                        'purchase_or_lease' => $project['purchase_or_lease'],
                        'notes' => $project['notes'] ?? null,
                        'client_id' => $project['client_id'] ? (int)$project['client_id'] : null,
                        'client_type' => $project['client_type'] ?? null,
                        'client_table' => $project['client_table'] ?? null,
                        'client_name' => $this->getClientNameWithFallback($project, $this->parseClientData($project['client_data'] ?? null)),
                        'client_data' => $this->parseClientData($project['client_data'] ?? null),
                        'client2_id' => $project['client2_id'] ? (int)$project['client2_id'] : null,
                        'client2_type' => $project['client2_type'] ?? null,
                        'client2_table' => $project['client2_table'] ?? null,
                        'client2_name' => $this->getClient2NameWithFallback($project, $this->parseClientData($project['client2_data'] ?? null)),
                        'client2_data' => $this->parseClientData($project['client2_data'] ?? null),
                        'description' => $project['description'] ?? null,
                        'area' => isset($project['area']) && $project['area'] !== null ? (int)$project['area'] : null,
                        'level' => $project['level'] ?? null,
                        'prj_manager' => $project['prj_manager'] ? (int)$project['prj_manager'] : null,
                        'created_by' => $project['created_by'] ? (int)$project['created_by'] : null,
                        'created_by_name' => $project['created_by_first_name'] && $project['created_by_last_name']
                            ? $project['created_by_first_name'] . ' ' . $project['created_by_last_name']
                            : null,
                        'created_at' => $project['created_at'],
                        'updated_at' => $project['updated_at']
                    ]
                ]
            ], 201);

        } catch (Exception $e) {
            $this->logger->error('Failed to create project', [
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to create project',
                'data' => null
            ], 500);
        }
    }

    /**
     * Обновить проект
     * PUT /api/v1/projects/{id}
     *
     * @OA\Put(
     *     path="/api/v1/projects/{id}",
     *     summary="Update project",
     *     description="Update an existing project",
     *     tags={"Projects"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Project ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="prj_name", type="string", example="Office Building Construction"),
     *             @OA\Property(property="address", type="string", example="123 Main St, City, State"),
     *             @OA\Property(property="date_start", type="string", format="date", example="2025-01-01"),
     *             @OA\Property(property="date_end", type="string", format="date", example="2025-12-31"),
     *             @OA\Property(property="priority", type="string", example="High"),
     *             @OA\Property(property="status", type="string", example="Project Secured", description="One of: Initial Contact Lead, Dead Lead, Waiting On Direction, Actively Looking For A Location, Securing Location, Project Secured, Construction, Completed Project"),
     *             @OA\Property(property="purchase_or_lease", type="string", enum={"Purchase","Lease"}, example="Purchase"),
     *             @OA\Property(property="notes", type="string", nullable=true, example="Additional project notes"),
     *             @OA\Property(property="client_id", type="integer", nullable=true, example=1),
     *             @OA\Property(property="client_type", type="string", nullable=true, example="pharmacy"),
     *             @OA\Property(property="client_table", type="string", enum={"pharma","physician","pharmacist","medical_clinic"}, nullable=true, example="pharma"),
     *             @OA\Property(property="client_name", type="string", nullable=true, example="Pharmacy Name"),
     *             @OA\Property(property="client_data", type="object", nullable=true, example={"id":1,"name":"Pharmacy Name","address":"123 Main St"}),
     *             @OA\Property(property="client2_id", type="integer", nullable=true, example=2),
     *             @OA\Property(property="client2_type", type="string", nullable=true, example="physician"),
     *             @OA\Property(property="client2_table", type="string", enum={"pharma","physician","pharmacist","medical_clinic"}, nullable=true, example="physician"),
     *             @OA\Property(property="client2_name", type="string", nullable=true, example="Second Client Name"),
     *             @OA\Property(property="client2_data", type="object", nullable=true, example={"id":2,"name":"Second Client","address":"456 Oak St"}),
     *             @OA\Property(property="prj_manager", type="integer", example=1),
     *             @OA\Property(property="created_by", type="integer", example=47)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Project updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Project updated successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="project", type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="prj_name", type="string", example="Office Building Construction"),
     *                     @OA\Property(property="address", type="string", example="123 Main St, City, State"),
     *                     @OA\Property(property="date_start", type="string", format="date", example="2025-01-01"),
     *                     @OA\Property(property="date_end", type="string", format="date", example="2025-12-31"),
     *                     @OA\Property(property="priority", type="string", example="High"),
     *                     @OA\Property(property="status", type="string", example="Project Secured", description="One of: Initial Contact Lead, Dead Lead, Waiting On Direction, Actively Looking For A Location, Securing Location, Project Secured, Construction, Completed Project"),
     *                     @OA\Property(property="prj_manager", type="integer", nullable=true, example=1),
     *                     @OA\Property(property="created_by", type="integer", nullable=true, example=47),
     *                     @OA\Property(property="created_by_name", type="string", nullable=true, example="John Doe"),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Project not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=404),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Project not found"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     )
     * )
     */
    public function updateProject(int $id): void
    {
        $this->logger->info('ProjectController::updateProject called', ['id' => $id]);
        
        try {
            $request = Flight::request();
            $data = json_decode($request->getBody(), true);

            $connection = $this->database->getConnection();
            
            // Проверяем, существует ли проект
            $checkResult = $connection->executeQuery(
                "SELECT id FROM fw_projects WHERE id = ?",
                [$id]
            );
            
            if (!$checkResult->fetchOne()) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Project not found',
                    'data' => null
                ], 404);
                return;
            }

            // Валидация данных
            $validation = $this->validateProjectData($data, false);
            if (!$validation['valid']) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => $validation['message'],
                    'data' => null
                ], 400);
                return;
            }

            // Получаем текущие данные проекта перед обновлением для логирования
            $beforeResult = $connection->executeQuery(
                "SELECT id, prj_name, address, date_start, date_end, priority, status, purchase_or_lease, notes, client_id, client_type, client_table, client_data, client_name, client2_id, client2_type, client2_table, client2_data, client2_name, description, area, level, prj_manager, created_by, created_at, updated_at
                 FROM fw_projects WHERE id = ?",
                [$id]
            );
            $beforeData = $beforeResult->fetchAssociative();

            // Строим SQL запрос для обновления
            $updateFields = [];
            $params = [];

            if (isset($data['prj_name'])) {
                $updateFields[] = "prj_name = ?";
                $params[] = $data['prj_name'];
            }
            if (isset($data['address'])) {
                $updateFields[] = "address = ?";
                $params[] = $data['address'];
            }
            // date_start и date_end допускают null (partial update)
            if (array_key_exists('date_start', $data)) {
                $updateFields[] = "date_start = ?";
                $params[] = $data['date_start'];
            }
            if (array_key_exists('date_end', $data)) {
                $updateFields[] = "date_end = ?";
                $params[] = $data['date_end'];
            }
            if (isset($data['priority'])) {
                $updateFields[] = "priority = ?";
                $params[] = $data['priority'];
            }
            if (isset($data['status'])) {
                $updateFields[] = "status = ?";
                $params[] = $data['status'];
            }
            if (isset($data['purchase_or_lease'])) {
                $updateFields[] = "purchase_or_lease = ?";
                $params[] = $data['purchase_or_lease'];
            }
            if (isset($data['notes'])) {
                $updateFields[] = "notes = ?";
                $params[] = $data['notes'];
            }
            // Поля клиента обновляются даже если они null (для очистки клиента)
            if (array_key_exists('client_id', $data)) {
                $updateFields[] = "client_id = ?";
                $params[] = $data['client_id'];
            }
            if (array_key_exists('client_type', $data)) {
                $updateFields[] = "client_type = ?";
                $params[] = $data['client_type'];
            }
            if (array_key_exists('client_table', $data)) {
                $updateFields[] = "client_table = ?";
                $params[] = $data['client_table'];
            }
            if (array_key_exists('client_data', $data)) {
                $updateFields[] = "client_data = ?";
                $encodedClientData = $this->encodeClientData($data['client_data']);
                $params[] = $encodedClientData;
                
                // Логируем что сохраняется в client_data для диагностики
                $this->logger->debug('Saving client_data to project', [
                    'project_id' => $id,
                    'client_id' => $data['client_id'] ?? null,
                    'client_table' => $data['client_table'] ?? null,
                    'client_data_raw' => $data['client_data'],
                    'client_data_encoded' => $encodedClientData,
                    'client_data_keys' => is_array($data['client_data']) ? array_keys($data['client_data']) : 'not_array'
                ]);
            }
            
            // Обновляем client_name если изменились client_id или client_table
            if (array_key_exists('client_id', $data) || array_key_exists('client_table', $data)) {
                $clientId = array_key_exists('client_id', $data) ? $data['client_id'] : null;
                $clientTable = array_key_exists('client_table', $data) ? $data['client_table'] : null;
                
                // Если client_id или client_table были удалены (null), очищаем client_name
                if (!$clientId || !$clientTable) {
                    $updateFields[] = "client_name = ?";
                    $params[] = null;
                } else {
                    // Получаем имя клиента из соответствующей таблицы
                    $clientName = $this->getClientName($clientTable, (int)$clientId);
                    $updateFields[] = "client_name = ?";
                    $params[] = $clientName;
                }
            }
            if (array_key_exists('client2_id', $data)) {
                $updateFields[] = "client2_id = ?";
                $params[] = $data['client2_id'];
            }
            if (array_key_exists('client2_type', $data)) {
                $updateFields[] = "client2_type = ?";
                $params[] = $data['client2_type'];
            }
            if (array_key_exists('client2_table', $data)) {
                $updateFields[] = "client2_table = ?";
                $params[] = $data['client2_table'];
            }
            if (array_key_exists('client2_data', $data)) {
                $updateFields[] = "client2_data = ?";
                $params[] = $this->encodeClientData($data['client2_data']);
            }
            if (array_key_exists('client2_id', $data) || array_key_exists('client2_table', $data)) {
                $client2Id = array_key_exists('client2_id', $data) ? $data['client2_id'] : null;
                $client2Table = array_key_exists('client2_table', $data) ? $data['client2_table'] : null;
                if (!$client2Id || !$client2Table) {
                    $updateFields[] = "client2_name = ?";
                    $params[] = null;
                } else {
                    $client2Name = $this->getClientName($client2Table, (int)$client2Id);
                    $updateFields[] = "client2_name = ?";
                    $params[] = $client2Name;
                }
            }
            if (isset($data['description'])) {
                $updateFields[] = "description = ?";
                $params[] = $data['description'];
            }
            if (isset($data['prj_manager'])) {
                $updateFields[] = "prj_manager = ?";
                $params[] = $data['prj_manager'];
            }
            if (isset($data['created_by'])) {
                $updateFields[] = "created_by = ?";
                $params[] = $data['created_by'];
            }
            if (array_key_exists('area', $data)) {
                $updateFields[] = "area = ?";
                $params[] = $data['area'] !== null ? (int)$data['area'] : null;
            }
            if (array_key_exists('level', $data)) {
                $updateFields[] = "level = ?";
                $params[] = $data['level'];
            }

            if (empty($updateFields)) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'No fields to update',
                    'data' => null
                ], 400);
                return;
            }

            $updateFields[] = "updated_at = NOW()";
            $params[] = $id;

            $sql = "UPDATE fw_projects SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $connection->executeStatement($sql, $params);

            // Получаем обновленный проект с информацией о создателе
            $result = $connection->executeQuery(
                "SELECT p.*, creator.first_name as created_by_first_name, creator.last_name as created_by_last_name
                 FROM fw_projects p
                 LEFT JOIN fw_v_users creator ON p.created_by = creator.id
                 WHERE p.id = ?",
                [$id]
            );
            $project = $result->fetchAssociative();

            // Логируем событие обновления проекта
            try {
                $user = Flight::get('current_user');
                $actorId = $user['id'] ?? $beforeData['created_by'] ?? null;
                
                $afterData = [
                    'id' => (int)$project['id'],
                    'prj_name' => $project['prj_name'],
                    'address' => $project['address'],
                    'date_start' => $project['date_start'],
                    'date_end' => $project['date_end'],
                    'priority' => $project['priority'],
                    'status' => $project['status'],
                    'purchase_or_lease' => $project['purchase_or_lease'],
                    'notes' => $project['notes'] ?? null,
                    'client_id' => $project['client_id'] ? (int)$project['client_id'] : null,
                    'client_type' => $project['client_type'] ?? null,
                    'client_table' => $project['client_table'] ?? null,
                    'client_name' => $this->getClientNameWithFallback($project, $this->parseClientData($project['client_data'] ?? null)),
                    'client_data' => $this->parseClientData($project['client_data'] ?? null),
                    'client2_id' => $project['client2_id'] ? (int)$project['client2_id'] : null,
                    'client2_type' => $project['client2_type'] ?? null,
                    'client2_table' => $project['client2_table'] ?? null,
                    'client2_name' => $this->getClient2NameWithFallback($project, $this->parseClientData($project['client2_data'] ?? null)),
                    'client2_data' => $this->parseClientData($project['client2_data'] ?? null),
                    'description' => $project['description'] ?? null,
                    'prj_manager' => $project['prj_manager'] ? (int)$project['prj_manager'] : null,
                    'created_by' => $project['created_by'] ? (int)$project['created_by'] : null,
                    'updated_at' => $project['updated_at']
                ];

                $this->eventLoggingService->logSimple(
                    entityType: 'project',
                    entityId: $id,
                    eventType: 'PROJECT_UPDATED',
                    afterData: $afterData,
                    options: [
                        'actor_type' => 'user',
                        'actor_id' => $actorId,
                        'before_data' => [
                            'id' => (int)$beforeData['id'],
                            'prj_name' => $beforeData['prj_name'],
                            'address' => $beforeData['address'],
                            'date_start' => $beforeData['date_start'],
                            'date_end' => $beforeData['date_end'],
                            'priority' => $beforeData['priority'],
                            'status' => $beforeData['status'],
                            'purchase_or_lease' => $beforeData['purchase_or_lease'],
                            'notes' => $beforeData['notes'] ?? null,
                            'client_id' => $beforeData['client_id'] ? (int)$beforeData['client_id'] : null,
                            'client_type' => $beforeData['client_type'] ?? null,
                            'client_table' => $beforeData['client_table'] ?? null,
                            'client_name' => $beforeData['client_name'] ?? null,
                            'client_data' => $this->parseClientData($beforeData['client_data'] ?? null),
                            'client2_id' => $beforeData['client2_id'] ? (int)$beforeData['client2_id'] : null,
                            'client2_type' => $beforeData['client2_type'] ?? null,
                            'client2_table' => $beforeData['client2_table'] ?? null,
                            'client2_name' => $beforeData['client2_name'] ?? null,
                            'client2_data' => $this->parseClientData($beforeData['client2_data'] ?? null),
                            'description' => $beforeData['description'] ?? null,
                            'area' => isset($beforeData['area']) && $beforeData['area'] !== null ? (int)$beforeData['area'] : null,
                            'level' => $beforeData['level'] ?? null,
                            'prj_manager' => $beforeData['prj_manager'] ? (int)$beforeData['prj_manager'] : null,
                            'created_by' => $beforeData['created_by'] ? (int)$beforeData['created_by'] : null
                        ],
                        'changed_fields' => array_keys($data),
                        'comment' => 'Project updated',
                        'ip' => $this->getClientIp(),
                        'user_agent' => $this->getUserAgent(),
                        'severity' => 'important'
                    ]
                );

                // Если изменился статус, логируем отдельное событие
                if (isset($data['status']) && $beforeData['status'] !== $project['status']) {
                    $this->eventLoggingService->logSimple(
                        entityType: 'project',
                        entityId: $id,
                        eventType: 'PROJECT_STATUS_CHANGED',
                        afterData: [
                            'status' => $project['status'],
                            'previous_status' => $beforeData['status'],
                            'project_id' => $id,
                            'project_name' => $project['prj_name']
                        ],
                        options: [
                            'actor_type' => 'user',
                            'actor_id' => $actorId,
                            'before_data' => ['status' => $beforeData['status']],
                            'changed_fields' => ['status'],
                            'comment' => "Project status changed from '{$beforeData['status']}' to '{$project['status']}'",
                            'ip' => $this->getClientIp(),
                            'user_agent' => $this->getUserAgent(),
                            'severity' => 'important'
                        ]
                    );
                }
            } catch (\Exception $e) {
                $this->logger->warning('Failed to log project update event', [
                    'error' => $e->getMessage(),
                    'project_id' => $id
                ]);
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Project updated successfully',
                'data' => [
                    'project' => [
                        'id' => (int)$project['id'],
                        'prj_name' => $project['prj_name'],
                        'address' => $project['address'],
                        'date_start' => $project['date_start'],
                        'date_end' => $project['date_end'],
                        'priority' => $project['priority'],
                        'status' => $project['status'],
                        'purchase_or_lease' => $project['purchase_or_lease'],
                        'notes' => $project['notes'] ?? null,
                        'client_id' => $project['client_id'] ? (int)$project['client_id'] : null,
                        'client_type' => $project['client_type'] ?? null,
                        'client_table' => $project['client_table'] ?? null,
                        'client_name' => $this->getClientNameWithFallback($project, $this->parseClientData($project['client_data'] ?? null)),
                        'client_data' => $this->parseClientData($project['client_data'] ?? null),
                        'client2_id' => $project['client2_id'] ? (int)$project['client2_id'] : null,
                        'client2_type' => $project['client2_type'] ?? null,
                        'client2_table' => $project['client2_table'] ?? null,
                        'client2_name' => $this->getClient2NameWithFallback($project, $this->parseClientData($project['client2_data'] ?? null)),
                        'client2_data' => $this->parseClientData($project['client2_data'] ?? null),
                        'description' => $project['description'] ?? null,
                        'area' => isset($project['area']) && $project['area'] !== null ? (int)$project['area'] : null,
                        'level' => $project['level'] ?? null,
                        'prj_manager' => $project['prj_manager'] ? (int)$project['prj_manager'] : null,
                        'created_by' => $project['created_by'] ? (int)$project['created_by'] : null,
                        'created_by_name' => $project['created_by_first_name'] && $project['created_by_last_name']
                            ? $project['created_by_first_name'] . ' ' . $project['created_by_last_name']
                            : null,
                        'created_at' => $project['created_at'],
                        'updated_at' => $project['updated_at']
                    ]
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to update project', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to update project',
                'data' => null
            ], 500);
        }
    }

    /**
     * Удалить проект
     * DELETE /api/v1/projects/{id}
     *
     * @OA\Delete(
     *     path="/api/v1/projects/{id}",
     *     summary="Delete project",
     *     description="Delete a project by ID",
     *     tags={"Projects"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Project ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Project deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Project deleted successfully"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Project not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=404),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Project not found"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     )
     * )
     */
    public function deleteProject(int $id): void
    {
        $this->logger->info('ProjectController::deleteProject called', ['id' => $id]);
        
        try{
            $connection = $this->database->getConnection();
            
            // Получаем данные проекта перед удалением для логирования
            $projectResult = $connection->executeQuery(
                "SELECT id, prj_name, address, date_start, date_end, priority, status, prj_manager, created_by, created_at, updated_at
                 FROM fw_projects WHERE id = ?",
                [$id]
            );
            $projectData = $projectResult->fetchAssociative();
            
            if (!$projectData) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Project not found',
                    'data' => null
                ], 404);
                return;
            }

            // Удаляем проект
            $connection->executeStatement(
                "DELETE FROM fw_projects WHERE id = ?",
                [$id]
            );

            // Логируем событие удаления проекта
            try {
                $user = Flight::get('current_user');
                $actorId = $user['id'] ?? $projectData['created_by'] ?? null;

                $this->eventLoggingService->logSimple(
                    entityType: 'project',
                    entityId: $id,
                    eventType: 'PROJECT_DELETED',
                    afterData: [
                        'id' => (int)$projectData['id'],
                        'prj_name' => $projectData['prj_name'],
                        'status' => $projectData['status'],
                        'deleted_at' => date('c')
                    ],
                    options: [
                        'actor_type' => 'user',
                        'actor_id' => $actorId,
                        'before_data' => [
                            'id' => (int)$projectData['id'],
                            'prj_name' => $projectData['prj_name'],
                            'address' => $projectData['address'],
                            'date_start' => $projectData['date_start'],
                            'date_end' => $projectData['date_end'],
                            'priority' => $projectData['priority'],
                            'status' => $projectData['status'],
                            'prj_manager' => $projectData['prj_manager'] ? (int)$projectData['prj_manager'] : null,
                            'created_by' => $projectData['created_by'] ? (int)$projectData['created_by'] : null
                        ],
                        'changed_fields' => ['deleted'],
                        'comment' => "Project '{$projectData['prj_name']}' deleted",
                        'ip' => $this->getClientIp(),
                        'user_agent' => $this->getUserAgent(),
                        'severity' => 'important'
                    ]
                );
            } catch (\Exception $e) {
                $this->logger->warning('Failed to log project deletion event', [
                    'error' => $e->getMessage(),
                    'project_id' => $id
                ]);
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Project deleted successfully',
                'data' => null
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to delete project', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to delete project',
                'data' => null
            ], 500);
        }
    }

    /**
     * Валидация данных проекта
     */
    private function validateProjectData(array $data, bool $isCreate = true): array
    {
        // date_start and date_end are optional (nullable) - project dates can be derived from tasks
        $requiredFields = ['prj_name', 'address', 'created_by'];
        
        if ($isCreate) {
            foreach ($requiredFields as $field) {
                $val = $data[$field] ?? null;
                if ($val === null || (is_string($val) && trim($val) === '')) {
                    return [
                        'valid' => false,
                        'message' => "Field '{$field}' is required"
                    ];
                }
            }
        }

        // Валидация длины полей
        if (isset($data['prj_name']) && strlen($data['prj_name']) > 150) {
            return [
                'valid' => false,
                'message' => 'Project name must not exceed 150 characters'
            ];
        }

        if (isset($data['address']) && strlen($data['address']) > 250) {
            return [
                'valid' => false,
                'message' => 'Address must not exceed 250 characters'
            ];
        }

        if (isset($data['priority']) && strlen($data['priority']) > 100) {
            return [
                'valid' => false,
                'message' => 'Priority must not exceed 100 characters'
            ];
        }

        if (isset($data['status'])) {
            if (strlen($data['status']) > 100) {
                return [
                    'valid' => false,
                    'message' => 'Status must not exceed 100 characters'
                ];
            }
            if (!in_array($data['status'], self::ALLOWED_PROJECT_STATUSES, true)) {
                return [
                    'valid' => false,
                    'message' => 'Invalid status. Allowed: ' . implode(', ', self::ALLOWED_PROJECT_STATUSES)
                ];
            }
        }

        if (isset($data['purchase_or_lease']) && !in_array($data['purchase_or_lease'], ['Purchase', 'Lease'], true)) {
            return [
                'valid' => false,
                'message' => 'purchase_or_lease must be either Purchase or Lease'
            ];
        }

        // area: non-negative integer or null
        if (array_key_exists('area', $data) && $data['area'] !== null) {
            if (!is_numeric($data['area']) || (int)$data['area'] < 0) {
                return [
                    'valid' => false,
                    'message' => 'area must be a non-negative integer or null'
                ];
            }
        }

        // level: one of allowed enum values or null (DB has "Bacics" spelling)
        if (array_key_exists('level', $data) && $data['level'] !== null && $data['level'] !== '') {
            if (!in_array($data['level'], self::ALLOWED_PROJECT_LEVELS, true)) {
                return [
                    'valid' => false,
                    'message' => 'Invalid level. Allowed: ' . implode(', ', self::ALLOWED_PROJECT_LEVELS)
                ];
            }
        }

        if (isset($data['notes']) && strlen($data['notes']) > 1000) {
            return [
                'valid' => false,
                'message' => 'Notes must not exceed 1000 characters'
            ];
        }

        // Валидация client_id (может быть null или положительное целое число)
        if (isset($data['client_id']) && $data['client_id'] !== null) {
            if (!is_numeric($data['client_id']) || $data['client_id'] <= 0) {
                return [
                    'valid' => false,
                    'message' => 'client_id must be a positive integer or null'
                ];
            }
        }

        // Валидация client_table (может быть null или одно из допустимых значений)
        if (isset($data['client_table']) && $data['client_table'] !== null) {
            if (!in_array($data['client_table'], ['pharma', 'physician', 'pharmacist', 'medical_clinic'], true)) {
                return [
                    'valid' => false,
                    'message' => 'client_table must be one of: pharma, physician, pharmacist, medical_clinic or null'
                ];
            }
        }

        // Валидация client_data (должен быть валидным JSON или массивом)
        if (isset($data['client_data']) && $data['client_data'] !== null) {
            if (is_string($data['client_data'])) {
                $decoded = json_decode($data['client_data'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return [
                        'valid' => false,
                        'message' => 'client_data must be valid JSON'
                    ];
                }
            } elseif (!is_array($data['client_data']) && !is_object($data['client_data'])) {
                return [
                    'valid' => false,
                    'message' => 'client_data must be a valid JSON object or array'
                ];
            }
        }

        // Валидация client2_* (все поля необязательные; проверка только при передаче)
        if (isset($data['client2_id']) && $data['client2_id'] !== null) {
            if (!is_numeric($data['client2_id']) || $data['client2_id'] <= 0) {
                return [
                    'valid' => false,
                    'message' => 'client2_id must be a positive integer or null'
                ];
            }
        }
        if (isset($data['client2_table']) && $data['client2_table'] !== null) {
            if (!in_array($data['client2_table'], ['pharma', 'physician', 'pharmacist', 'medical_clinic'], true)) {
                return [
                    'valid' => false,
                    'message' => 'client2_table must be one of: pharma, physician, pharmacist, medical_clinic or null'
                ];
            }
        }
        if (isset($data['client2_data']) && $data['client2_data'] !== null) {
            if (is_string($data['client2_data'])) {
                $decoded = json_decode($data['client2_data'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return [
                        'valid' => false,
                        'message' => 'client2_data must be valid JSON'
                    ];
                }
            } elseif (!is_array($data['client2_data']) && !is_object($data['client2_data'])) {
                return [
                    'valid' => false,
                    'message' => 'client2_data must be a valid JSON object or array'
                ];
            }
        }

        // Валидация дат (date_start и date_end могут быть null)
        if (isset($data['date_start']) && $data['date_start'] !== null && $data['date_start'] !== '') {
            if (!is_string($data['date_start']) || !$this->isValidDate($data['date_start'])) {
                return [
                    'valid' => false,
                    'message' => 'Invalid date_start format. Use YYYY-MM-DD or null'
                ];
            }
        }

        if (isset($data['date_end']) && $data['date_end'] !== null && $data['date_end'] !== '') {
            if (!is_string($data['date_end']) || !$this->isValidDate($data['date_end'])) {
                return [
                    'valid' => false,
                    'message' => 'Invalid date_end format. Use YYYY-MM-DD or null'
                ];
            }
        }

        // Проверка, что дата окончания не раньше даты начала (только если обе заданы)
        if (!empty($data['date_start']) && !empty($data['date_end'])) {
            if (strtotime($data['date_end']) < strtotime($data['date_start'])) {
                return [
                    'valid' => false,
                    'message' => 'End date must be after start date'
                ];
            }
        }

        // Валидация created_by
        if (isset($data['created_by']) && (!is_numeric($data['created_by']) || $data['created_by'] <= 0)) {
            return [
                'valid' => false,
                'message' => 'created_by must be a positive integer'
            ];
        }

        // Валидация ID менеджера
        if (isset($data['prj_manager']) && !is_numeric($data['prj_manager'])) {
            return [
                'valid' => false,
                'message' => 'Project manager ID must be numeric'
            ];
        }

        return ['valid' => true];
    }

    /**
     * Проверка валидности даты
     */
    private function isValidDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    /**
     * Проверка авторизации
     */
    private function checkAuth(): bool
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            Flight::json([
                'error_code' => 401,
                'status' => 'error',
                'message' => 'Authorization header required',
                'data' => null
            ], 401);
            return false;
        }

        $token = substr($authHeader, 7);
        
        try {
            // Правильная JWT проверка
            $parts = explode('.', $token);
            
            if (count($parts) !== 3) {
                Flight::json([
                    'error_code' => 401,
                    'status' => 'error',
                    'message' => 'Invalid token format',
                    'data' => null
                ], 401);
                return false;
            }

            [$base64Header, $base64Payload, $base64Signature] = $parts;

            // Декодируем payload
            $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $base64Payload)), true);

            if (!$payload || !isset($payload['user_id']) || !isset($payload['exp'])) {
                Flight::json([
                    'error_code' => 401,
                    'status' => 'error',
                    'message' => 'Invalid token',
                    'data' => null
                ], 401);
                return false;
            }

            // Проверяем подпись
            $secret = $_ENV['JWT_SECRET'] ?? 'your-secret-key-change-in-production';
            $expectedSignature = hash_hmac('sha256', $base64Header . '.' . $base64Payload, $secret, true);
            $expectedBase64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($expectedSignature));

            if (!hash_equals($expectedBase64Signature, $base64Signature)) {
                Flight::json([
                    'error_code' => 401,
                    'status' => 'error',
                    'message' => 'Invalid token signature',
                    'data' => null
                ], 401);
                return false;
            }

            // Проверяем срок действия
            if ($payload['exp'] < time()) {
                Flight::json([
                    'error_code' => 401,
                    'status' => 'error',
                    'message' => 'Token expired',
                    'data' => null
                ], 401);
                return false;
            }
            
            return true;
        } catch (\Exception $e) {
            Flight::json([
                'error_code' => 401,
                'status' => 'error',
                'message' => 'Invalid token',
                'data' => null
            ], 401);
            return false;
        }
    }

    /**
     * Логирует событие создания проекта
     */
    private function logProjectCreationEvent(array $project, array $requestData): void
    {
        try {
            // Определяем тип актора (админ или менеджер)
            $actorType = 'user';
            $actorId = $project['created_by'];
            
            // Подготавливаем данные для логирования
            $afterData = [
                'id' => (int)$project['id'],
                'prj_name' => $project['prj_name'],
                'address' => $project['address'],
                'date_start' => $project['date_start'],
                'date_end' => $project['date_end'],
                'priority' => $project['priority'],
                'status' => $project['status'],
                'purchase_or_lease' => $project['purchase_or_lease'],
                'notes' => $project['notes'] ?? null,
                'client_id' => $project['client_id'] ? (int)$project['client_id'] : null,
                'client_type' => $project['client_type'] ?? null,
                'client_table' => $project['client_table'] ?? null,
                'client_name' => $this->getClientNameWithFallback($project, $this->parseClientData($project['client_data'] ?? null)),
                'client_data' => $this->parseClientData($project['client_data'] ?? null),
                'client2_id' => $project['client2_id'] ? (int)$project['client2_id'] : null,
                'client2_type' => $project['client2_type'] ?? null,
                'client2_table' => $project['client2_table'] ?? null,
                'client2_name' => $this->getClient2NameWithFallback($project, $this->parseClientData($project['client2_data'] ?? null)),
                'client2_data' => $this->parseClientData($project['client2_data'] ?? null),
                'description' => $project['description'] ?? null,
                'prj_manager' => $project['prj_manager'] ? (int)$project['prj_manager'] : null,
                'created_by' => $project['created_by'] ? (int)$project['created_by'] : null,
                'created_at' => $project['created_at'],
                'updated_at' => $project['updated_at']
            ];

            $changedFields = array_keys($requestData);
            
            // Логируем событие
            $this->eventLoggingService->logEvent(
                'project',
                (int)$project['id'],
                'PROJECT_CREATED',
                [],
                $afterData,
                $changedFields,
                [
                    'actor_type' => $actorType,
                    'actor_id' => $actorId,
                    'comment' => 'Project created via API',
                    'ip' => $this->getClientIp(),
                    'user_agent' => $this->getUserAgent()
                ]
            );

            $this->logger->info('Project creation event logged', [
                'project_id' => $project['id'],
                'created_by' => $project['created_by'],
                'prj_manager' => $project['prj_manager']
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to log project creation event', [
                'project_id' => $project['id'] ?? null,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Получает IP адрес клиента
     */
    private function getClientIp(): ?string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                return trim($ips[0]);
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? null;
    }

    /**
     * Получает User Agent клиента
     */
    private function getUserAgent(): ?string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? null;
    }

    /**
     * Копирует стандартную структуру папок из проекта-образца (project_id = 0) в новый проект
     */
    private function copyDefaultFolderStructure(int $newProjectId, $connection): void
    {
        $this->logger->info('copyDefaultFolderStructure method called', [
            'new_project_id' => $newProjectId,
            'connection_type' => get_class($connection)
        ]);
        
        try {
            $this->logger->info('Copying default folder structure to new project', [
                'new_project_id' => $newProjectId
            ]);

            // Получаем все папки из проекта-образца (project_id = 0)
            $this->logger->info('Executing query to get default folders', [
                'sql' => 'SELECT id, name, parent_id, created_at, updated_at FROM fw_plan_folders WHERE project_id = 0 ORDER BY parent_id ASC, id ASC'
            ]);
            try {
                $templateFolders = $connection->executeQuery(
                "SELECT id, name, parent_id, created_at, updated_at 
                     FROM fw_plan_folders 
                     WHERE project_id = 0 
                     ORDER BY parent_id ASC, id ASC"
                )->fetchAllAssociative();
            } catch (\Throwable $e) {
                $this->logger->error('Query to get default folders failed', [
                    'error' => $e->getMessage()
                ]);
                return;
            }

            $this->logger->info('Query executed, found folders', [
                'folder_count' => count($templateFolders),
                'folders' => $templateFolders
            ]);

            if (empty($templateFolders)) {
                $this->logger->info('No default folders found to copy');
                return;
            }

            // Создаем маппинг старых ID на новые ID
            $idMapping = [];
            
            // Первый проход: вставляем все папки с правильным parent_id
            foreach ($templateFolders as $folder) {
                $oldId = (int)$folder['id'];
                $oldParentId = (int)$folder['parent_id'];
                
                // Определяем parent_id для новой папки
                $newParentId = null;
                if ($oldParentId == 0) {
                    // Корневые папки остаются корневыми (parent_id = NULL)
                    $newParentId = null;
                } else {
                    // Вложенные папки пока ставим parent_id = new_project_id (временно)
                    $newParentId = $newProjectId;
                }
                
                // Вставляем папку с новым project_id
                try {
                    $connection->executeStatement(
                        "INSERT INTO fw_plan_folders (name, parent_id, project_id, created_at, updated_at) 
                         VALUES (?, ?, ?, ?, ?)",
                        [
                            $folder['name'],
                            $newParentId,
                            $newProjectId,
                            $folder['created_at'],
                            $folder['updated_at']
                        ]
                    );
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to insert default folder', [
                        'new_project_id' => $newProjectId,
                        'folder' => $folder,
                        'error' => $e->getMessage()
                    ]);
                    continue;
                }
                
                $newId = $connection->lastInsertId();
                $idMapping[$oldId] = $newId;
            }

            // Второй проход: обновляем parent_id для вложенных папок
            foreach ($templateFolders as $folder) {
                $oldId = (int)$folder['id'];
                $oldParentId = (int)$folder['parent_id'];
                
                if ($oldParentId > 0 && isset($idMapping[$oldParentId])) {
                    // Если это вложенная папка и мы знаем новый ID родительской папки
                    $newId = $idMapping[$oldId];
                    $newParentId = $idMapping[$oldParentId];
                    
                    try {
                        $connection->executeStatement(
                            "UPDATE fw_plan_folders 
                             SET parent_id = ? 
                             WHERE id = ?",
                            [$newParentId, $newId]
                        );
                    } catch (\Throwable $e) {
                        $this->logger->error('Failed to update parent_id for folder', [
                            'folder_id' => $newId,
                            'new_parent_id' => $newParentId,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            $this->logger->info('Default folder structure copied successfully', [
                'new_project_id' => $newProjectId,
                'folders_copied' => count($templateFolders),
                'id_mapping' => $idMapping
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to copy default folder structure', [
                'new_project_id' => $newProjectId,
                'error' => $e->getMessage()
            ]);
            // Не прерываем создание проекта, если копирование папок не удалось
        }
    }

    /**
     * Безопасная обработка поля client_data из БД
     * Обрабатывает случаи когда поле может быть JSON строкой, уже декодированным массивом, или NULL
     * 
     * @param mixed $clientData Значение client_data из БД
     * @return array|null Декодированный массив или null
     */
    private function parseClientData($clientData): ?array
    {
        // Если null или пустая строка
        if ($clientData === null || $clientData === '') {
            return null;
        }

        // Если уже массив - возвращаем как есть
        if (is_array($clientData)) {
            return $clientData;
        }

        // Если не строка - возвращаем null
        if (!is_string($clientData)) {
            $this->logger->warning('Unexpected client_data type', [
                'type' => gettype($clientData),
                'value' => $clientData
            ]);
            return null;
        }

        // Пытаемся декодировать JSON
        $decoded = json_decode($clientData, true);
        
        // Проверяем ошибки декодирования
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('Failed to decode client_data JSON', [
                'error' => json_last_error_msg(),
                'raw_value' => substr($clientData, 0, 200) // Логируем первые 200 символов
            ]);
            return null;
        }

        // Логируем что возвращается из client_data для диагностики
        if (is_array($decoded)) {
            $this->logger->debug('Parsed client_data from DB', [
                'keys' => array_keys($decoded),
                'keys_count' => count($decoded),
                'sample' => array_slice($decoded, 0, 3, true) // Первые 3 элемента для примера
            ]);
        }

        return $decoded;
    }

    /**
     * Безопасное кодирование client_data для сохранения в БД
     * Обрабатывает случаи когда значение может быть уже строкой JSON, массивом, или NULL
     * 
     * @param mixed $clientData Значение client_data для сохранения
     * @return string|null JSON строка или null
     */
    private function encodeClientData($clientData): ?string
    {
        // Если null - возвращаем null
        if ($clientData === null) {
            return null;
        }

        // Если уже строка - проверяем, является ли она валидным JSON
        if (is_string($clientData)) {
            // Пытаемся декодировать, чтобы проверить валидность
            $decoded = json_decode($clientData, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                // Это валидный JSON - возвращаем как есть
                return $clientData;
            } else {
                // Не валидный JSON - логируем предупреждение и кодируем как строку
                $this->logger->warning('client_data is string but not valid JSON, encoding as string value', [
                    'raw_value' => substr($clientData, 0, 200)
                ]);
                return json_encode($clientData);
            }
        }

        // Если массив или объект - кодируем в JSON
        if (is_array($clientData) || is_object($clientData)) {
            $encoded = json_encode($clientData);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error('Failed to encode client_data to JSON', [
                    'error' => json_last_error_msg()
                ]);
                return null;
            }
            return $encoded;
        }

        // Для других типов - кодируем значение
        $encoded = json_encode($clientData);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('Unexpected client_data type for encoding', [
                'type' => gettype($clientData)
            ]);
            return null;
        }
        return $encoded;
    }

    /**
     * Получить имя клиента из соответствующей таблицы
     * 
     * @param string|null $clientTable Тип таблицы клиента
     * @param int|null $clientId ID клиента
     * @return string|null Имя клиента или null
     */
    private function getClientName(?string $clientTable, ?int $clientId): ?string
    {
        if (!$clientTable || !$clientId) {
            return null;
        }

        // Маппинг типов таблиц на их реальные имена в БД и поля name
        $clientTables = [
            'pharma' => [
                'table' => 'pharma',
                'name_field' => 'operName'
            ],
            'physician' => [
                'table' => 'physician',
                'name_field' => 'fullName'
            ],
            'pharmacist' => [
                'table' => 'pharmacist',
                'name_field' => 'fullName'
            ],
            'medical_clinic' => [
                'table' => 'medical_clinic',
                'name_field' => 'clinicName'
            ]
        ];

        if (!isset($clientTables[$clientTable])) {
            $this->logger->warning('Unknown client_table type', [
                'client_table' => $clientTable,
                'client_id' => $clientId
            ]);
            return null;
        }

        try {
            $connection = $this->database->getConnection();
            $tableConfig = $clientTables[$clientTable];
            
            $sql = "SELECT {$tableConfig['name_field']} as name 
                    FROM {$tableConfig['table']} 
                    WHERE id = ? 
                    LIMIT 1";
            
            $result = $connection->executeQuery($sql, [$clientId]);
            $client = $result->fetchAssociative();
            
            return $client['name'] ?? null;
        } catch (Exception $e) {
            $this->logger->error('Failed to get client name', [
                'client_table' => $clientTable,
                'client_id' => $clientId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Получить имя клиента с fallback на client_data
     * 
     * @param array $project Данные проекта из БД
     * @param array|null $clientData Распарсенные данные client_data
     * @return string|null Имя клиента или null
     */
    private function getClientNameWithFallback(array $project, ?array $clientData): ?string
    {
        // Сначала пробуем получить из поля client_name в БД
        $clientName = $project['client_name'] ?? null;
        
        if ($clientName) {
            return $clientName;
        }
        
        // Если client_name не заполнен, пробуем получить из client_data
        if ($clientData && is_array($clientData)) {
            // Пробуем разные поля в зависимости от типа клиента
            // Для pharma - operName
            // Для physician/pharmacist - fullName
            // Для medical_clinic - clinicName
            // Также пробуем общее поле name
            $clientName = $clientData['operName'] 
                ?? $clientData['fullName'] 
                ?? $clientData['clinicName'] 
                ?? $clientData['name'] 
                ?? null;
        }
        
        // Если все еще нет имени, но есть client_id и client_table, пробуем получить из БД
        if (!$clientName && !empty($project['client_id']) && !empty($project['client_table'])) {
            $clientName = $this->getClientName($project['client_table'], (int)$project['client_id']);
        }
        
        return $clientName;
    }

    /**
     * Get client2 display name with fallback (client2_name, client2_data, or lookup by client2_id/client2_table)
     * @param array $project Row with client2_* keys
     * @param array|null $client2Data Parsed client2_data
     * @return string|null
     */
    private function getClient2NameWithFallback(array $project, ?array $client2Data): ?string
    {
        $clientName = $project['client2_name'] ?? null;
        if ($clientName) {
            return $clientName;
        }
        if ($client2Data && is_array($client2Data)) {
            $clientName = $client2Data['operName']
                ?? $client2Data['fullName']
                ?? $client2Data['clinicName']
                ?? $client2Data['name']
                ?? null;
        }
        if (!$clientName && !empty($project['client2_id']) && !empty($project['client2_table'])) {
            $clientName = $this->getClientName($project['client2_table'], (int)$project['client2_id']);
        }
        return $clientName;
    }
}
