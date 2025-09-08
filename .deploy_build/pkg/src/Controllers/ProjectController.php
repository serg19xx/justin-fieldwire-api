<?php

namespace App\Controllers;

use App\Database\Database;
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

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        
        try {
            $this->database = new Database();
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
        // Проверка токена
        if (!$this->checkAuth()) {
            return;
        }

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
                        p.priority, p.status, p.prj_manager, p.created_at, p.updated_at,
                        u.first_name, u.last_name
                    FROM fw_projects p
                    LEFT JOIN fw_users u ON p.prj_manager = u.id
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
                    'prj_manager' => $project['prj_manager'] ? (int)$project['prj_manager'] : null,
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
        // Проверка токена
        if (!$this->checkAuth()) {
            return;
        }

        try {
            $connection = $this->database->getConnection();
            
            $sql = "SELECT 
                        p.id, p.prj_name, p.address, p.date_start, p.date_end, 
                        p.priority, p.status, p.prj_manager, p.created_at, p.updated_at,
                        u.first_name, u.last_name
                    FROM fw_projects p
                    LEFT JOIN fw_users u ON p.prj_manager = u.id
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
                'prj_manager' => $project['prj_manager'] ? (int)$project['prj_manager'] : null,
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
     *             required={"prj_name", "address", "date_start", "date_end", "priority"},
     *             @OA\Property(property="prj_name", type="string", example="Office Building Construction"),
     *             @OA\Property(property="address", type="string", example="123 Main St, City, State"),
     *             @OA\Property(property="date_start", type="string", format="date", example="2025-01-01"),
     *             @OA\Property(property="date_end", type="string", format="date", example="2025-12-31"),
     *             @OA\Property(property="priority", type="string", example="High"),
     *             @OA\Property(property="status", type="string", example="Active"),
     *             @OA\Property(property="prj_manager", type="integer", example=1)
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
        // Проверка токена
        if (!$this->checkAuth()) {
            return;
        }

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
            
            $sql = "INSERT INTO fw_projects (prj_name, address, date_start, date_end, priority, status, prj_manager) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            
            $params = [
                $data['prj_name'],
                $data['address'],
                $data['date_start'],
                $data['date_end'],
                $data['priority'],
                $data['status'] ?? null,
                $data['prj_manager'] ?? null
            ];

            $connection->executeStatement($sql, $params);
            $projectId = $connection->lastInsertId();

            // Получаем созданный проект
            $result = $connection->executeQuery(
                "SELECT * FROM fw_projects WHERE id = ?",
                [$projectId]
            );
            $project = $result->fetchAssociative();

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
                        'prj_manager' => $project['prj_manager'] ? (int)$project['prj_manager'] : null,
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
     *             @OA\Property(property="prj_manager", type="integer", example=1)
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
        // Проверка токена
        if (!$this->checkAuth()) {
            return;
        }

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
            if (isset($data['prj_manager'])) {
                $updateFields[] = "prj_manager = ?";
                $params[] = $data['prj_manager'];
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

            // Получаем обновленный проект
            $result = $connection->executeQuery(
                "SELECT * FROM fw_projects WHERE id = ?",
                [$id]
            );
            $project = $result->fetchAssociative();

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
        // Проверка токена
        if (!$this->checkAuth()) {
            return;
        }

        try {
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

            // Удаляем проект
            $connection->executeStatement(
                "DELETE FROM fw_projects WHERE id = ?",
                [$id]
            );

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
        $requiredFields = ['prj_name', 'address', 'date_start', 'date_end', 'priority'];
        
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
            // Декодируем простой base64 токен (как в AuthController)
            $decoded = base64_decode($token);
            if ($decoded === false) {
                Flight::json([
                    'error_code' => 401,
                    'status' => 'error',
                    'message' => 'Invalid token',
                    'data' => null
                ], 401);
                return false;
            }
            
            $payload = json_decode($decoded, true);
            if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) {
                Flight::json([
                    'error_code' => 401,
                    'status' => 'error',
                    'message' => 'Invalid or expired token',
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
}
