<?php

namespace App\Controllers;

use App\Database\Database;
use Doctrine\DBAL\Exception;
use Flight;
use Monolog\Logger;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Tasks",
 *     description="Project tasks management endpoints"
 * )
 */
class TaskController
{
    private Logger $logger;
    private Database $database;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        
        try {
            $this->database = new Database();
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize TaskController', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Получить список задач проекта
     * GET /api/v1/projects/{project_id}/tasks
     *
     * @OA\Get(
     *     path="/api/v1/projects/{project_id}/tasks",
     *     summary="Get project tasks",
     *     description="Retrieve a paginated list of tasks for a specific project",
     *     tags={"Tasks"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="project_id",
     *         in="path",
     *         description="Project ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filter by task status",
     *         required=false,
     *         @OA\Schema(type="string", enum={"planned", "in_progress", "done", "blocked", "delayed"})
     *     ),
     *     @OA\Parameter(
     *         name="milestone",
     *         in="query",
     *         description="Filter by milestone flag",
     *         required=false,
     *         @OA\Schema(type="boolean")
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Search by task name",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Tasks retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Tasks retrieved successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="tasks", type="array", @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="project_id", type="integer", example=1),
     *                     @OA\Property(property="wbs_path", type="string", example="1.1.1"),
     *                     @OA\Property(property="name", type="string", example="Design Phase"),
     *                     @OA\Property(property="start_planned", type="string", format="date", example="2025-01-01"),
     *                     @OA\Property(property="end_planned", type="string", format="date", example="2025-01-15"),
     *                     @OA\Property(property="milestone", type="boolean", example=false),
     *                     @OA\Property(property="status", type="string", example="planned"),
     *                     @OA\Property(property="progress_pct", type="integer", example=0),
     *                     @OA\Property(property="notes", type="string", example="Initial design phase"),
     *                     @OA\Property(property="task_lead_id", type="integer", example=47),
     *                     @OA\Property(property="team_members", type="array", @OA\Items(type="integer"), example={23, 45, 67}),
     *                     @OA\Property(property="resources", type="array", @OA\Items(type="string")),
     *                     @OA\Property(property="dependencies", type="array", 
     *                         @OA\Items(type="object",
     *                             @OA\Property(property="predecessor_id", type="integer", example=4),
     *                             @OA\Property(property="type", type="string", example="FS"),
     *                             @OA\Property(property="lag_days", type="integer", example=1)
     *                         )
     *                     ),
     *                     @OA\Property(property="baseline_start", type="string", format="date", nullable=true),
     *                     @OA\Property(property="baseline_end", type="string", format="date", nullable=true),
      *                    @OA\Property(property="actual_start", type="string", format="date", nullable=true),
     *                     @OA\Property(property="actual_end", type="string", format="date", nullable=true),
     *                     @OA\Property(property="slack_days", type="integer", nullable=true),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time")
     *                 )),
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
    public function getTasks(int $projectId): void
    {
        // Проверка токена
        if (!$this->checkAuth()) {
            return;
        }

        try {
            $request = Flight::request();
            $status = $request->query['status'] ?? null;
            $milestone = $request->query['milestone'] ?? null;
            $search = $request->query['search'] ?? null;

            // Проверяем, существует ли проект
            $connection = $this->database->getConnection();
            $projectCheck = $connection->executeQuery(
                "SELECT id FROM fw_projects WHERE id = ?",
                [$projectId]
            );
            
            if (!$projectCheck->fetchOne()) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Project not found',
                    'data' => null
                ], 404);
                return;
            }

            // Базовый SQL запрос
            $sql = "SELECT id, project_id, wbs_path, name, start_planned, end_planned, milestone, status, progress_pct, notes, resources, dependencies, baseline_start, baseline_end, actual_start, actual_end, slack_days, task_lead_id, team_members, created_at, updated_at FROM fw_prj_tasks WHERE project_id = ?";
            $params = [$projectId];

            // Фильтр по статусу
            if ($status) {
                $sql .= " AND status = ?";
                $params[] = $status;
            }

            // Фильтр по milestone
            if ($milestone !== null) {
                $sql .= " AND milestone = ?";
                $params[] = $milestone === 'true' ? 1 : 0;
            }

