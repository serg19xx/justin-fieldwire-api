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
     *                     @OA\Property(property="status", type="string", example="Active"),
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
                        p.priority, p.status, p.description, p.prj_manager, p.created_by, p.created_at, p.updated_at,
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
                return [
                    'id' => (int)$project['id'],
                    'prj_name' => $project['prj_name'],
                    'address' => $project['address'],
                    'date_start' => $project['date_start'],
                    'date_end' => $project['date_end'],
                    'priority' => $project['priority'],
                    'status' => $project['status'],
                    'description' => $project['description'] ?? null,
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
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve projects',
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
     *                     @OA\Property(property="status", type="string", example="Active"),
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
                        p.priority, p.status, p.description, p.prj_manager, p.created_by, p.created_at, p.updated_at,
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

            $formattedProject = [
                'id' => (int)$project['id'],
                'prj_name' => $project['prj_name'],
                'address' => $project['address'],
                'date_start' => $project['date_start'],
                'date_end' => $project['date_end'],
                'priority' => $project['priority'],
                'status' => $project['status'],
                'description' => $project['description'] ?? null,
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
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve project',
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
     *             required={"prj_name", "address", "date_start", "date_end", "priority", "created_by"},
     *             @OA\Property(property="prj_name", type="string", example="Office Building Construction"),
     *             @OA\Property(property="address", type="string", example="123 Main St, City, State"),
     *             @OA\Property(property="date_start", type="string", format="date", example="2025-01-01"),
     *             @OA\Property(property="date_end", type="string", format="date", example="2025-12-31"),
     *             @OA\Property(property="priority", type="string", example="High"),
     *             @OA\Property(property="status", type="string", example="Active"),
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
     *                     @OA\Property(property="status", type="string", example="Active"),
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
            
            $sql = "INSERT INTO fw_projects (prj_name, address, date_start, date_end, priority, status, prj_manager, created_by, description) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $params = [
                $data['prj_name'],
                $data['address'],
                $data['date_start'],
                $data['date_end'],
                $data['priority'],
                $data['status'] ?? null,
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
                        'description' => $project['description'] ?? null,
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
     *             @OA\Property(property="status", type="string", example="Active"),
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
     *                     @OA\Property(property="status", type="string", example="Active"),
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
                "SELECT id, prj_name, address, date_start, date_end, priority, status, description, prj_manager, created_by, created_at, updated_at
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
            if (isset($data['date_start'])) {
                $updateFields[] = "date_start = ?";
                $params[] = $data['date_start'];
            }
            if (isset($data['date_end'])) {
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
                            'description' => $beforeData['description'] ?? null,
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
        $requiredFields = ['prj_name', 'address', 'date_start', 'date_end', 'priority', 'created_by'];
        
        if ($isCreate) {
            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || empty(trim($data[$field]))) {
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

        if (isset($data['status']) && strlen($data['status']) > 100) {
            return [
                'valid' => false,
                'message' => 'Status must not exceed 100 characters'
            ];
        }

        // Валидация дат
        if (isset($data['date_start']) && !$this->isValidDate($data['date_start'])) {
            return [
                'valid' => false,
                'message' => 'Invalid date_start format. Use YYYY-MM-DD'
            ];
        }

        if (isset($data['date_end']) && !$this->isValidDate($data['date_end'])) {
            return [
                'valid' => false,
                'message' => 'Invalid date_end format. Use YYYY-MM-DD'
            ];
        }

        // Проверка, что дата окончания не раньше даты начала
        if (isset($data['date_start']) && isset($data['date_end'])) {
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
}