            // Поиск по названию
            if ($search) {
                $sql .= " AND name LIKE ?";
                $params[] = "%{$search}%";
            }

            // Подсчет общего количества
            // Добавляем сортировку
            $sql .= " ORDER BY start_planned ASC";

            $result = $connection->executeQuery($sql, $params);
            $tasks = $result->fetchAllAssociative();

            // Форматируем данные
            $formattedTasks = array_map(function($task) {
                return [
                    'id' => (int)$task['id'],
                    'project_id' => (int)$task['project_id'],
                    'wbs_path' => $task['wbs_path'] ? json_decode($task['wbs_path'], true) : null,
                    'name' => $task['name'],
                    'start_planned' => $task['start_planned'],
                    'end_planned' => $task['end_planned'],
                    'milestone' => (bool)$task['milestone'],
                    'status' => $task['status'],
                    'progress_pct' => (int)$task['progress_pct'],
                    'notes' => $task['notes'],
                    'task_lead_id' => isset($task['task_lead_id']) && $task['task_lead_id'] ? (int)$task['task_lead_id'] : null,
                    'team_members' => isset($task['team_members']) && $task['team_members'] ? json_decode($task['team_members'], true) : null,
                    'resources' => $task['resources'] ? json_decode($task['resources'], true) : null,
                    'dependencies' => $task['dependencies'] ? json_decode($task['dependencies'], true) : null,
                    'baseline_start' => $task['baseline_start'],
                    'baseline_end' => $task['baseline_end'],
                    'actual_start' => $task['actual_start'],
                    'actual_end' => $task['actual_end'],
                    'slack_days' => $task['slack_days'] ? (int)$task['slack_days'] : null,
                    'created_at' => $task['created_at'],
                    'updated_at' => $task['updated_at']
                ];
            }, $tasks);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Tasks retrieved successfully',
                'data' => [
                    'tasks' => $formattedTasks
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to retrieve tasks', [
                'project_id' => $projectId,
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve tasks',
                'data' => null
            ], 500);
        }
    }

    /**
     * Получить задачу по ID
     * GET /api/v1/projects/{project_id}/tasks/{task_id}
     *
     * @OA\Get(
     *     path="/api/v1/projects/{project_id}/tasks/{task_id}",
     *     summary="Get task by ID",
     *     description="Retrieve a specific task by its ID",
     *     tags={"Tasks"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="project_id",
     *         in="path",
     *         description="Project ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="task_id",
     *         in="path",
     *         description="Task ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Task retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Task retrieved successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="task", type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="project_id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Design Phase"),
     *                     @OA\Property(property="status", type="string", example="planned")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Task not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=404),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Task not found"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     )
     * )
     */
    public function getTask(int $projectId, int $taskId): void
    {
        // Проверка токена
        if (!$this->checkAuth()) {
            return;
        }

        try {
            $connection = $this->database->getConnection();
            
            $sql = "SELECT id, project_id, wbs_path, name, start_planned, end_planned, milestone, status, progress_pct, notes, resources, dependencies, baseline_start, baseline_end, actual_start, actual_end, slack_days, task_lead_id, team_members, created_at, updated_at FROM fw_prj_tasks WHERE id = ? AND project_id = ?";
            $result = $connection->executeQuery($sql, [$taskId, $projectId]);
            $task = $result->fetchAssociative();

            if (!$task) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Task not found',
                    'data' => null
                ], 404);
                return;
            }

            $formattedTask = [
                'id' => (int)$task['id'],
                'project_id' => (int)$task['project_id'],
                'wbs_path' => $task['wbs_path'] ? json_decode($task['wbs_path'], true) : null,
                'name' => $task['name'],
                'start_planned' => $task['start_planned'],
                'end_planned' => $task['end_planned'],
                'milestone' => (bool)$task['milestone'],
                'status' => $task['status'],
                'progress_pct' => (int)$task['progress_pct'],
                'notes' => $task['notes'],
                'task_lead_id' => isset($task['task_lead_id']) && $task['task_lead_id'] ? (int)$task['task_lead_id'] : null,
                'team_members' => isset($task['team_members']) && $task['team_members'] ? json_decode($task['team_members'], true) : null,
                'resources' => $task['resources'] ? json_decode($task['resources'], true) : null,
                'dependencies' => $task['dependencies'] ? json_decode($task['dependencies'], true) : null,
                'baseline_start' => $task['baseline_start'],
                'baseline_end' => $task['baseline_end'],
                'actual_start' => $task['actual_start'],
                'actual_end' => $task['actual_end'],
                'slack_days' => $task['slack_days'] ? (int)$task['slack_days'] : null,
                'created_at' => $task['created_at'],
                'updated_at' => $task['updated_at']
            ];

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Task retrieved successfully',
                'data' => [
                    'task' => $formattedTask
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to retrieve task', [
                'project_id' => $projectId,
                'task_id' => $taskId,
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve task',
                'data' => null
            ], 500);
        }
    }

    /**
     * Создать новую задачу
     * POST /api/v1/projects/{project_id}/tasks
     *
     * @OA\Post(
     *     path="/api/v1/projects/{project_id}/tasks",
     *     summary="Create new task",
     *     description="Create a new task for a project",
     *     tags={"Tasks"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="project_id",
     *         in="path",
     *         description="Project ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "start_planned", "task_lead_id"},
     *             @OA\Property(property="wbs_path", type="string", example="1.1.1", description="Work Breakdown Structure path"),
     *             @OA\Property(property="name", type="string", example="Foundation Work", description="Task name (required)"),
     *             @OA\Property(property="start_planned", type="string", format="date", example="2025-09-10", description="Planned start date (required)"),
     *             @OA\Property(property="end_planned", type="string", format="date", example="2025-09-12", description="Planned end date"),
     *             @OA\Property(property="milestone", type="boolean", example=false, description="Is this a milestone task"),
     *             @OA\Property(property="status", type="string", enum={"planned", "in_progress", "done", "blocked", "delayed"}, example="planned", description="Task status"),
     *             @OA\Property(property="progress_pct", type="integer", minimum=0, maximum=100, example=0, description="Progress percentage"),
     *             @OA\Property(property="notes", type="string", example="Foundation work description", description="Task notes"),
     *             @OA\Property(property="task_lead_id", type="integer", example=47, description="ID of the user who leads this task (required)"),
     *             @OA\Property(property="team_members", type="array", @OA\Items(type="integer"), example={23, 45, 67}, description="Array of user IDs who are team members/executors"),
     *             @OA\Property(property="resources", type="array", @OA\Items(type="string"), example={"excavator_1", "concrete_mixer"}, description="Array of resource names"),
     *             @OA\Property(property="dependencies", type="array", 
     *                 @OA\Items(type="object",
     *                     @OA\Property(property="predecessor_id", type="integer", example=4, description="ID of the predecessor task"),
     *                     @OA\Property(property="type", type="string", example="FS", description="Dependency type (FS=Finish to Start, SS=Start to Start, FF=Finish to Finish, SF=Start to Finish)"),
     *                     @OA\Property(property="lag_days", type="integer", example=1, description="Lag days between tasks")
     *                 ), 
     *                 example={{"predecessor_id": 4, "type": "FS", "lag_days": 1}}, 
     *                 description="Array of dependency objects"
     *             ),
     *             @OA\Property(property="baseline_start", type="string", format="date", example="2025-09-08", description="Baseline start date"),
     *             @OA\Property(property="baseline_end", type="string", format="date", example="2025-09-10", description="Baseline end date"),
     *             @OA\Property(property="actual_start", type="string", format="date", example=null, description="Actual start date"),
     *             @OA\Property(property="actual_end", type="string", format="date", example=null, description="Actual end date"),
     *             @OA\Property(property="slack_days", type="integer", example=2, description="Slack days")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Task created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Task created successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="task", type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="project_id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Design Phase")
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
     *             @OA\Property(property="message", type="string", example="Field 'name' is required"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     )
     * )
     */
    public function createTask(int $projectId): void
    {
        // Проверка токена
        if (!$this->checkAuth()) {
            return;
        }

        try {
            $request = Flight::request();
            $data = json_decode($request->getBody(), true);

            // Валидация данных
            $validation = $this->validateTaskData($data, true);
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
            
            // Проверяем, существует ли проект
            $projectCheck = $connection->executeQuery(
                "SELECT id FROM fw_projects WHERE id = ?",
                [$projectId]
            );
            
            if (!$projectCheck->fetchOne()) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Project not found',
                    'data' => null
                ], 404);
                return;
            }
            
            $sql = "INSERT INTO fw_prj_tasks (project_id, wbs_path, name, start_planned, end_planned, milestone, status, progress_pct, notes, resources, dependencies, baseline_start, baseline_end, actual_start, actual_end, slack_days, task_lead_id, team_members) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $params = [
                $projectId,
                isset($data['wbs_path']) && $data['wbs_path'] ? json_encode($data['wbs_path']) : null,
                $data['name'],
                $data['start_planned'],
                $data['end_planned'] ?? null,
                $data['milestone'] ?? false,
                $data['status'] ?? 'planned',
                $data['progress_pct'] ?? 0,
                $data['notes'] ?? null,
                isset($data['resources']) && $data['resources'] ? json_encode($data['resources']) : null,
                isset($data['dependencies']) && $data['dependencies'] ? json_encode($data['dependencies']) : null,
                $data['baseline_start'] ?? null,
                $data['baseline_end'] ?? null,
                $data['actual_start'] ?? null,
                $data['actual_end'] ?? null,
                $data['slack_days'] ?? null,
                $data['task_lead_id'],
                isset($data['team_members']) && $data['team_members'] ? json_encode($data['team_members']) : null
            ];

            $connection->executeStatement($sql, $params);
            $taskId = $connection->lastInsertId();

            // Получаем созданную задачу
            $result = $connection->executeQuery(
                "SELECT id, project_id, wbs_path, name, start_planned, end_planned, milestone, status, progress_pct, notes, resources, dependencies, baseline_start, baseline_end, actual_start, actual_end, slack_days, task_lead_id, team_members, created_at, updated_at FROM fw_prj_tasks WHERE id = ?",
                [$taskId]
            );
            $task = $result->fetchAssociative();

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Task created successfully',
                'data' => [
                    'task' => $this->formatTask($task)
                ]
            ], 201);

        } catch (Exception $e) {
            $this->logger->error('Failed to create task', [
                'project_id' => $projectId,
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to create task',
                'data' => null
            ], 500);
        }
    }

    /**
     * Обновить задачу
     * PUT /api/v1/projects/{project_id}/tasks/{task_id}
     *
     * @OA\Put(
     *     path="/api/v1/projects/{project_id}/tasks/{task_id}",
     *     summary="Update task",
     *     description="Update an existing task",
     *     tags={"Tasks"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="project_id",
     *         in="path",
     *         description="Project ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="task_id",
     *         in="path",
     *         description="Task ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="wbs_path", type="string", example="1.1.2", description="Work Breakdown Structure path"),
     *             @OA\Property(property="name", type="string", example="Updated Foundation Work", description="Task name"),
     *             @OA\Property(property="start_planned", type="string", format="date", example="2025-09-11", description="Planned start date"),
     *             @OA\Property(property="end_planned", type="string", format="date", example="2025-09-13", description="Planned end date"),
     *             @OA\Property(property="milestone", type="boolean", example=false, description="Is this a milestone task"),
     *             @OA\Property(property="status", type="string", enum={"planned", "in_progress", "done", "blocked", "delayed"}, example="in_progress", description="Task status"),
     *             @OA\Property(property="progress_pct", type="integer", minimum=0, maximum=100, example=50, description="Progress percentage"),
     *             @OA\Property(property="notes", type="string", example="Updated foundation work description", description="Task notes"),
     *             @OA\Property(property="task_lead_id", type="integer", example=47, description="ID of the user who leads this task"),
     *             @OA\Property(property="team_members", type="array", @OA\Items(type="integer"), example={23, 45, 67, 89}, description="Array of user IDs who are team members/executors"),
     *             @OA\Property(property="resources", type="array", @OA\Items(type="string"), example={"excavator_1", "concrete_mixer", "crane"}, description="Array of resource names"),
     *             @OA\Property(property="dependencies", type="array", 
     *                 @OA\Items(type="object",
     *                     @OA\Property(property="predecessor_id", type="integer", example=4, description="ID of the predecessor task"),
     *                     @OA\Property(property="type", type="string", example="FS", description="Dependency type (FS=Finish to Start, SS=Start to Start, FF=Finish to Finish, SF=Start to Finish)"),
     *                     @OA\Property(property="lag_days", type="integer", example=1, description="Lag days between tasks")
     *                 ), 
     *                 example={{"predecessor_id": 4, "type": "FS", "lag_days": 1}, {"predecessor_id": 5, "type": "SS", "lag_days": 0}}, 
     *                 description="Array of dependency objects"
     *             ),
     *             @OA\Property(property="baseline_start", type="string", format="date", example="2025-09-09", description="Baseline start date"),
     *             @OA\Property(property="baseline_end", type="string", format="date", example="2025-09-11", description="Baseline end date"),
     *             @OA\Property(property="actual_start", type="string", format="date", example="2025-09-10", description="Actual start date"),
     *             @OA\Property(property="actual_end", type="string", format="date", example=null, description="Actual end date"),
     *             @OA\Property(property="slack_days", type="integer", example=1, description="Slack days")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Task updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Task updated successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="task", type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="name", type="string", example="Updated Design Phase")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Task not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=404),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Task not found"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     )
     * )
     */
    public function updateTask(int $projectId, int $taskId): void
    {
        // Проверка токена
        if (!$this->checkAuth()) {
            return;
        }

        try {
            $request = Flight::request();
            $data = json_decode($request->getBody(), true);

            $connection = $this->database->getConnection();
            
            // Проверяем, существует ли задача
            $checkResult = $connection->executeQuery(
                "SELECT id FROM fw_prj_tasks WHERE id = ? AND project_id = ?",
                [$taskId, $projectId]
            );
            
            if (!$checkResult->fetchOne()) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Task not found',
                    'data' => null
                ], 404);
                return;
            }

            // Валидация данных
            $validation = $this->validateTaskData($data, false);
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

            if (isset($data['wbs_path'])) {
                $updateFields[] = "wbs_path = ?";
                $params[] = $data['wbs_path'] ? json_encode($data['wbs_path']) : null;
            }
            if (isset($data['name'])) {
                $updateFields[] = "name = ?";
                $params[] = $data['name'];
            }
            if (isset($data['start_planned'])) {
                $updateFields[] = "start_planned = ?";
                $params[] = $data['start_planned'];
            }
            if (isset($data['end_planned'])) {
                $updateFields[] = "end_planned = ?";
                $params[] = $data['end_planned'];
            }
            if (isset($data['milestone'])) {
                $updateFields[] = "milestone = ?";
                $params[] = $data['milestone'] ? 1 : 0;
            }
            if (isset($data['status'])) {
                $updateFields[] = "status = ?";
                $params[] = $data['status'];
            }
            if (isset($data['progress_pct'])) {
                $updateFields[] = "progress_pct = ?";
                $params[] = $data['progress_pct'];
            }
            if (isset($data['notes'])) {
                $updateFields[] = "notes = ?";
                $params[] = $data['notes'];
            }
            if (isset($data['task_lead_id'])) {
                $updateFields[] = "task_lead_id = ?";
                $params[] = $data['task_lead_id'];
            }
            if (isset($data['team_members'])) {
                $updateFields[] = "team_members = ?";
                $params[] = $data['team_members'] ? json_encode($data['team_members']) : null;
            }
            if (isset($data['resources'])) {
                $updateFields[] = "resources = ?";
                $params[] = $data['resources'] ? json_encode($data['resources']) : null;
            }
            if (isset($data['dependencies'])) {
                $updateFields[] = "dependencies = ?";
                $params[] = $data['dependencies'] ? json_encode($data['dependencies']) : null;
            }
            if (isset($data['baseline_start'])) {
                $updateFields[] = "baseline_start = ?";
                $params[] = $data['baseline_start'];
            }
            if (isset($data['baseline_end'])) {
                $updateFields[] = "baseline_end = ?";
                $params[] = $data['baseline_end'];
            }
            if (isset($data['actual_start'])) {
                $updateFields[] = "actual_start = ?";
                $params[] = $data['actual_start'];
            }
            if (isset($data['actual_end'])) {
                $updateFields[] = "actual_end = ?";
                $params[] = $data['actual_end'];
            }
            if (isset($data['slack_days'])) {
                $updateFields[] = "slack_days = ?";
                $params[] = $data['slack_days'];
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
            $params[] = $taskId;

            $sql = "UPDATE fw_prj_tasks SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $connection->executeStatement($sql, $params);

            // Получаем обновленную задачу
            $result = $connection->executeQuery(
                "SELECT id, project_id, wbs_path, name, start_planned, end_planned, milestone, status, progress_pct, notes, resources, dependencies, baseline_start, baseline_end, actual_start, actual_end, slack_days, task_lead_id, team_members, created_at, updated_at FROM fw_prj_tasks WHERE id = ?",
                [$taskId]
            );
            $task = $result->fetchAssociative();

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Task updated successfully',
                'data' => [
                    'task' => $this->formatTask($task)
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to update task', [
                'project_id' => $projectId,
                'task_id' => $taskId,
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to update task',
                'data' => null
            ], 500);
        }
    }

    /**
     * Удалить задачу
     * DELETE /api/v1/projects/{project_id}/tasks/{task_id}
     *
     * @OA\Delete(
     *     path="/api/v1/projects/{project_id}/tasks/{task_id}",
     *     summary="Delete task",
     *     description="Delete a task by ID",
     *     tags={"Tasks"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="project_id",
     *         in="path",
     *         description="Project ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="task_id",
     *         in="path",
     *         description="Task ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Task deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Task deleted successfully"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Task not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=404),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Task not found"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     )
     * )
     */
    public function deleteTask(int $projectId, int $taskId): void
    {
        // Проверка токена
        if (!$this->checkAuth()) {
            return;
        }

        try {
            $connection = $this->database->getConnection();
            
            // Проверяем, существует ли задача
            $checkResult = $connection->executeQuery(
                "SELECT id FROM fw_prj_tasks WHERE id = ? AND project_id = ?",
                [$taskId, $projectId]
            );
            
            if (!$checkResult->fetchOne()) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Task not found',
                    'data' => null
                ], 404);
                return;
            }

            // Удаляем задачу
            $connection->executeStatement(
                "DELETE FROM fw_prj_tasks WHERE id = ?",
                [$taskId]
            );

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Task deleted successfully',
                'data' => null
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to delete task', [
                'project_id' => $projectId,
                'task_id' => $taskId,
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to delete task',
                'data' => null
            ], 500);
        }
    }

    /**
     * Валидация данных задачи
     */
    private function validateTaskData(array $data, bool $isCreate = true): array
    {
        $requiredFields = ['name', 'start_planned', 'task_lead_id'];
        
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
        if (isset($data['name']) && strlen($data['name']) > 255) {
            return [
                'valid' => false,
                'message' => 'Task name must not exceed 255 characters'
            ];
        }

        // Валидация дат
        if (isset($data['start_planned']) && !$this->isValidDate($data['start_planned'])) {
            return [
                'valid' => false,
                'message' => 'Invalid start_planned format. Use YYYY-MM-DD'
            ];
        }

        if (isset($data['end_planned']) && $data['end_planned'] && !$this->isValidDate($data['end_planned'])) {
            return [
                'valid' => false,
                'message' => 'Invalid end_planned format. Use YYYY-MM-DD'
            ];
        }

        // Проверка, что дата окончания не раньше даты начала
        if (isset($data['start_planned']) && isset($data['end_planned']) && $data['end_planned']) {
            if (strtotime($data['end_planned']) < strtotime($data['start_planned'])) {
                return [
                    'valid' => false,
                    'message' => 'End date must be after start date'
                ];
            }
        }

        // Проверка границ проекта убрана - теперь выполняется на фронтенде

        // Валидация статуса
        if (isset($data['status'])) {
            $validStatuses = ['planned', 'in_progress', 'done', 'blocked', 'delayed'];
            if (!in_array($data['status'], $validStatuses)) {
                return [
                    'valid' => false,
                    'message' => 'Invalid status. Must be one of: ' . implode(', ', $validStatuses)
                ];
            }
        }

        // Валидация progress_pct
        if (isset($data['progress_pct'])) {
            if (!is_numeric($data['progress_pct']) || $data['progress_pct'] < 0 || $data['progress_pct'] > 100) {
                return [
                    'valid' => false,
                    'message' => 'Progress percentage must be between 0 and 100'
                ];
            }
        }

        // Валидация task_lead_id
        if (isset($data['task_lead_id'])) {
            if (!is_numeric($data['task_lead_id']) || $data['task_lead_id'] <= 0) {
                return [
                    'valid' => false,
                    'message' => 'Task lead ID must be a positive number'
                ];
            }
            
            // Проверяем, существует ли пользователь
            try {
                $connection = $this->database->getConnection();
                $stmt = $connection->executeQuery(
                    "SELECT id FROM fw_v_users WHERE id = ? AND status = 1",
                    [$data['task_lead_id']]
                );
                
                if (!$stmt->fetchOne()) {
                    return [
                        'valid' => false,
                        'message' => 'Task lead user not found or inactive'
                    ];
                }
            } catch (Exception $e) {
                // Если не удается проверить пользователя, пропускаем валидацию
                // чтобы не блокировать создание задачи из-за проблем с БД
            }
        }

        return ['valid' => true];
    }

    /**
     * Проверка дат задачи против границ проекта
     */
    private function validateTaskDatesAgainstProject(array $data, int $projectId): array
    {
        try {
            // Логируем начало валидации
            $this->logger->info('Starting project bounds validation', [
                'current_date' => date('Y-m-d H:i:s'),
                'project_id' => $projectId,
                'task_data' => $data
            ]);
            
            $connection = $this->database->getConnection();
            
            // Получаем даты проекта
            $projectResult = $connection->executeQuery(
                "SELECT date_start, date_end FROM fw_projects WHERE id = ?",
                [$projectId]
            );
            $project = $projectResult->fetchAssociative();
            
            if (!$project) {
                return [
                    'valid' => false,
                    'message' => 'Project not found'
                ];
            }
            
            $projectStart = $project['date_start'];
            $projectEnd = $project['date_end'];
            
            // Проверяем дату начала задачи
            if (isset($data['start_planned'])) {
                $taskStart = new \DateTime($data['start_planned']);
                $projectStartDate = new \DateTime($projectStart);
                
                // Сравниваем объекты DateTime напрямую
                $isBefore = $taskStart < $projectStartDate;
                
                // Логируем для отладки
                $this->logger->info('Project bounds validation - start date', [
                    'current_date' => date('Y-m-d H:i:s'),
                    'task_start' => $data['start_planned'],
                    'project_start' => $projectStart,
                    'task_start_date' => $taskStart->format('Y-m-d'),
                    'project_start_date' => $projectStartDate->format('Y-m-d'),
                    'is_before' => $isBefore
                ]);
                
                if ($isBefore) {
                    return [
                        'valid' => false,
                        'message' => 'Task start date cannot be before project start date'
                    ];
                }
            }
            
            // Проверяем дату окончания задачи
            if (isset($data['end_planned']) && $data['end_planned']) {
                $taskEnd = new \DateTime($data['end_planned']);
                $projectEndDate = new \DateTime($projectEnd);
                
                // Сравниваем объекты DateTime напрямую
                $isAfter = $taskEnd > $projectEndDate;
                
                // Логируем для отладки
                $this->logger->info('Project bounds validation - end date', [
                    'current_date' => date('Y-m-d H:i:s'),
                    'task_end' => $data['end_planned'],
                    'project_end' => $projectEnd,
                    'task_end_date' => $taskEnd->format('Y-m-d'),
                    'project_end_date' => $projectEndDate->format('Y-m-d'),
                    'task_end_full' => $taskEnd->format('Y-m-d H:i:s'),
                    'project_end_full' => $projectEndDate->format('Y-m-d H:i:s'),
                    'task_timestamp' => $taskEnd->getTimestamp(),
                    'project_timestamp' => $projectEndDate->getTimestamp(),
                    'is_after' => $isAfter
                ]);
                
                // Используем > чтобы срабатывало только после 8 октября (на 9 октября и позже)
                if ($isAfter) {
                    return [
                        'valid' => false,
                        'message' => 'Task end date cannot be after project end date'
                    ];
                }
            }
            
            return ['valid' => true];
            
        } catch (Exception $e) {
            // Если не удается проверить проект, пропускаем валидацию
            return ['valid' => true];
        }
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
     * Форматирование задачи
     */
    private function formatTask(array $task): array
    {
        return [
            'id' => (int)$task['id'],
            'project_id' => (int)$task['project_id'],
            'wbs_path' => $task['wbs_path'] ? json_decode($task['wbs_path'], true) : null,
            'name' => $task['name'],
            'start_planned' => $task['start_planned'],
            'end_planned' => $task['end_planned'],
            'milestone' => (bool)$task['milestone'],
            'status' => $task['status'],
            'progress_pct' => (int)$task['progress_pct'],
            'notes' => $task['notes'],
            'task_lead_id' => isset($task['task_lead_id']) && $task['task_lead_id'] ? (int)$task['task_lead_id'] : null,
            'team_members' => isset($task['team_members']) && $task['team_members'] ? json_decode($task['team_members'], true) : null,
            'resources' => $task['resources'] ? json_decode($task['resources'], true) : null,
            'dependencies' => $task['dependencies'] ? json_decode($task['dependencies'], true) : null,
            'baseline_start' => $task['baseline_start'],
            'baseline_end' => $task['baseline_end'],
            'actual_start' => $task['actual_start'],
            'actual_end' => $task['actual_end'],
            'slack_days' => $task['slack_days'] ? (int)$task['slack_days'] : null,
            'created_at' => $task['created_at'],
            'updated_at' => $task['updated_at']
        ];
    }

    /**
     * Проверить границы проекта для задачи
     * GET /api/v1/projects/{project_id}/tasks/check-bounds
     *
     * @OA\Get(
     *     path="/api/v1/projects/{project_id}/tasks/check-bounds",
     *     summary="Check task bounds against project",
     *     description="Check if task dates are within project bounds",
     *     tags={"Tasks"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="project_id",
     *         in="path",
     *         description="Project ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="start_planned",
     *         in="query",
     *         description="Task start date",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="end_planned",
     *         in="query",
     *         description="Task end date",
     *         required=false,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Bounds check result",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Task dates are within project bounds"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="valid", type="boolean", example=true),
     *                 @OA\Property(property="project_start", type="string", format="date"),
     *                 @OA\Property(property="project_end", type="string", format="date")
     *             )
     *         )
     *     )
     * )
     */
    public function checkTaskBounds(int $projectId): void
    {
        // Проверка токена
        if (!$this->checkAuth()) {
            return;
        }

        try {
            $request = Flight::request();
            $startPlanned = $request->query['start_planned'] ?? null;
            $endPlanned = $request->query['end_planned'] ?? null;

            $connection = $this->database->getConnection();
            
            // Получаем даты проекта
            $projectResult = $connection->executeQuery(
                "SELECT date_start, date_end FROM fw_projects WHERE id = ?",
                [$projectId]
            );
            $project = $projectResult->fetchAssociative();
            
            if (!$project) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Project not found',
                    'data' => null
                ], 404);
                return;
            }

            $projectStart = $project['date_start'];
            $projectEnd = $project['date_end'];

            $validation = $this->validateTaskDatesAgainstProject([
                'start_planned' => $startPlanned,
                'end_planned' => $endPlanned
            ], $projectId);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => $validation['valid'] ? 'Task dates are within project bounds' : 'Task dates are outside project bounds',
                'data' => [
                    'valid' => $validation['valid'],
                    'project_start' => $projectStart,
                    'project_end' => $projectEnd,
                    'message' => $validation['message'] ?? null
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to check task bounds', [
                'project_id' => $projectId,
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to check task bounds',
                'data' => null
            ], 500);
        }
    }

    /**
     * Получить информацию о границах проекта
     */
    private function getProjectBoundsInfo(int $projectId): array
    {
        try {
            $connection = $this->database->getConnection();
            
            $projectResult = $connection->executeQuery(
                "SELECT date_start, date_end FROM fw_projects WHERE id = ?",
                [$projectId]
            );
            $project = $projectResult->fetchAssociative();
            
            if (!$project) {
                return [
                    'project_start' => null,
                    'project_end' => null
                ];
            }
            
            return [
                'project_start' => $project['date_start'],
                'project_end' => $project['date_end']
            ];
            
        } catch (Exception $e) {
            return [
                'project_start' => null,
                'project_end' => null
            ];
        }
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
