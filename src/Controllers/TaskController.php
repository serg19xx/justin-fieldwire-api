<?php

namespace App\Controllers;

use App\Database\Database;
use App\Services\EventLoggingService;
use App\Services\FieldWorkNotificationService;
use App\Services\TaskAuthorizationService;
use Doctrine\DBAL\Connection;
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
    private const TASK_ADDRESS_MAX_LEN = 500;

    /** Base columns always present on fw_prj_tasks. */
    private const TASK_DETAIL_BASE_COLUMNS = [
        'id', 'task_order', 'project_id', 'address', 'name', 'start_planned', 'end_planned',
        'start_time', 'end_time', 'milestone', 'status', 'progress_pct', 'notes', 'resources',
        'baseline_start', 'baseline_end', 'actual_start', 'actual_end', 'slack_days',
        'created_at', 'updated_at',
    ];

    /** Optional columns added by field-work migrations (may be missing on older DBs). */
    private const TASK_DETAIL_OPTIONAL_COLUMNS = [
        'field_submitted_at',
        'field_submitted_by',
        'field_work_started_at',
        'field_work_started_by',
        'field_work_start_lat',
        'field_work_start_lng',
        'field_work_ended_at',
        'field_work_ended_by',
        'field_work_end_lat',
        'field_work_end_lng',
        'field_notes',
        'field_work_start_reason',
        'field_work_end_reason',
    ];

    private static ?string $resolvedTaskDetailSelect = null;

    /** @var array<string, bool>|null */
    private static ?array $taskOptionalColumnExists = null;

    private Logger $logger;
    private Database $database;
    private EventLoggingService $eventLoggingService;
    private TaskAuthorizationService $taskAuth;
    private FieldWorkNotificationService $fieldWorkNotifications;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        
        try {
            $this->database = new Database();
            $this->eventLoggingService = new EventLoggingService($logger);
            $this->taskAuth = new TaskAuthorizationService();
            $this->fieldWorkNotifications = new FieldWorkNotificationService($logger);
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize TaskController', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Plain string for DB from request body. Prefer `address`; legacy clients may send `wbs_path`.
     */
    private function normalizeTaskAddressForStorage(array $data): ?string
    {
        $raw = null;
        if (array_key_exists('address', $data)) {
            $raw = $data['address'];
        } elseif (array_key_exists('wbs_path', $data)) {
            $raw = $data['wbs_path'];
        } else {
            return null;
        }
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_string($raw)) {
            $s = trim($raw);

            return $s === '' ? null : $this->truncateTaskAddress($s);
        }
        if (is_array($raw)) {
            $enc = json_encode($raw, JSON_UNESCAPED_UNICODE);

            return ($enc === false || $enc === '') ? null : $this->truncateTaskAddress($enc);
        }

        return null;
    }

    private function truncateTaskAddress(string $s): string
    {
        if (function_exists('mb_strlen') && function_exists('mb_substr') && mb_strlen($s) > self::TASK_ADDRESS_MAX_LEN) {
            return mb_substr($s, 0, self::TASK_ADDRESS_MAX_LEN);
        }
        if (strlen($s) > self::TASK_ADDRESS_MAX_LEN) {
            return substr($s, 0, self::TASK_ADDRESS_MAX_LEN);
        }

        return $s;
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function fieldSubmissionPayloadFromRow(array $task, ?float $projectLat = null, ?float $projectLng = null): array
    {
        $startLat = $this->nullableFloat($task['field_work_start_lat'] ?? null);
        $startLng = $this->nullableFloat($task['field_work_start_lng'] ?? null);
        $endLat = $this->nullableFloat($task['field_work_end_lat'] ?? null);
        $endLng = $this->nullableFloat($task['field_work_end_lng'] ?? null);

        return [
            'field_submitted_at' => $task['field_submitted_at'] ?? null,
            'field_submitted_by' => isset($task['field_submitted_by']) && $task['field_submitted_by'] !== null
                ? (int) $task['field_submitted_by']
                : null,
            'field_work_started_at' => $task['field_work_started_at'] ?? null,
            'field_work_started_by' => isset($task['field_work_started_by']) && $task['field_work_started_by'] !== null
                ? (int) $task['field_work_started_by']
                : null,
            'field_work_start_lat' => $startLat,
            'field_work_start_lng' => $startLng,
            'field_work_start_distance_km' => $this->haversineKm($startLat, $startLng, $projectLat, $projectLng),
            'field_work_ended_at' => $task['field_work_ended_at'] ?? null,
            'field_work_ended_by' => isset($task['field_work_ended_by']) && $task['field_work_ended_by'] !== null
                ? (int) $task['field_work_ended_by']
                : null,
            'field_work_end_lat' => $endLat,
            'field_work_end_lng' => $endLng,
            'field_work_end_distance_km' => $this->haversineKm($endLat, $endLng, $projectLat, $projectLng),
            'field_work_start_reason' => $task['field_work_start_reason'] ?? null,
            'field_work_end_reason' => $task['field_work_end_reason'] ?? null,
            'field_notes' => $task['field_notes'] ?? null,
        ];
    }

    private function nullableFloat(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_numeric($v)) {
            return null;
        }
        $f = (float) $v;

        return is_finite($f) ? $f : null;
    }

    private function haversineKm(?float $lat1, ?float $lng1, ?float $lat2, ?float $lng2): ?float
    {
        if ($lat1 === null || $lng1 === null || $lat2 === null || $lng2 === null) {
            return null;
        }
        $earthKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthKm * $c, 2);
    }

    /**
     * @return array{0: ?float, 1: ?float}
     */
    private function resolveProjectCoordinates(Connection $connection, int $projectId): array
    {
        if (!$this->columnExistsOnTable($connection, 'fw_projects', 'latitude')
            || !$this->columnExistsOnTable($connection, 'fw_projects', 'longitude')
        ) {
            return [null, null];
        }
        $row = $connection->executeQuery(
            'SELECT latitude, longitude FROM fw_projects WHERE id = ? LIMIT 1',
            [$projectId]
        )->fetchAssociative();
        if (!$row) {
            return [null, null];
        }

        return [
            $this->nullableFloat($row['latitude'] ?? null),
            $this->nullableFloat($row['longitude'] ?? null),
        ];
    }

    private function columnExistsOnTable(Connection $connection, string $table, string $column): bool
    {
        $row = $connection->executeQuery(
            'SELECT COUNT(*) AS c FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        )->fetchAssociative();

        return (int) ($row['c'] ?? 0) > 0;
    }

    /** @return array<string, bool> */
    private function taskOptionalColumnMap(Connection $connection): array
    {
        if (self::$taskOptionalColumnExists !== null) {
            return self::$taskOptionalColumnExists;
        }
        $map = [];
        foreach (self::TASK_DETAIL_OPTIONAL_COLUMNS as $column) {
            $map[$column] = $this->columnExistsOnTable($connection, 'fw_prj_tasks', $column);
        }
        self::$taskOptionalColumnExists = $map;

        return $map;
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, mixed> $beforeData
     */
    private function logFieldWorkInitiatorEvent(
        Connection $connection,
        int $projectId,
        int $taskId,
        array $task,
        array $beforeData,
        int $actorId,
        string $eventType,
        string $phase,
    ): void {
        $accountableForemanId = $this->taskAuth->resolveAccountableForemanUserId($connection, $projectId, $taskId);
        $onBehalfOfForeman = $accountableForemanId !== null && $actorId !== $accountableForemanId;
        $timeKey = $phase === 'ended' ? 'field_work_ended_at' : 'field_work_started_at';
        $byKey = $phase === 'ended' ? 'field_work_ended_by' : 'field_work_started_by';

        $this->eventLoggingService->logEvent(
            'task',
            $taskId,
            $eventType,
            $this->fieldSubmissionPayloadFromRow($beforeData),
            array_merge(
                $this->fieldSubmissionPayloadFromRow($task),
                [
                    'task_id' => $taskId,
                    'project_id' => $projectId,
                    'task_name' => $task['name'] ?? null,
                    'project_foreman_id' => $this->taskAuth->resolveProjectForemanUserId($connection, $projectId),
                    'task_lead_id' => $this->taskAuth->resolveTaskLeadUserId($connection, $taskId),
                    'accountable_foreman_id' => $accountableForemanId,
                    'initiated_by_user_id' => $actorId,
                    $timeKey => $task[$timeKey] ?? null,
                    $byKey => isset($task[$byKey]) ? (int) $task[$byKey] : $actorId,
                    'on_behalf_of_foreman' => $onBehalfOfForeman,
                ]
            ),
            [$timeKey, $byKey],
            [
                'actor_type' => 'user',
                'actor_id' => $actorId,
                'comment' => $onBehalfOfForeman
                    ? "Field work {$phase} recorded on behalf of accountable foreman"
                    : "Field work {$phase} recorded by accountable foreman",
                'ip' => Flight::request()->ip ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]
        );
    }

    private function resolveTaskDetailSelect(Connection $connection): string
    {
        if (self::$resolvedTaskDetailSelect !== null) {
            return self::$resolvedTaskDetailSelect;
        }
        $columns = self::TASK_DETAIL_BASE_COLUMNS;
        foreach (self::TASK_DETAIL_OPTIONAL_COLUMNS as $column) {
            if ($this->taskOptionalColumnMap($connection)[$column]) {
                $columns[] = $column;
            }
        }
        self::$resolvedTaskDetailSelect = implode(', ', $columns);

        return self::$resolvedTaskDetailSelect;
    }

    private function taskOptionalColumnPresent(Connection $connection, string $column): bool
    {
        return $this->taskOptionalColumnMap($connection)[$column] ?? false;
    }

    /**
     * API response value: string or null. Supports legacy rows stored as JSON-encoded strings.
     */
    private function formatTaskAddressForResponse(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }
        $decoded = json_decode($trimmed, true);
        if (is_string($decoded)) {
            return $decoded === '' ? null : $decoded;
        }
        if (is_array($decoded)) {
            $enc = json_encode($decoded, JSON_UNESCAPED_UNICODE);

            return ($enc === false || $enc === '') ? null : $enc;
        }

        return $trimmed;
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
     *         description="Search by task name, notes, or address",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="user_id",
     *         in="query",
     *         description="Filter tasks assigned to this user (task lead, member, invited with user_id)",
     *         required=false,
     *         @OA\Schema(type="integer", example=47)
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
     *                     @OA\Property(property="task_order", type="integer", example=1),
     *                     @OA\Property(property="project_id", type="integer", example=1),
     *                     @OA\Property(property="address", type="string", nullable=true, example="123 Main St", description="Site / object address"),
     *                     @OA\Property(property="name", type="string", example="Design Phase"),
     *                     @OA\Property(property="start_planned", type="string", format="date", example="2025-01-01"),
     *                     @OA\Property(property="end_planned", type="string", format="date", example="2025-01-15"),
     *                     @OA\Property(property="start_time", type="string", format="time", example="08:00:00", nullable=true, description="Planned start time"),
     *                     @OA\Property(property="end_time", type="string", format="time", example="17:00:00", nullable=true, description="Planned end time"),
     *                     @OA\Property(property="milestone", type="boolean", example=false),
     *                     @OA\Property(property="status", type="string", example="planned"),
     *                     @OA\Property(property="progress_pct", type="integer", example=0),
     *                     @OA\Property(property="notes", type="string", example="Initial design phase"),
     *                     @OA\Property(property="task_lead_id", type="integer", example=47),
     *                     @OA\Property(property="team_members", type="array", @OA\Items(type="integer"), example={23, 45, 67}),
     *                     @OA\Property(property="resources", type="array", @OA\Items(type="string")),
     *                     @OA\Property(property="baseline_start", type="string", format="date", nullable=true),
     *                     @OA\Property(property="baseline_end", type="string", format="date", nullable=true),
     *                     @OA\Property(property="actual_start", type="string", format="date", nullable=true),
     *                     @OA\Property(property="actual_end", type="string", format="date", nullable=true),
     *                     @OA\Property(property="slack_days", type="integer", nullable=true),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time")
     *                 )),
     *                 @OA\Property(property="dependencies", type="array", @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="project_id", type="integer", example=1),
     *                     @OA\Property(property="from_task_id", type="integer", example=1),
     *                     @OA\Property(property="to_task_id", type="integer", example=2),
     *                     @OA\Property(property="dependency_type", type="string", example="FS"),
     *                     @OA\Property(property="lag_days", type="integer", example=0),
     *                     @OA\Property(property="priority", type="integer", example=1),
     *                     @OA\Property(property="created_by", type="integer", example=47),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time")
     *                 ))
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
        try {
            $request = Flight::request();
            $status = $request->query['status'] ?? null;
            $milestone = $request->query['milestone'] ?? null;
            $search = $request->query['search'] ?? null;
            $userId = $request->query['user_id'] ?? null;

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

            // Base WHERE clause (shared by COUNT and SELECT)
            $whereSql = ' WHERE project_id = ?';
            $params = [$projectId];

            if ($status) {
                $whereSql .= ' AND status = ?';
                $params[] = $status;
            }

            if ($milestone !== null && $milestone !== '') {
                $milestoneValue = filter_var($milestone, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($milestoneValue === true || $milestone === 'true' || $milestone === true) {
                    $whereSql .= ' AND milestone IS NOT NULL';
                } elseif ($milestoneValue === false || $milestone === 'false' || $milestone === false) {
                    $whereSql .= ' AND milestone IS NULL';
                } else {
                    $validMilestones = ['inspection', 'visit', 'meeting', 'review', 'delivery', 'approval', 'other'];
                    if (in_array($milestone, $validMilestones, true)) {
                        $whereSql .= ' AND milestone = ?';
                        $params[] = $milestone;
                    }
                }
            }

            if ($search) {
                $term = '%' . $search . '%';
                $whereSql .= ' AND (name LIKE ? OR notes LIKE ? OR address LIKE ?)';
                $params[] = $term;
                $params[] = $term;
                $params[] = $term;
            }

            if ($userId !== null && $userId !== '' && is_numeric($userId)) {
                $whereSql .= ' AND EXISTS (
                    SELECT 1
                    FROM fw_prj_team_members tm
                    WHERE tm.project_id = ?
                      AND tm.task_id = fw_prj_tasks.id
                      AND tm.user_id = ?
                )';
                $params[] = $projectId;
                $params[] = (int)$userId;
            }

            $countResult = $connection->executeQuery(
                'SELECT COUNT(*) FROM fw_prj_tasks' . $whereSql,
                $params
            );
            $totalTasks = (int)$countResult->fetchOne();

            $page = max(1, (int)($request->query['page'] ?? 1));
            $limitParam = $request->query['limit'] ?? null;
            $limit = null;
            if ($limitParam !== null && $limitParam !== '') {
                $limit = min(max((int)$limitParam, 1), 2000);
            }

            $sql = 'SELECT id, task_order, project_id, address, name, start_planned, end_planned, start_time, end_time, milestone, status, progress_pct, notes, resources, baseline_start, baseline_end, actual_start, actual_end, slack_days, created_at, updated_at FROM fw_prj_tasks'
                . $whereSql
                . ' ORDER BY task_order ASC, start_planned ASC';

            if ($limit !== null) {
                $offset = ($page - 1) * $limit;
                $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
            } else {
                $page = 1;
            }

            $result = $connection->executeQuery($sql, $params);
            $tasks = $result->fetchAllAssociative();

            $effectivePerPage = $limit ?? $totalTasks;
            $lastPage = $limit !== null ? max(1, (int)ceil($totalTasks / $limit)) : 1;

            // Получаем зависимости из отдельной таблицы
            $dependenciesResult = $connection->executeQuery(
                "SELECT id, project_id, from_task_id, to_task_id, dependency_type, lag_days, priority, created_by, created_at, updated_at FROM fw_prj_task_dependencies WHERE project_id = ?",
                [$projectId]
            );
            $dependencies = $dependenciesResult->fetchAllAssociative();

            // Форматируем зависимости
            $formattedDependencies = array_map(function($dep) {
                return [
                    'id' => (int)$dep['id'],
                    'project_id' => (int)$dep['project_id'],
                    'from_task_id' => (int)$dep['from_task_id'],
                    'to_task_id' => (int)$dep['to_task_id'],
                    'dependency_type' => $dep['dependency_type'],
                    'lag_days' => (int)$dep['lag_days'],
                    'priority' => (int)$dep['priority'],
                    'created_by' => (int)$dep['created_by'],
                    'created_at' => $dep['created_at'],
                    'updated_at' => $dep['updated_at']
                ];
            }, $dependencies);

            // Получаем team_members и task_lead_id для задач из fw_prj_team_members (где task_id заполнен)
            $taskIds = array_column($tasks, 'id');
            $taskAssignees = [];
            $taskLeads = [];
            $taskInvitedPeople = []; // Инициализируем всегда
            
            if (!empty($taskIds)) {
                $taskMilestones = [];

                foreach (array_chunk($taskIds, 200) as $taskIdChunk) {
                    $placeholders = str_repeat('?,', count($taskIdChunk) - 1) . '?';

                    $milestoneSql = "SELECT id, milestone FROM fw_prj_tasks WHERE id IN ($placeholders)";
                    $milestoneResult = $connection->executeQuery($milestoneSql, $taskIdChunk);
                    $milestoneData = $milestoneResult->fetchAllAssociative();
                    foreach ($milestoneData as $milestoneRow) {
                        $taskMilestones[(int)$milestoneRow['id']] = $milestoneRow['milestone'] !== null && $milestoneRow['milestone'] !== '';
                    }

                    $assigneesSql = "SELECT task_id, user_id, role_in_project, invited_people FROM fw_prj_team_members WHERE task_id IN ($placeholders)";
                    $assigneesResult = $connection->executeQuery($assigneesSql, $taskIdChunk);
                    $assigneesData = $assigneesResult->fetchAllAssociative();

                    foreach ($assigneesData as $assignee) {
                        $taskId = (int)$assignee['task_id'];
                        $role = $assignee['role_in_project'] ?? null;
                        $isMilestone = isset($taskMilestones[$taskId]) && $taskMilestones[$taskId];

                        if ($isMilestone && $role === 'task_lead') {
                            $invitedPeopleRaw = $assignee['invited_people'] ?? null;
                            if ($invitedPeopleRaw !== null && $invitedPeopleRaw !== '') {
                                $invitedPeopleArray = json_decode($invitedPeopleRaw, true);
                                if (is_array($invitedPeopleArray)) {
                                    $taskInvitedPeople[$taskId] = $invitedPeopleArray;
                                } else {
                                    $taskInvitedPeople[$taskId] = [];
                                }
                            } else {
                                $taskInvitedPeople[$taskId] = [];
                            }
                            if ($assignee['user_id']) {
                                $taskLeads[$taskId] = (int)$assignee['user_id'];
                            }
                        } elseif (!$isMilestone && $assignee['user_id']) {
                            $assigneeUserId = (int)$assignee['user_id'];
                            if ($this->taskAuth->isTaskLeadProjectRole($role)) {
                                $taskLeads[$taskId] = $assigneeUserId;
                            } else {
                                if (!isset($taskAssignees[$taskId])) {
                                    $taskAssignees[$taskId] = [];
                                }
                                $taskAssignees[$taskId][] = $assigneeUserId;
                            }
                        }
                    }
                }
            }

            // Форматируем данные задач
            $formattedTasks = array_map(function($task) use ($taskAssignees, $taskLeads, $taskInvitedPeople) {
                $taskId = (int)$task['id'];
                $teamMembers = isset($taskAssignees[$taskId]) ? $taskAssignees[$taskId] : null;
                $taskLeadId = isset($taskLeads[$taskId]) ? $taskLeads[$taskId] : null;
                $isMilestone = $task['milestone'] !== null && $task['milestone'] !== '';
                // Для milestone всегда возвращаем массив (даже пустой), для обычной задачи - null
                $invitedPeople = $isMilestone 
                    ? (isset($taskInvitedPeople[$taskId]) && is_array($taskInvitedPeople[$taskId]) ? $taskInvitedPeople[$taskId] : [])
                    : null;
                
                return [
                    'id' => (int)$task['id'],
                    'task_order' => (int)$task['task_order'],
                    'project_id' => (int)$task['project_id'],
                    'address' => $this->formatTaskAddressForResponse(isset($task['address']) ? (string) $task['address'] : null),
                    'name' => $task['name'],
                    'start_planned' => $task['start_planned'],
                    'end_planned' => $task['end_planned'],
                    'start_time' => $task['start_time'] ?? null,
                    'end_time' => $task['end_time'] ?? null,
                    'milestone' => $task['milestone'] ?? null, // ENUM: 'inspection','visit','meeting','review','delivery','approval','other' или NULL
                    'status' => $task['status'],
                    'progress_pct' => (int)$task['progress_pct'],
                    'notes' => $task['notes'],
                    'task_lead_id' => $taskLeadId,
                    'team_members' => $teamMembers,
                    'invited_people' => $invitedPeople,
                    'resources' => $task['resources'] ? json_decode($task['resources'], true) : null,
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
                    'tasks' => $formattedTasks,
                    'dependencies' => $formattedDependencies,
                    'pagination' => [
                        'current_page' => $page,
                        'per_page' => $effectivePerPage,
                        'total' => $totalTasks,
                        'last_page' => $lastPage,
                    ],
                ]
            ]);

        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            $errorTrace = $e->getTraceAsString();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();
            
            $this->logger->error('Failed to retrieve tasks', [
                'project_id' => $projectId,
                'error' => $errorMessage,
                'trace' => $errorTrace,
                'file' => $errorFile,
                'line' => $errorLine
            ]);

            // В режиме разработки возвращаем детальную ошибку
            $appEnv = $_ENV['APP_ENV'] ?? 'development';
            $responseData = [
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve tasks',
                'data' => null
            ];
            
            if ($appEnv !== 'production') {
                $responseData['debug'] = [
                    'error' => $errorMessage,
                    'file' => $errorFile,
                    'line' => $errorLine
                ];
            }

            Flight::json($responseData, 500);
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
        try {
            $connection = $this->database->getConnection();
            
            $sql = 'SELECT ' . $this->resolveTaskDetailSelect($connection) . ' FROM fw_prj_tasks WHERE id = ? AND project_id = ?';
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

            // Получаем task_lead_id, team_members и invited_people из fw_prj_team_members для этой задачи
            $assigneesResult = $connection->executeQuery(
                "SELECT user_id, role_in_project, invited_people FROM fw_prj_team_members WHERE task_id = ?",
                [$taskId]
            );
            $assigneesData = $assigneesResult->fetchAllAssociative();
            
            $this->logger->info('Reading team members for task', [
                'task_id' => $taskId,
                'assignees_count' => count($assigneesData),
                'assignees_data' => $assigneesData
            ]);
            
            $taskLeadId = null;
            $teamMembers = [];
            $invitedPeople = null;
            
            // Проверяем, является ли задача milestone
            $isMilestone = $task['milestone'] !== null && $task['milestone'] !== '';
            
            $this->logger->info('Task milestone check', [
                'task_id' => $taskId,
                'is_milestone' => $isMilestone,
                'milestone_value' => $task['milestone'] ?? null
            ]);
            
            if ($isMilestone) {
                // Для milestone: ищем ОДНУ запись с role_in_project = 'task_lead' и invited_people
                foreach ($assigneesData as $assignee) {
                    $this->logger->info('Checking assignee for milestone', [
                        'task_id' => $taskId,
                        'role' => $assignee['role_in_project'] ?? null,
                        'invited_people_raw' => $assignee['invited_people'] ?? null
                    ]);
                    
                    if ($assignee['role_in_project'] === 'task_lead') {
                        $taskLeadId = $assignee['user_id'] ? (int)$assignee['user_id'] : null;
                        // invited_people - это JSON массив (может быть пустой массив "[]")
                        $invitedPeopleRaw = $assignee['invited_people'] ?? null;
                        
                        $this->logger->info('Found task_lead record', [
                            'task_id' => $taskId,
                            'task_lead_id' => $taskLeadId,
                            'invited_people_raw' => $invitedPeopleRaw,
                            'invited_people_type' => gettype($invitedPeopleRaw),
                            'invited_people_empty' => empty($invitedPeopleRaw)
                        ]);
                        
                        if ($invitedPeopleRaw !== null && $invitedPeopleRaw !== '') {
                            $decoded = json_decode($invitedPeopleRaw, true);
                            $jsonError = json_last_error();
                            
                            $this->logger->info('Decoding invited_people JSON', [
                                'task_id' => $taskId,
                                'raw' => $invitedPeopleRaw,
                                'decoded' => $decoded,
                                'json_error' => $jsonError,
                                'is_array' => is_array($decoded)
                            ]);
                            
                            $invitedPeople = is_array($decoded) ? $decoded : [];
                        } else {
                            $invitedPeople = [];
                        }
                        
                        $this->logger->info('Final invited_people for milestone', [
                            'task_id' => $taskId,
                            'invited_people' => $invitedPeople,
                            'count' => is_array($invitedPeople) ? count($invitedPeople) : 0
                        ]);
                        
                        break; // Только одна запись для milestone
                    }
                }
                
                if ($invitedPeople === null) {
                    $this->logger->warning('No task_lead record found for milestone', [
                        'task_id' => $taskId,
                        'assignees_data' => $assigneesData
                    ]);
                    $invitedPeople = [];
                }
            } else {
                // Для обычной задачи: отдельные записи для каждого члена бригады
                foreach ($assigneesData as $assignee) {
                    $userId = (int)$assignee['user_id'];
                    $role = $assignee['role_in_project'] ?? null;
                    if ($this->taskAuth->isTaskLeadProjectRole($role)) {
                        $taskLeadId = $userId;
                    } else {
                        $teamMembers[] = $userId;
                    }
                }
            }

            // Убеждаемся, что invited_people всегда массив для milestone
            if ($isMilestone && $invitedPeople === null) {
                $invitedPeople = [];
            }
            
            $this->logger->info('Final task data before response', [
                'task_id' => $taskId,
                'is_milestone' => $isMilestone,
                'invited_people' => $invitedPeople,
                'invited_people_type' => gettype($invitedPeople),
                'invited_people_count' => is_array($invitedPeople) ? count($invitedPeople) : 'not array'
            ]);
            
            [$projectLat, $projectLng] = $this->resolveProjectCoordinates($connection, $projectId);

            $formattedTask = [
                'id' => (int)$task['id'],
                'task_order' => (int)$task['task_order'],
                'project_id' => (int)$task['project_id'],
                'address' => $this->formatTaskAddressForResponse(isset($task['address']) ? (string) $task['address'] : null),
                'name' => $task['name'],
                'start_planned' => $task['start_planned'],
                'end_planned' => $task['end_planned'],
                'start_time' => $task['start_time'] ?? null,
                'end_time' => $task['end_time'] ?? null,
                'milestone' => $task['milestone'] ?? null, // ENUM: 'inspection','visit','meeting','review','delivery','approval','other' или NULL
                'status' => $task['status'],
                'progress_pct' => (int)$task['progress_pct'],
                'notes' => $task['notes'],
                'task_lead_id' => $taskLeadId,
                'team_members' => !empty($teamMembers) ? $teamMembers : null,
                'invited_people' => $isMilestone ? ($invitedPeople ?? []) : null,
                'resources' => $task['resources'] ? json_decode($task['resources'], true) : null,
                'baseline_start' => $task['baseline_start'],
                'baseline_end' => $task['baseline_end'],
                'actual_start' => $task['actual_start'],
                'actual_end' => $task['actual_end'],
                'slack_days' => $task['slack_days'] ? (int)$task['slack_days'] : null,
                'created_at' => $task['created_at'],
                'updated_at' => $task['updated_at'],
                ...$this->fieldSubmissionPayloadFromRow($task, $projectLat, $projectLng),
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
     *             @OA\Property(property="address", type="string", nullable=true, example="123 Main St", description="Site / object address (legacy body key: wbs_path)"),
     *             @OA\Property(property="name", type="string", example="Foundation Work", description="Task name (required)"),
     *             @OA\Property(property="start_planned", type="string", format="date", example="2025-09-10", description="Planned start date (required)"),
     *             @OA\Property(property="end_planned", type="string", format="date", example="2025-09-12", description="Planned end date"),
     *             @OA\Property(property="start_time", type="string", format="time", example="08:00:00", description="Planned start time (default: 08:00:00)"),
     *             @OA\Property(property="end_time", type="string", format="time", example="17:00:00", description="Planned end time (default: 17:00:00)"),
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
            
            // Получаем следующий порядковый номер для проекта
            $nextOrderResult = $connection->executeQuery(
                "SELECT COALESCE(MAX(task_order), 0) + 1 as next_order FROM fw_prj_tasks WHERE project_id = ?",
                [$projectId]
            );
            $nextOrder = (int)$nextOrderResult->fetchOne();
            
            $sql = "INSERT INTO fw_prj_tasks (task_order, project_id, address, name, start_planned, end_planned, start_time, end_time, milestone, status, progress_pct, notes, resources, baseline_start, baseline_end, actual_start, actual_end, slack_days) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            // task_order, project_id, address, name, ...

            $address = $this->normalizeTaskAddressForStorage($data);

            // Обработка team_members - все пользователи должны быть прикреплены к задачам
            // Не проверяем наличие в команде проекта, так как все должны быть назначены на задачи
            $teamMembers = [];
            if (isset($data['team_members']) && is_array($data['team_members']) && !empty($data['team_members'])) {
                // Фильтруем только числовые значения
                $teamMemberIds = array_filter($data['team_members'], 'is_numeric');
                $teamMemberIds = array_map('intval', $teamMemberIds);
                $teamMembers = $teamMemberIds;
            }
            
            // Обработка task_lead_id - все пользователи должны быть прикреплены к задачам
            // Не проверяем наличие в команде проекта, так как все должны быть назначены на задачи
            $taskLeadId = isset($data['task_lead_id']) && $data['task_lead_id'] ? (int)$data['task_lead_id'] : null;
            if ($taskLeadId === null) {
                $taskLeadId = $this->taskAuth->resolveProjectForemanUserId($connection, $projectId);
            }

            $params = [
                $nextOrder,
                $projectId,
                $address,
                $data['name'],
                $data['start_planned'],
                $data['end_planned'] ?? null,
                $data['start_time'] ?? '08:00:00',
                $data['end_time'] ?? '17:00:00',
                $data['milestone'] ?? null, // ENUM: 'inspection','visit','meeting','review','delivery','approval','other' или NULL
                $data['status'] ?? 'planned',
                $data['progress_pct'] ?? 0,
                $data['notes'] ?? null,
                isset($data['resources']) && is_array($data['resources']) && !empty($data['resources']) ? json_encode($data['resources']) : null,
                $data['baseline_start'] ?? null,
                $data['baseline_end'] ?? null,
                $data['actual_start'] ?? null,
                $data['actual_end'] ?? null,
                $data['slack_days'] ?? null
            ];

            $connection->executeStatement($sql, $params);
            $taskId = $connection->lastInsertId();

            if (!$taskId || $taskId === 0) {
                throw new \Exception("Failed to create task: lastInsertId returned 0");
            }

            // Определяем, является ли задача milestone
            $isMilestone = isset($data['milestone']) && $data['milestone'] !== null && $data['milestone'] !== '';
            
            if ($isMilestone) {
                // Для milestone: ОДНА строка с task_lead и JSON массивом invited_people
                
                // Подготавливаем JSON массив для invited_people
                $invitedPeopleArray = [];
                if (array_key_exists('invited_people', $data) && is_array($data['invited_people'])) {
                    $this->logger->info('Processing invited_people for milestone', [
                        'task_id' => $taskId,
                        'invited_people_count' => count($data['invited_people']),
                        'invited_people' => $data['invited_people']
                    ]);
                    
                    foreach ($data['invited_people'] as $invitedPerson) {
                        // Валидация обязательных полей
                        if (!isset($invitedPerson['name']) || empty(trim($invitedPerson['name']))) {
                            $this->logger->warning('Skipping invited person without name', [
                                'task_id' => $taskId,
                                'invited_person' => $invitedPerson
                            ]);
                            continue;
                        }
                        
                        // Подготавливаем данные для invited_people
                        $invitedPersonData = [];
                        
                        // Добавляем name (обязательное поле)
                        if (isset($invitedPerson['name']) && !empty(trim($invitedPerson['name']))) {
                            $invitedPersonData['name'] = trim($invitedPerson['name']);
                        }
                        
                        // Добавляем email, если он указан
                        if (isset($invitedPerson['email']) && !empty(trim($invitedPerson['email']))) {
                            $invitedPersonData['email'] = trim($invitedPerson['email']);
                        }
                        if (isset($invitedPerson['company']) && !empty(trim($invitedPerson['company']))) {
                            $invitedPersonData['company'] = trim($invitedPerson['company']);
                        }
                        if (isset($invitedPerson['phone']) && !empty(trim($invitedPerson['phone']))) {
                            $invitedPersonData['phone'] = trim($invitedPerson['phone']);
                        }
                        if (isset($invitedPerson['notes']) && !empty(trim($invitedPerson['notes']))) {
                            $invitedPersonData['notes'] = trim($invitedPerson['notes']);
                        }
                        if (isset($invitedPerson['avatar']) && !empty(trim($invitedPerson['avatar']))) {
                            $invitedPersonData['avatar'] = trim($invitedPerson['avatar']);
                        }
                        
                        // Добавляем в массив, если есть name
                        if (!empty($invitedPersonData) && isset($invitedPersonData['name'])) {
                            $invitedPeopleArray[] = $invitedPersonData;
                            $this->logger->info('Added invited person to array', [
                                'task_id' => $taskId,
                                'person_data' => $invitedPersonData
                            ]);
                        } else {
                            $this->logger->warning('Skipping invited person - no name or empty data', [
                                'task_id' => $taskId,
                                'person_data' => $invitedPersonData,
                                'original_data' => $invitedPerson
                            ]);
                        }
                    }
                    
                    $this->logger->info('Final invited_people array', [
                        'task_id' => $taskId,
                        'count' => count($invitedPeopleArray),
                        'array' => $invitedPeopleArray
                    ]);
                }
                
                // Если invited_people установлен (даже пустой массив), сохраняем как JSON, иначе null
                if (array_key_exists('invited_people', $data) && is_array($data['invited_people'])) {
                    $invitedPeopleJson = json_encode($invitedPeopleArray, JSON_UNESCAPED_UNICODE);
                    $this->logger->info('Saving invited_people JSON', [
                        'task_id' => $taskId,
                        'json' => $invitedPeopleJson
                    ]);
                } else {
                    $invitedPeopleJson = null;
                }
                
                // Создаем или обновляем ОДНУ запись для milestone
                try {
                    $connection->executeStatement(
                        "INSERT INTO fw_prj_team_members (project_id, task_id, user_id, role_in_project, invited_people) VALUES (?, ?, ?, 'task_lead', ?)
                         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), invited_people = VALUES(invited_people)",
                        [$projectId, $taskId, $taskLeadId, $invitedPeopleJson]
                    );
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to create/update milestone team member', [
                        'task_id' => $taskId,
                        'task_lead_id' => $taskLeadId,
                        'error' => $e->getMessage()
                    ]);
                }
            } else {
                // Для обычной задачи: отдельные строки для каждого члена бригады
                
                // Сохраняем task_lead_id с role_in_project = 'task_lead'
                if ($taskLeadId) {
                    try {
                        $connection->executeStatement(
                            "INSERT INTO fw_prj_team_members (project_id, task_id, user_id, role_in_project) VALUES (?, ?, ?, 'task_lead')
                             ON DUPLICATE KEY UPDATE role_in_project = 'task_lead'",
                            [$projectId, $taskId, $taskLeadId]
                        );
                    } catch (\Exception $e) {
                        $this->logger->warning('Failed to assign task lead to task', [
                            'task_id' => $taskId,
                            'user_id' => $taskLeadId,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
                
                // Сохраняем team_members (исполнители) - отдельные строки
                if (!empty($teamMembers)) {
                    foreach ($teamMembers as $userId) {
                        try {
                            $connection->executeStatement(
                                "INSERT INTO fw_prj_team_members (project_id, task_id, user_id, role_in_project) VALUES (?, ?, ?, 'member')
                                 ON DUPLICATE KEY UPDATE role_in_project = IF(role_in_project = 'task_lead', 'task_lead', 'member')",
                                [$projectId, $taskId, $userId]
                            );
                        } catch (\Exception $e) {
                            $this->logger->warning('Failed to assign team member to task', [
                                'task_id' => $taskId,
                                'user_id' => $userId,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                }
            }

            // Получаем созданную задачу
            $result = $connection->executeQuery(
                "SELECT id, task_order, project_id, address, name, start_planned, end_planned, start_time, end_time, milestone, status, progress_pct, notes, resources, baseline_start, baseline_end, actual_start, actual_end, slack_days, created_at, updated_at FROM fw_prj_tasks WHERE id = ?",
                [$taskId]
            );
            $task = $result->fetchAssociative();

            if (!$task) {
                throw new \Exception("Failed to retrieve created task with ID: {$taskId}");
            }
            
            // Получаем task_lead_id, team_members и invited_people из fw_prj_team_members для этой задачи
            $assigneesResult = $connection->executeQuery(
                "SELECT user_id, role_in_project, invited_people FROM fw_prj_team_members WHERE task_id = ?",
                [$taskId]
            );
            $assigneesData = $assigneesResult->fetchAllAssociative();
            
            $taskLeadId = null;
            $teamMembers = [];
            $invitedPeople = null;
            
            // Проверяем, является ли задача milestone
            $isMilestone = $task['milestone'] !== null && $task['milestone'] !== '';
            
            if ($isMilestone) {
                // Для milestone: ищем ОДНУ запись с role_in_project = 'task_lead' и invited_people
                foreach ($assigneesData as $assignee) {
                    if ($assignee['role_in_project'] === 'task_lead') {
                        $taskLeadId = $assignee['user_id'] ? (int)$assignee['user_id'] : null;
                        // invited_people - это JSON массив
                        if ($assignee['invited_people']) {
                            $invitedPeople = json_decode($assignee['invited_people'], true);
                        }
                        break; // Только одна запись для milestone
                    }
                }
            } else {
                // Для обычной задачи: отдельные записи для каждого члена бригады
                foreach ($assigneesData as $assignee) {
                    $role = $assignee['role_in_project'] ?? null;
                    
                    if ($this->taskAuth->isTaskLeadProjectRole($role)) {
                        // Бригадир
                        $taskLeadId = (int)$assignee['user_id'];
                    } elseif ($assignee['user_id']) {
                        // Обычный участник команды
                        $teamMembers[] = (int)$assignee['user_id'];
                    }
                }
            }
            
            $task['task_lead_id'] = $taskLeadId;
            $task['team_members'] = !empty($teamMembers) ? json_encode($teamMembers) : null;
            $task['invited_people'] = $invitedPeople;

            // Логируем событие создания задачи
            try {
                $user = Flight::get('current_user');
                $actorId = $user['id'] ?? null;

                // Убеждаемся, что changed_fields содержит только строки
                $changedFields = array_keys($data);
                $changedFields = array_filter($changedFields, 'is_string');

                $this->eventLoggingService->logSimple(
                    entityType: 'task',
                    entityId: $taskId,
                    eventType: 'TASK_CREATED',
                    afterData: [
                        'id' => (int)$task['id'],
                        'project_id' => (int)$task['project_id'],
                        'name' => $task['name'],
                        'status' => $task['status'],
                        'milestone' => $task['milestone'] ?? null, // ENUM: 'inspection','visit','meeting','review','delivery','approval','other' или NULL
                        'start_planned' => $task['start_planned'],
                        'end_planned' => $task['end_planned'],
                        'start_time' => $task['start_time'] ?? null,
                        'end_time' => $task['end_time'] ?? null,
                        'task_lead_id' => $task['task_lead_id'] ? (int)$task['task_lead_id'] : null,
                        'created_at' => $task['created_at']
                    ],
                    options: [
                        'actor_type' => 'user',
                        'actor_id' => $actorId,
                        'changed_fields' => array_values($changedFields), // Убеждаемся, что это массив
                        'comment' => "Task '{$task['name']}' created in project {$projectId}",
                        'ip' => Flight::request()->ip ?? null,
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                        'severity' => 'important'
                    ]
                );
            } catch (\Exception $e) {
                // Логируем ошибку, но не прерываем создание задачи
                $this->logger->warning('Failed to log task creation event', [
                    'error' => $e->getMessage(),
                    'task_id' => $taskId,
                    'trace' => $e->getTraceAsString(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            }

            // Пересчёт date_start/date_end проекта по задачам
            $this->recalculateProjectDates($projectId);

            [$projectLat, $projectLng] = $this->resolveProjectCoordinates($connection, $projectId);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Task created successfully',
                'data' => [
                    'task' => $this->formatTask($task, $projectLat, $projectLng)
                ]
            ], 201);

        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            $errorTrace = $e->getTraceAsString();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();
            
            $this->logger->error('Failed to create task', [
                'project_id' => $projectId,
                'error' => $errorMessage,
                'trace' => $errorTrace,
                'file' => $errorFile,
                'line' => $errorLine
            ]);

            // В режиме разработки возвращаем детальную ошибку
            $appEnv = $_ENV['APP_ENV'] ?? 'development';
            $responseData = [
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to create task',
                'data' => null
            ];
            
            if ($appEnv !== 'production') {
                $responseData['debug'] = [
                    'error' => $errorMessage,
                    'file' => $errorFile,
                    'line' => $errorLine
                ];
            }

            Flight::json($responseData, 500);
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
     *             @OA\Property(property="address", type="string", nullable=true, example="456 Oak Ave", description="Site / object address (legacy body key: wbs_path)"),
     *             @OA\Property(property="name", type="string", example="Updated Foundation Work", description="Task name"),
     *             @OA\Property(property="start_planned", type="string", format="date", example="2025-09-11", description="Planned start date"),
     *             @OA\Property(property="end_planned", type="string", format="date", example="2025-09-13", description="Planned end date"),
     *             @OA\Property(property="start_time", type="string", format="time", example="08:00:00", description="Planned start time"),
     *             @OA\Property(property="end_time", type="string", format="time", example="17:00:00", description="Planned end time"),
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

            $user = Flight::get('current_user');
            $actorId = isset($user['id']) ? (int) $user['id'] : 0;
            if ($actorId <= 0) {
                Flight::json([
                    'error_code' => 401,
                    'status' => 'error',
                    'message' => 'Unauthorized',
                    'data' => null,
                ], 401);
                return;
            }

            $authResult = $this->taskAuth->authorizeTaskUpdate(
                $connection,
                $actorId,
                $projectId,
                $taskId,
                is_array($data) ? $data : [],
            );
            if (!$authResult['allowed']) {
                Flight::json([
                    'error_code' => 403,
                    'status' => 'error',
                    'message' => $authResult['message'] ?? 'Forbidden',
                    'data' => null,
                ], 403);
                return;
            }
            $data = $authResult['filtered'] ?? $data;

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

            // Получаем текущие данные задачи перед обновлением для логирования
            $beforeResult = $connection->executeQuery(
                'SELECT ' . $this->resolveTaskDetailSelect($connection) . ' FROM fw_prj_tasks WHERE id = ? AND project_id = ?',
                [$taskId, $projectId]
            );
            $beforeData = $beforeResult->fetchAssociative();
            
            // Получаем task_lead_id, team_members и invited_people из fw_prj_team_members для beforeData
            $beforeAssigneesResult = $connection->executeQuery(
                "SELECT user_id, role_in_project, invited_people FROM fw_prj_team_members WHERE task_id = ?",
                [$taskId]
            );
            $beforeAssigneesData = $beforeAssigneesResult->fetchAllAssociative();
            
            $beforeTaskLeadId = null;
            $beforeTeamMembers = [];
            $beforeInvitedPeople = null;
            foreach ($beforeAssigneesData as $assignee) {
                $userId = (int)$assignee['user_id'];
                $role = $assignee['role_in_project'] ?? null;
                $invitedPeople = $assignee['invited_people'] ?? null;
                
                // Сохраняем invited_people для milestone (role = 'task_lead' для milestone)
                if ($role === 'task_lead' && $invitedPeople !== null) {
                    $beforeInvitedPeople = $invitedPeople;
                }
                
                if ($this->taskAuth->isTaskLeadProjectRole($role)) {
                    $beforeTaskLeadId = $userId;
                } else {
                    $beforeTeamMembers[] = $userId;
                }
            }
            $beforeData['task_lead_id'] = $beforeTaskLeadId;
            $beforeData['team_members'] = !empty($beforeTeamMembers) ? json_encode($beforeTeamMembers) : null;
            $beforeData['invited_people'] = $beforeInvitedPeople;

            // Строим SQL запрос для обновления
            $updateFields = [];
            $params = [];

            if (array_key_exists('address', $data) || array_key_exists('wbs_path', $data)) {
                $updateFields[] = 'address = ?';
                $params[] = $this->normalizeTaskAddressForStorage($data);
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
            if (isset($data['start_time'])) {
                $updateFields[] = "start_time = ?";
                $params[] = $data['start_time'];
            }
            if (isset($data['end_time'])) {
                $updateFields[] = "end_time = ?";
                $params[] = $data['end_time'];
            }
            if (isset($data['milestone'])) {
                $updateFields[] = "milestone = ?";
                // ENUM: 'inspection','visit','meeting','review','delivery','approval','other' или NULL
                $validMilestones = ['inspection', 'visit', 'meeting', 'review', 'delivery', 'approval', 'other'];
                $milestoneValue = ($data['milestone'] === null || $data['milestone'] === '') 
                    ? null 
                    : (in_array($data['milestone'], $validMilestones, true) ? $data['milestone'] : null);
                $params[] = $milestoneValue;
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
            // Обработка task_lead_id и team_members - НЕ добавляем в команду проекта отдельно
            // Пользователь будет добавлен в команду проекта автоматически при назначении на задачу
            // Это предотвращает дублирование записей с task_id = NULL
            // Если task_lead_id не передан, используем старое значение из beforeData
            $taskLeadId = null;
            if (array_key_exists('task_lead_id', $data)) {
                $taskLeadId = $data['task_lead_id'] ? (int)$data['task_lead_id'] : null;
            } else {
                // Используем старое значение, если новое не передано
                $taskLeadId = $beforeTaskLeadId;
            }
            $teamMembers = [];
            $hasTeamMembersInRequest = array_key_exists('team_members', $data);
            
            if ($hasTeamMembersInRequest) {
                if (is_array($data['team_members'])) {
                    $teamMemberIds = array_filter($data['team_members'], 'is_numeric');
                    $teamMembers = array_values(array_map('intval', $teamMemberIds));
                }
            }
            if (isset($data['resources'])) {
                $updateFields[] = "resources = ?";
                $params[] = $data['resources'] ? json_encode($data['resources']) : null;
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
            if (array_key_exists('field_work_started_at', $data)) {
                if ($this->taskOptionalColumnPresent($connection, 'field_work_started_at')) {
                    $updateFields[] = 'field_work_started_at = ?';
                    $params[] = $this->normalizeNullableDateTime($data['field_work_started_at']);
                    if (
                        $data['field_work_started_at'] !== null
                        && $this->taskOptionalColumnPresent($connection, 'field_work_started_by')
                    ) {
                        $updateFields[] = 'field_work_started_by = ?';
                        $params[] = $actorId;
                    }
                }
            }
            if (array_key_exists('field_work_ended_at', $data)) {
                if ($this->taskOptionalColumnPresent($connection, 'field_work_ended_at')) {
                    $updateFields[] = 'field_work_ended_at = ?';
                    $params[] = $this->normalizeNullableDateTime($data['field_work_ended_at']);
                    if (
                        $data['field_work_ended_at'] !== null
                        && $this->taskOptionalColumnPresent($connection, 'field_work_ended_by')
                    ) {
                        $updateFields[] = 'field_work_ended_by = ?';
                        $params[] = $actorId;
                    }
                }
            }
            if (array_key_exists('field_notes', $data)) {
                if ($this->taskOptionalColumnPresent($connection, 'field_notes')) {
                    $updateFields[] = 'field_notes = ?';
                    $params[] = is_string($data['field_notes']) ? $data['field_notes'] : null;
                }
            }
            if (array_key_exists('field_work_start_reason', $data)) {
                if ($this->taskOptionalColumnPresent($connection, 'field_work_start_reason')) {
                    $updateFields[] = 'field_work_start_reason = ?';
                    $params[] = is_string($data['field_work_start_reason'])
                        ? $data['field_work_start_reason']
                        : null;
                }
            }
            if (array_key_exists('field_work_end_reason', $data)) {
                if ($this->taskOptionalColumnPresent($connection, 'field_work_end_reason')) {
                    $updateFields[] = 'field_work_end_reason = ?';
                    $params[] = is_string($data['field_work_end_reason'])
                        ? $data['field_work_end_reason']
                        : null;
                }
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
            
            // Определяем старое значение milestone из beforeData
            $oldIsMilestone = isset($beforeData['milestone']) && $beforeData['milestone'] !== null && $beforeData['milestone'] !== '';
            
            // Определяем новое значение milestone (после обновления)
            $newIsMilestone = isset($data['milestone']) 
                ? ($data['milestone'] !== null && $data['milestone'] !== '')
                : $oldIsMilestone;
            
            // Если milestone изменился, нужно пересоздать записи в fw_prj_team_members
            $milestoneChanged = isset($data['milestone']) && ($oldIsMilestone !== $newIsMilestone);
            
            // Для milestone всегда обновляем team_members, если передаются task_lead_id или invited_people
            // Для обычной задачи обновляем, если передаются task_lead_id или team_members
            $shouldUpdateTeamMembers = false;
            if ($newIsMilestone) {
                // Для milestone обновляем, если передается task_lead_id или invited_people
                // Используем array_key_exists для проверки наличия ключа, даже если значение null или пустой массив
                $hasTaskLeadId = array_key_exists('task_lead_id', $data);
                $hasInvitedPeople = array_key_exists('invited_people', $data);
                $shouldUpdateTeamMembers = $milestoneChanged || $hasTaskLeadId || $hasInvitedPeople;
                
                $this->logger->info('Checking if should update team members for milestone', [
                    'task_id' => $taskId,
                    'milestone_changed' => $milestoneChanged,
                    'has_task_lead_id' => $hasTaskLeadId,
                    'has_invited_people' => $hasInvitedPeople,
                    'should_update' => $shouldUpdateTeamMembers,
                    'data_keys' => array_keys($data)
                ]);
            } else {
                // Для обычной задачи обновляем, если передается task_lead_id или team_members
                $shouldUpdateTeamMembers = $milestoneChanged 
                    || array_key_exists('task_lead_id', $data) 
                    || array_key_exists('team_members', $data);
            }
            
            if ($shouldUpdateTeamMembers) {
                $this->logger->info('Updating team members', [
                    'task_id' => $taskId,
                    'is_milestone' => $newIsMilestone
                ]);
                
                if ($newIsMilestone) {
                    // Для milestone: удаляем все записи, кроме той, что с role_in_project = 'task_lead'
                    // Потом обновим или создадим запись с task_lead
                    $connection->executeStatement(
                        "DELETE FROM fw_prj_team_members WHERE task_id = ? AND project_id = ? AND role_in_project != 'task_lead'",
                        [$taskId, $projectId]
                    );
                    // Для milestone: ОДНА строка с task_lead и JSON массивом invited_people
                    
                    // Подготавливаем JSON массив для invited_people
                    $invitedPeopleArray = [];
                    
                    if (array_key_exists('invited_people', $data) && is_array($data['invited_people'])) {
                        // Если invited_people передан, используем его как полный массив (фронтенд должен отправлять весь список)
                        $this->logger->info('Processing invited_people for milestone update', [
                            'task_id' => $taskId,
                            'invited_people_count' => count($data['invited_people']),
                            'invited_people' => $data['invited_people']
                        ]);
                        
                        // Обрабатываем переданные данные
                        foreach ($data['invited_people'] as $invitedPerson) {
                            // Валидация обязательных полей
                            if (!isset($invitedPerson['name']) || empty(trim($invitedPerson['name']))) {
                                $this->logger->warning('Skipping invited person without name', [
                                    'task_id' => $taskId,
                                    'invited_person' => $invitedPerson
                                ]);
                                continue;
                            }
                            
                            // Подготавливаем данные для invited_people
                            $invitedPersonData = [];
                            
                            // Добавляем name (обязательное поле)
                            if (isset($invitedPerson['name']) && !empty(trim($invitedPerson['name']))) {
                                $invitedPersonData['name'] = trim($invitedPerson['name']);
                            }
                            
                            // Добавляем email, если он указан
                            if (isset($invitedPerson['email']) && !empty(trim($invitedPerson['email']))) {
                                $invitedPersonData['email'] = trim($invitedPerson['email']);
                            }
                            if (isset($invitedPerson['company']) && !empty(trim($invitedPerson['company']))) {
                                $invitedPersonData['company'] = trim($invitedPerson['company']);
                            }
                            if (isset($invitedPerson['phone']) && !empty(trim($invitedPerson['phone']))) {
                                $invitedPersonData['phone'] = trim($invitedPerson['phone']);
                            }
                            if (isset($invitedPerson['notes']) && !empty(trim($invitedPerson['notes']))) {
                                $invitedPersonData['notes'] = trim($invitedPerson['notes']);
                            }
                            if (isset($invitedPerson['avatar']) && !empty(trim($invitedPerson['avatar']))) {
                                $invitedPersonData['avatar'] = trim($invitedPerson['avatar']);
                            }
                            
                            // Добавляем в массив, если есть name
                            if (!empty($invitedPersonData) && isset($invitedPersonData['name'])) {
                                $invitedPeopleArray[] = $invitedPersonData;
                                $this->logger->info('Added invited person to array', [
                                    'task_id' => $taskId,
                                    'person_data' => $invitedPersonData
                                ]);
                            } else {
                                $this->logger->warning('Skipping invited person - no name or empty data', [
                                    'task_id' => $taskId,
                                    'person_data' => $invitedPersonData,
                                    'original_data' => $invitedPerson
                                ]);
                            }
                        }
                        
                        $this->logger->info('Final invited_people array for update', [
                            'task_id' => $taskId,
                            'count' => count($invitedPeopleArray),
                            'array' => $invitedPeopleArray
                        ]);
                        
                        // Сохраняем как JSON (даже если пустой массив)
                        $invitedPeopleJson = json_encode($invitedPeopleArray, JSON_UNESCAPED_UNICODE);
                        $this->logger->info('Saving invited_people JSON for update', [
                            'task_id' => $taskId,
                            'count' => count($invitedPeopleArray),
                            'json' => $invitedPeopleJson
                        ]);
                    } else {
                        // Если invited_people не передан, используем старое значение из базы
                        if (!empty($beforeData['invited_people'])) {
                            $invitedPeopleJson = is_string($beforeData['invited_people']) 
                                ? $beforeData['invited_people'] 
                                : json_encode($beforeData['invited_people'], JSON_UNESCAPED_UNICODE);
                        } else {
                            $invitedPeopleJson = null;
                        }
                        $this->logger->info('Using old invited_people value', [
                            'task_id' => $taskId,
                            'old_value' => $invitedPeopleJson
                        ]);
                    }
                    
                    // Проверяем, существует ли уже запись для этого milestone
                    $existingRecord = $connection->executeQuery(
                        "SELECT id FROM fw_prj_team_members WHERE task_id = ? AND project_id = ? AND role_in_project = 'task_lead'",
                        [$taskId, $projectId]
                    )->fetchAssociative();
                    
                    if ($existingRecord) {
                        // Обновляем существующую запись
                        try {
                            $this->logger->info('Updating existing milestone team member', [
                                'task_id' => $taskId,
                                'record_id' => $existingRecord['id'],
                                'project_id' => $projectId,
                                'task_lead_id' => $taskLeadId,
                                'invited_people_json' => $invitedPeopleJson
                            ]);
                            
                            $connection->executeStatement(
                                "UPDATE fw_prj_team_members 
                                 SET user_id = ?, invited_people = ?, role_in_project = 'task_lead'
                                 WHERE id = ?",
                                [$taskLeadId, $invitedPeopleJson, $existingRecord['id']]
                            );
                            
                            $this->logger->info('Successfully updated milestone team member', [
                                'task_id' => $taskId,
                                'record_id' => $existingRecord['id']
                            ]);
                        } catch (\Exception $e) {
                            $this->logger->error('Failed to update milestone team member', [
                                'task_id' => $taskId,
                                'record_id' => $existingRecord['id'],
                                'project_id' => $projectId,
                                'task_lead_id' => $taskLeadId,
                                'invited_people_json' => $invitedPeopleJson,
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString()
                            ]);
                        }
                    } else {
                        // Создаем новую запись
                        try {
                            $this->logger->info('Creating new milestone team member', [
                                'task_id' => $taskId,
                                'project_id' => $projectId,
                                'task_lead_id' => $taskLeadId,
                                'invited_people_json' => $invitedPeopleJson
                            ]);
                            
                            $connection->executeStatement(
                                "INSERT INTO fw_prj_team_members (project_id, task_id, user_id, role_in_project, invited_people) 
                                 VALUES (?, ?, ?, 'task_lead', ?)",
                                [$projectId, $taskId, $taskLeadId, $invitedPeopleJson]
                            );
                            
                            $this->logger->info('Successfully created milestone team member', [
                                'task_id' => $taskId
                            ]);
                        } catch (\Exception $e) {
                            $this->logger->error('Failed to create milestone team member', [
                                'task_id' => $taskId,
                                'project_id' => $projectId,
                                'task_lead_id' => $taskLeadId,
                                'invited_people_json' => $invitedPeopleJson,
                                'error' => $e->getMessage(),
                                'trace' => $e->getTraceAsString()
                            ]);
                        }
                    }
                } else {
                    // Regular task: replace assignee rows with authoritative set from request
                    if (!$hasTeamMembersInRequest) {
                        $teamMembers = $beforeTeamMembers;
                    }

                    $connection->executeStatement(
                        "DELETE FROM fw_prj_team_members WHERE task_id = ? AND project_id = ?",
                        [$taskId, $projectId]
                    );

                    // task_lead row
                    if ($taskLeadId) {
                        try {
                            $connection->executeStatement(
                                "INSERT INTO fw_prj_team_members (project_id, task_id, user_id, role_in_project) VALUES (?, ?, ?, 'task_lead')",
                                [$projectId, $taskId, $taskLeadId]
                            );
                        } catch (\Exception $e) {
                            $this->logger->warning('Failed to assign task lead to task', [
                                'task_id' => $taskId,
                                'user_id' => $taskLeadId,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                    
                    // team members (executors) — skip duplicate of task_lead
                    if (!empty($teamMembers)) {
                        foreach ($teamMembers as $userId) {
                            if ($taskLeadId && (int) $userId === (int) $taskLeadId) {
                                continue;
                            }
                            try {
                                $connection->executeStatement(
                                    "INSERT INTO fw_prj_team_members (project_id, task_id, user_id, role_in_project) VALUES (?, ?, ?, 'member')",
                                    [$projectId, $taskId, $userId]
                                );
                            } catch (\Exception $e) {
                                $this->logger->warning('Failed to assign team member to task', [
                                    'task_id' => $taskId,
                                    'user_id' => $userId,
                                    'error' => $e->getMessage()
                                ]);
                            }
                        }
                    }
                }
            }

            // Получаем обновленную задачу (включая field-work колонки, если есть в БД)
            $selectSql = 'SELECT ' . $this->resolveTaskDetailSelect($connection) . ' FROM fw_prj_tasks WHERE id = ?';
            $result = $connection->executeQuery($selectSql, [$taskId]);
            $task = $result->fetchAssociative();
            
            // Получаем task_lead_id, team_members и invited_people из fw_prj_team_members для этой задачи
            $assigneesResult = $connection->executeQuery(
                "SELECT user_id, role_in_project, invited_people FROM fw_prj_team_members WHERE task_id = ?",
                [$taskId]
            );
            $assigneesData = $assigneesResult->fetchAllAssociative();
            
            $taskLeadId = null;
            $teamMembers = [];
            $invitedPeople = null;
            
            // Проверяем, является ли задача milestone
            $isMilestone = $task['milestone'] !== null && $task['milestone'] !== '';
            
            if ($isMilestone) {
                // Для milestone: ищем ОДНУ запись с role_in_project = 'task_lead' и invited_people
                foreach ($assigneesData as $assignee) {
                    if ($assignee['role_in_project'] === 'task_lead') {
                        $taskLeadId = $assignee['user_id'] ? (int)$assignee['user_id'] : null;
                        // invited_people - это JSON массив
                        if ($assignee['invited_people']) {
                            $invitedPeople = json_decode($assignee['invited_people'], true);
                        }
                        break; // Только одна запись для milestone
                    }
                }
            } else {
                // Для обычной задачи: отдельные записи для каждого члена бригады
                foreach ($assigneesData as $assignee) {
                    $role = $assignee['role_in_project'] ?? null;
                    
                    if ($this->taskAuth->isTaskLeadProjectRole($role)) {
                        // Бригадир
                        $taskLeadId = (int)$assignee['user_id'];
                    } elseif ($assignee['user_id']) {
                        // Обычный участник команды
                        $teamMembers[] = (int)$assignee['user_id'];
                    }
                }
            }
            
            $task['task_lead_id'] = $taskLeadId;
            $task['team_members'] = !empty($teamMembers) ? json_encode($teamMembers) : null;
            $task['invited_people'] = $invitedPeople;

            // Логируем событие обновления задачи
            try {
                $user = Flight::get('current_user');
                $actorId = $user['id'] ?? null;

                $afterData = [
                    'id' => (int)$task['id'],
                    'project_id' => (int)$task['project_id'],
                    'name' => $task['name'],
                    'status' => $task['status'],
                    'milestone' => $task['milestone'] ?? null, // ENUM: 'inspection','visit','meeting','review','delivery','approval','other' или NULL
                    'start_planned' => $task['start_planned'],
                    'end_planned' => $task['end_planned'],
                    'progress_pct' => (int)$task['progress_pct'],
                    'task_lead_id' => $task['task_lead_id'] ? (int)$task['task_lead_id'] : null,
                    'updated_at' => $task['updated_at']
                ];

                $this->eventLoggingService->logSimple(
                    entityType: 'task',
                    entityId: $taskId,
                    eventType: 'TASK_UPDATED',
                    afterData: $afterData,
                    options: [
                        'actor_type' => 'user',
                        'actor_id' => $actorId,
                        'before_data' => [
                            'id' => (int)$beforeData['id'],
                            'project_id' => (int)$beforeData['project_id'],
                            'name' => $beforeData['name'],
                            'status' => $beforeData['status'],
                            'milestone' => $beforeData['milestone'] ?? null, // ENUM: 'inspection','visit','meeting','review','delivery','approval','other' или NULL
                            'start_planned' => $beforeData['start_planned'],
                            'end_planned' => $beforeData['end_planned'],
                            'start_time' => $beforeData['start_time'] ?? null,
                            'end_time' => $beforeData['end_time'] ?? null,
                            'progress_pct' => (int)$beforeData['progress_pct'],
                            'task_lead_id' => $beforeData['task_lead_id'] ? (int)$beforeData['task_lead_id'] : null
                        ],
                        'changed_fields' => array_keys($data),
                        'comment' => "Task '{$task['name']}' updated",
                        'ip' => Flight::request()->ip ?? null,
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                        'severity' => 'important'
                    ]
                );

                // Если изменился статус, логируем отдельное событие
                if (isset($data['status']) && $beforeData['status'] !== $task['status']) {
                    $this->eventLoggingService->logSimple(
                        entityType: 'task',
                        entityId: $taskId,
                        eventType: 'TASK_STATUS_CHANGED',
                        afterData: [
                            'status' => $task['status'],
                            'previous_status' => $beforeData['status'],
                            'task_id' => $taskId,
                            'task_name' => $task['name'],
                            'project_id' => (int)$task['project_id']
                        ],
                        options: [
                            'actor_type' => 'user',
                            'actor_id' => $actorId,
                            'before_data' => ['status' => $beforeData['status']],
                            'changed_fields' => ['status'],
                            'comment' => "Task status changed from '{$beforeData['status']}' to '{$task['status']}'",
                            'ip' => Flight::request()->ip ?? null,
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                            'severity' => 'important'
                        ]
                    );
                }

                $notifyUrgent = $this->isTruthyRequestFlag($data['notify_urgent'] ?? $data['urgent'] ?? null);

                if (
                    array_key_exists('field_work_started_at', $data)
                    && ($beforeData['field_work_started_at'] ?? null) !== ($task['field_work_started_at'] ?? null)
                ) {
                    $this->logFieldWorkInitiatorEvent(
                        $connection,
                        $projectId,
                        $taskId,
                        $task,
                        $beforeData,
                        $actorId,
                        'TASK_FIELD_WORK_STARTED',
                        'started',
                    );
                    if (($task['field_work_started_at'] ?? null) !== null) {
                        $this->fieldWorkNotifications->notifyManagers(
                            $projectId,
                            $taskId,
                            $task,
                            $actorId,
                            'started',
                            $notifyUrgent,
                        );
                    }
                }

                if (
                    array_key_exists('field_work_ended_at', $data)
                    && ($beforeData['field_work_ended_at'] ?? null) !== ($task['field_work_ended_at'] ?? null)
                ) {
                    $this->logFieldWorkInitiatorEvent(
                        $connection,
                        $projectId,
                        $taskId,
                        $task,
                        $beforeData,
                        $actorId,
                        'TASK_FIELD_WORK_ENDED',
                        'ended',
                    );
                    if (($task['field_work_ended_at'] ?? null) !== null) {
                        $this->fieldWorkNotifications->notifyManagers(
                            $projectId,
                            $taskId,
                            $task,
                            $actorId,
                            'ended',
                            $notifyUrgent,
                        );
                    }
                }

                // Если изменилось расписание (start_planned, end_planned, start_time или end_time), логируем событие
                $scheduleChanged = (isset($data['start_planned']) && $beforeData['start_planned'] !== $task['start_planned']) ||
                    (isset($data['end_planned']) && $beforeData['end_planned'] !== $task['end_planned']) ||
                    (isset($data['start_time']) && ($beforeData['start_time'] ?? null) !== ($task['start_time'] ?? null)) ||
                    (isset($data['end_time']) && ($beforeData['end_time'] ?? null) !== ($task['end_time'] ?? null));
                
                if ($scheduleChanged) {
                    $changedFields = [];
                    if (isset($data['start_planned']) && $beforeData['start_planned'] !== $task['start_planned']) {
                        $changedFields[] = 'start_planned';
                    }
                    if (isset($data['end_planned']) && $beforeData['end_planned'] !== $task['end_planned']) {
                        $changedFields[] = 'end_planned';
                    }
                    if (isset($data['start_time']) && ($beforeData['start_time'] ?? null) !== ($task['start_time'] ?? null)) {
                        $changedFields[] = 'start_time';
                    }
                    if (isset($data['end_time']) && ($beforeData['end_time'] ?? null) !== ($task['end_time'] ?? null)) {
                        $changedFields[] = 'end_time';
                    }
                    
                    $this->eventLoggingService->logSimple(
                        entityType: 'task',
                        entityId: $taskId,
                        eventType: 'TASK_SCHEDULE_CHANGED',
                        afterData: [
                            'start_planned' => $task['start_planned'],
                            'end_planned' => $task['end_planned'],
                            'start_time' => $task['start_time'] ?? null,
                            'end_time' => $task['end_time'] ?? null,
                            'previous_start_planned' => $beforeData['start_planned'],
                            'previous_end_planned' => $beforeData['end_planned'],
                            'previous_start_time' => $beforeData['start_time'] ?? null,
                            'previous_end_time' => $beforeData['end_time'] ?? null,
                            'task_id' => $taskId,
                            'task_name' => $task['name'],
                            'project_id' => (int)$task['project_id']
                        ],
                        options: [
                            'actor_type' => 'user',
                            'actor_id' => $actorId,
                            'before_data' => [
                                'start_planned' => $beforeData['start_planned'],
                                'end_planned' => $beforeData['end_planned'],
                                'start_time' => $beforeData['start_time'] ?? null,
                                'end_time' => $beforeData['end_time'] ?? null
                            ],
                            'changed_fields' => $changedFields,
                            'comment' => "Task schedule updated",
                            'ip' => Flight::request()->ip ?? null,
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                            'severity' => 'important'
                        ]
                    );
                }

                // Если изменились исполнители (team_members), логируем событие
                $beforeTeamMembers = $beforeData['team_members'] ? json_decode($beforeData['team_members'], true) : [];
                $afterTeamMembers = $task['team_members'] ? json_decode($task['team_members'], true) : [];
                if (isset($data['team_members']) && json_encode($beforeTeamMembers) !== json_encode($afterTeamMembers)) {
                    $this->eventLoggingService->logSimple(
                        entityType: 'task',
                        entityId: $taskId,
                        eventType: 'TASK_ASSIGNEES_CHANGED',
                        afterData: [
                            'team_members' => $afterTeamMembers,
                            'previous_team_members' => $beforeTeamMembers,
                            'task_id' => $taskId,
                            'task_name' => $task['name'],
                            'project_id' => (int)$task['project_id']
                        ],
                        options: [
                            'actor_type' => 'user',
                            'actor_id' => $actorId,
                            'before_data' => ['team_members' => $beforeTeamMembers],
                            'changed_fields' => ['team_members'],
                            'comment' => "Task assignees changed",
                            'ip' => Flight::request()->ip ?? null,
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                            'severity' => 'important'
                        ]
                    );
                }
            } catch (\Exception $e) {
                // Логируем ошибку детально, но не прерываем обновление задачи
                $this->logger->error('Failed to log task update event', [
                    'error' => $e->getMessage(),
                    'task_id' => $taskId,
                    'trace' => $e->getTraceAsString(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            }

            // Пересчёт date_start/date_end проекта по задачам
            $this->recalculateProjectDates($projectId);

            [$projectLat, $projectLng] = $this->resolveProjectCoordinates($connection, $projectId);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Task updated successfully',
                'data' => [
                    'task' => $this->formatTask($task, $projectLat, $projectLng)
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
        try {
            $connection = $this->database->getConnection();
            
            // Получаем данные задачи перед удалением для логирования
            $taskResult = $connection->executeQuery(
                "SELECT id, project_id, name, status, start_planned, end_planned, milestone
                 FROM fw_prj_tasks WHERE id = ? AND project_id = ?",
                [$taskId, $projectId]
            );
            $taskData = $taskResult->fetchAssociative();
            
            // Получаем task_lead_id из fw_prj_team_members для логирования
            $leadResult = $connection->executeQuery(
                "SELECT user_id FROM fw_prj_team_members WHERE task_id = ? AND (role_in_project LIKE '%lead%' OR role_in_project LIKE '%supervisor%' OR role_in_project LIKE '%manager%' OR role_in_project LIKE '%foreman%') LIMIT 1",
                [$taskId]
            );
            $taskLeadId = $leadResult->fetchOne();
            $taskData['task_lead_id'] = $taskLeadId ? (int)$taskLeadId : null;
            
            if (!$taskData) {
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

            // Логируем событие удаления задачи
            try {
                $user = Flight::get('current_user');
                $actorId = $user['id'] ?? null;

                $this->eventLoggingService->logSimple(
                    entityType: 'task',
                    entityId: $taskId,
                    eventType: 'TASK_DELETED',
                    afterData: [
                        'id' => (int)$taskData['id'],
                        'project_id' => (int)$taskData['project_id'],
                        'name' => $taskData['name'],
                        'status' => $taskData['status'],
                        'deleted_at' => date('c')
                    ],
                    options: [
                        'actor_type' => 'user',
                        'actor_id' => $actorId,
                        'before_data' => [
                            'id' => (int)$taskData['id'],
                            'project_id' => (int)$taskData['project_id'],
                            'name' => $taskData['name'],
                            'status' => $taskData['status'],
                            'milestone' => $taskData['milestone'] ?? null, // ENUM: 'inspection','visit','meeting','review','delivery','approval','other' или NULL
                            'start_planned' => $taskData['start_planned'],
                            'end_planned' => $taskData['end_planned'],
                            'task_lead_id' => $taskData['task_lead_id'] ? (int)$taskData['task_lead_id'] : null
                        ],
                        'changed_fields' => ['deleted'],
                        'comment' => "Task '{$taskData['name']}' deleted from project {$projectId}",
                        'ip' => Flight::request()->ip ?? null,
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                        'severity' => 'important'
                    ]
                );
            } catch (\Exception $e) {
                $this->logger->warning('Failed to log task deletion event', [
                    'error' => $e->getMessage(),
                    'task_id' => $taskId
                ]);
            }

            // Пересчёт date_start/date_end проекта по оставшимся задачам
            $this->recalculateProjectDates($projectId);

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
     * Получить доступных работников для задачи
     * GET /api/v1/tasks/{taskId}/available-workers
     *
     * @OA\Get(
     *     path="/api/v1/tasks/{taskId}/available-workers",
     *     summary="Get available workers for task",
     *     description="Get list of workers available for assignment to a task in the specified date range",
     *     tags={"Tasks"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="taskId",
     *         in="path",
     *         description="Task ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="start_date",
     *         in="query",
     *         description="Start date of task period (YYYY-MM-DD)",
     *         required=true,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Parameter(
     *         name="end_date",
     *         in="query",
     *         description="End date of task period (YYYY-MM-DD)",
     *         required=true,
     *         @OA\Schema(type="string", format="date")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Available workers retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="workers", type="array",
     *                     @OA\Items(type="object",
     *                         @OA\Property(property="id", type="integer", example=1),
     *                         @OA\Property(property="user_id", type="integer", example=1),
     *                         @OA\Property(property="first_name", type="string", example="John"),
     *                         @OA\Property(property="last_name", type="string", example="Smith"),
     *                         @OA\Property(property="full_name", type="string", example="John Smith"),
     *                         @OA\Property(property="email", type="string", example="john@example.com"),
     *                         @OA\Property(property="role_name", type="string", example="Foreman"),
     *                         @OA\Property(property="role_code", type="string", example="foreman"),
     *                         @OA\Property(property="avatar_url", type="string", nullable=true),
     *                         @OA\Property(property="status", type="integer", example=1)
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid date parameters",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Invalid date format or missing dates")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Task not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Task not found")
     *         )
     *     )
     * )
     */
    public function getAvailableWorkers(int $taskId): void
    {
        try {
            $request = Flight::request();
            $startDate = $request->query['start_date'] ?? null;
            $endDate = $request->query['end_date'] ?? null;

            // Валидация дат
            if (!$startDate || !$endDate) {
                Flight::json([
                    'status' => 'error',
                    'message' => 'start_date and end_date are required',
                    'data' => null
                ], 400);
                return;
            }

            if (!$this->isValidDate($startDate) || !$this->isValidDate($endDate)) {
                Flight::json([
                    'status' => 'error',
                    'message' => 'Invalid date format. Use YYYY-MM-DD',
                    'data' => null
                ], 400);
                return;
            }

            if (strtotime($endDate) < strtotime($startDate)) {
                Flight::json([
                    'status' => 'error',
                    'message' => 'end_date must be after or equal to start_date',
                    'data' => null
                ], 400);
                return;
            }

            $connection = $this->database->getConnection();

            // Проверяем, существует ли задача
            $taskCheck = $connection->executeQuery(
                "SELECT id, project_id FROM fw_prj_tasks WHERE id = ?",
                [$taskId]
            );
            $task = $taskCheck->fetchAssociative();

            if (!$task) {
                Flight::json([
                    'status' => 'error',
                    'message' => 'Task not found',
                    'data' => null
                ], 404);
                return;
            }

            $projectId = (int)$task['project_id'];

            // Получаем уже назначенных на текущую задачу (task_lead_id и team_members из fw_prj_team_members)
            $assignedUsersResult = $connection->executeQuery(
                "SELECT DISTINCT user_id FROM fw_prj_team_members WHERE task_id = ?",
                [$taskId]
            );
            $assignedUserIds = $assignedUsersResult->fetchFirstColumn();
            $assignedUserIds = array_map('intval', $assignedUserIds);

            // Получаем занятых работников в других задачах (пересечение дат)
            // Проверяем все задачи всех проектов, где есть пересечение дат
            // Два периода пересекаются, если: (start1 <= end2) AND (end1 >= start2)
            $busyUsersSql = "
                SELECT DISTINCT tm.user_id
                FROM fw_prj_team_members tm
                INNER JOIN fw_prj_tasks t ON tm.task_id = t.id
                WHERE tm.task_id IS NOT NULL
                  AND tm.task_id != ?
                  AND t.start_planned IS NOT NULL
                  AND t.end_planned IS NOT NULL
                  AND t.start_planned <= ?
                  AND t.end_planned >= ?
            ";
            $busyUsersResult = $connection->executeQuery(
                $busyUsersSql,
                [
                    $taskId,
                    $endDate,    // task.start <= request.end
                    $startDate   // task.end >= request.start
                ]
            );
            $busyUserIds = $busyUsersResult->fetchFirstColumn();
            $busyUserIds = array_map('intval', $busyUserIds);

            // Объединяем исключенных пользователей
            $excludedUserIds = array_unique(array_merge($assignedUserIds, $busyUserIds));

            // Строим SQL запрос для получения доступных работников
            $sql = "
                SELECT 
                    u.id,
                    u.id as user_id,
                    u.first_name,
                    u.last_name,
                    CONCAT(u.first_name, ' ', u.last_name) as full_name,
                    u.email,
                    u.role_id,
                    u.job_title,
                    u.status,
                    u.avatar_url,
                    r.code as role_code,
                    r.name as role_name
                FROM fw_users u
                LEFT JOIN fw_glob_roles r ON u.role_id = r.id
                WHERE u.status = 1
                  AND u.archived_at IS NULL
                  AND (r.code IS NULL OR r.code NOT IN ('admin', 'project_manager'))
            ";

            $params = [];

            // Исключаем уже назначенных и занятых
            if (!empty($excludedUserIds)) {
                $placeholders = str_repeat('?,', count($excludedUserIds) - 1) . '?';
                $sql .= " AND u.id NOT IN ($placeholders)";
                $params = array_merge($params, $excludedUserIds);
            }

            $sql .= " ORDER BY u.first_name ASC, u.last_name ASC";

            $result = $connection->executeQuery($sql, $params);
            $workers = $result->fetchAllAssociative();

            // Форматируем результат
            $formattedWorkers = array_map(function($worker) {
                return [
                    'id' => (int)$worker['id'],
                    'user_id' => (int)$worker['user_id'],
                    'first_name' => $worker['first_name'],
                    'last_name' => $worker['last_name'],
                    'full_name' => $worker['full_name'],
                    'email' => $worker['email'],
                    'role_name' => $worker['role_name'] ?? null,
                    'role_code' => $worker['role_code'] ?? null,
                    'avatar_url' => $worker['avatar_url'] ?? null,
                    'status' => (int)$worker['status']
                ];
            }, $workers);

            Flight::json([
                'status' => 'success',
                'data' => [
                    'workers' => $formattedWorkers
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to get available workers', [
                'task_id' => $taskId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            Flight::json([
                'status' => 'error',
                'message' => 'Failed to get available workers',
                'data' => null
            ], 500);
        }
    }

    /**
     * Валидация данных задачи
     */
    private function validateTaskData(array $data, bool $isCreate = true): array
    {
        if ($isCreate) {
            // Проверка обязательных полей
            if (!isset($data['name']) || empty(trim($data['name']))) {
                return [
                    'valid' => false,
                    'message' => "Field 'name' is required"
                ];
            }
            
            if (!isset($data['start_planned']) || empty(trim($data['start_planned']))) {
                return [
                    'valid' => false,
                    'message' => "Field 'start_planned' is required"
                ];
            }
            
            // task_lead_id не обязателен при создании, но если передан - должен быть валидным
            if (isset($data['task_lead_id']) && (!is_numeric($data['task_lead_id']) || $data['task_lead_id'] <= 0)) {
                return [
                    'valid' => false,
                    'message' => "Field 'task_lead_id' must be a positive number if provided"
                ];
            }
        }

        // Валидация длины полей
        if (isset($data['name']) && strlen($data['name']) > 255) {
            return [
                'valid' => false,
                'message' => 'Task name must not exceed 255 characters'
            ];
        }

        foreach (['address', 'wbs_path'] as $addrField) {
            if (isset($data[$addrField]) && is_string($data[$addrField]) && strlen($data[$addrField]) > self::TASK_ADDRESS_MAX_LEN) {
                return [
                    'valid' => false,
                    'message' => 'address must not exceed ' . self::TASK_ADDRESS_MAX_LEN . ' characters',
                ];
            }
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
            
            // Проверяем, существует ли пользователь и активен ли он
            try {
                $connection = $this->database->getConnection();
                
                // Сначала проверяем, существует ли пользователь вообще
                $checkUser = $connection->executeQuery(
                    "SELECT id, status FROM fw_v_users WHERE id = ?",
                    [$data['task_lead_id']]
                );
                $user = $checkUser->fetchAssociative();
                
                if (!$user) {
                    return [
                        'valid' => false,
                        'message' => "Task lead user with ID {$data['task_lead_id']} not found"
                    ];
                }
                
                // Проверяем, активен ли пользователь (status может быть BOOLEAN или TINYINT(1))
                $isActive = $user['status'] == 1 || $user['status'] === true || $user['status'] === '1';
                
                if (!$isActive) {
                    return [
                        'valid' => false,
                        'message' => "Task lead user with ID {$data['task_lead_id']} is inactive"
                    ];
                }
            } catch (Exception $e) {
                // Если не удается проверить пользователя, пропускаем валидацию
                // чтобы не блокировать создание задачи из-за проблем с БД
                $this->logger->warning('Failed to validate task lead user', [
                    'task_lead_id' => $data['task_lead_id'],
                    'error' => $e->getMessage()
                ]);
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
    private function formatTask(array $task, ?float $projectLat = null, ?float $projectLng = null): array
    {
        return [
            'id' => (int)$task['id'],
            'task_order' => (int)$task['task_order'],
            'project_id' => (int)$task['project_id'],
            'address' => $this->formatTaskAddressForResponse(isset($task['address']) ? (string) $task['address'] : null),
            'name' => $task['name'],
            'start_planned' => $task['start_planned'],
            'end_planned' => $task['end_planned'],
            'start_time' => $task['start_time'] ?? null,
            'end_time' => $task['end_time'] ?? null,
            'milestone' => $task['milestone'] ?? null, // ENUM: 'inspection','visit','meeting','review','delivery','approval','other' или NULL
            'status' => $task['status'],
            'progress_pct' => (int)$task['progress_pct'],
            'notes' => $task['notes'],
            'task_lead_id' => isset($task['task_lead_id']) && $task['task_lead_id'] ? (int)$task['task_lead_id'] : null,
            'team_members' => isset($task['team_members']) && $task['team_members'] ? json_decode($task['team_members'], true) : null,
            'resources' => isset($task['resources']) && $task['resources'] ? json_decode($task['resources'], true) : null,
            'baseline_start' => $task['baseline_start'] ?? null,
            'baseline_end' => $task['baseline_end'] ?? null,
            'actual_start' => $task['actual_start'] ?? null,
            'actual_end' => $task['actual_end'] ?? null,
            'slack_days' => isset($task['slack_days']) && $task['slack_days'] ? (int)$task['slack_days'] : null,
            'created_at' => $task['created_at'],
            'updated_at' => $task['updated_at'],
            ...$this->fieldSubmissionPayloadFromRow($task, $projectLat, $projectLng),
        ];
    }

    /**
     * Обновить порядок задач проекта
     * PUT /api/v1/projects/{project_id}/tasks/reorder
     *
     * @OA\Put(
     *     path="/api/v1/projects/{project_id}/tasks/reorder",
     *     summary="Reorder project tasks",
     *     description="Update the order of tasks in a project",
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
     *             required={"order"},
     *             @OA\Property(property="projectId", type="integer", example=10),
     *             @OA\Property(
     *                 property="order",
     *                 type="array",
     *                 @OA\Items(type="integer"),
     *                 example={1, 5, 12, 4, 2}
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Tasks reordered successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Tasks reordered successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid request data",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=400),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Invalid request data"),
     *             @OA\Property(property="data", type="object", nullable=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Project not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=404),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Project not found"),
     *             @OA\Property(property="data", type="object", nullable=true)
     *         )
     *     )
     * )
     */
    public function reorderTasks(int $projectId): void
    {
        $request = Flight::request();
        $data = json_decode($request->getBody(), true);
        $order = $data['order'];

        $connection = $this->database->getConnection();
        $connection->beginTransaction();

        try {
            // Просто обновляем task_order для каждой задачи из массива
            foreach ($order as $newOrder => $taskId) {
                $connection->executeStatement(
                    "UPDATE fw_prj_tasks SET task_order = ? WHERE id = ? AND project_id = ?",
                    [$newOrder + 1, (int)$taskId, $projectId]
                );
            }

            $connection->commit();

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Tasks reordered successfully',
                'data' => [
                    'reordered_count' => count($order)
                ]
            ]);

        } catch (Exception $e) {
            $connection->rollBack();
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Internal server error',
                'data' => null
            ], 500);
        }
    }

    /**
     * Создать зависимость между задачами
     * POST /api/v1/projects/{project_id}/dependencies
     *
     * @OA\Post(
     *     path="/api/v1/projects/{project_id}/dependencies",
     *     summary="Create task dependency",
     *     tags={"Dependencies"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="project_id",
     *         in="path",
     *         required=true,
     *         description="Project ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"from_task_id", "to_task_id", "dependency_type"},
     *             @OA\Property(property="from_task_id", type="integer", description="Source task ID"),
     *             @OA\Property(property="to_task_id", type="integer", description="Target task ID"),
     *             @OA\Property(property="dependency_type", type="string", enum={"FS", "SS", "FF", "SF"}, description="Dependency type"),
     *             @OA\Property(property="lag_days", type="integer", description="Lag days", default=0),
     *             @OA\Property(property="priority", type="integer", description="Priority", default=1)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Dependency created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Dependency created successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="dependency_id", type="integer")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=401),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Invalid or expired token"),
     *             @OA\Property(property="data", type="object", nullable=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=500),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Internal server error"),
     *             @OA\Property(property="data", type="object", nullable=true)
     *         )
     *     )
     * )
     */
    public function createDependency(int $projectId): void
    {
        try {
            $request = Flight::request();
            $data = json_decode($request->getBody(), true);

            // Валидация обязательных полей
            if (!isset($data['from_task_id']) || !isset($data['to_task_id']) || !isset($data['dependency_type'])) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Missing required fields: from_task_id, to_task_id, dependency_type',
                    'data' => null
                ], 400);
                return;
            }

            $connection = $this->database->getConnection();
            
            // Логируем начало создания зависимости
            $this->logger->info('Starting dependency creation', [
                'project_id' => $projectId,
                'data' => $data
            ]);

            $connection->beginTransaction();

            try {
                // Получаем текущего пользователя
                $user = Flight::get('current_user');
                $actorId = $user['id'] ?? null;

                $sql = "INSERT INTO fw_prj_task_dependencies (project_id, from_task_id, to_task_id, dependency_type, lag_days, priority, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)";
                
                $params = [
                    $projectId,
                    (int)$data['from_task_id'],
                    (int)$data['to_task_id'],
                    $data['dependency_type'],
                    (int)($data['lag_days'] ?? 0),
                    (int)($data['priority'] ?? 1),
                    $actorId ?? $data['created_by'] ?? null
                ];

                $this->logger->info('Executing INSERT for dependency', [
                    'sql' => $sql,
                    'params' => $params
                ]);

                $connection->executeStatement($sql, $params);
                $dependencyId = (int)$connection->lastInsertId();

                $this->logger->info('Dependency inserted, got ID', [
                    'dependency_id' => $dependencyId
                ]);

                if (!$dependencyId || $dependencyId === 0) {
                    throw new \Exception('Failed to get dependency ID after insert. lastInsertId returned: ' . $dependencyId);
                }

                // Логируем событие создания зависимости
                try {
                    $this->logger->info('Starting event logging for dependency', [
                        'dependency_id' => $dependencyId
                    ]);

                    $logResult = $this->eventLoggingService->logSimple(
                        entityType: 'task_dependency',
                        entityId: $dependencyId,
                        eventType: 'TASK_DEPENDENCY_ADDED',
                        afterData: [
                            'id' => $dependencyId,
                            'project_id' => (int)$projectId,
                            'from_task_id' => (int)$data['from_task_id'],
                            'to_task_id' => (int)$data['to_task_id'],
                            'dependency_type' => $data['dependency_type'],
                            'lag_days' => (int)($data['lag_days'] ?? 0),
                            'priority' => (int)($data['priority'] ?? 1),
                            'created_at' => date('c')
                        ],
                        options: [
                            'actor_type' => 'user',
                            'actor_id' => $actorId,
                            'changed_fields' => ['from_task_id', 'to_task_id', 'dependency_type', 'lag_days', 'priority'],
                            'comment' => "Task dependency created: task {$data['from_task_id']} -> task {$data['to_task_id']} (type: {$data['dependency_type']})",
                            'ip' => Flight::request()->ip ?? null,
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                            'severity' => 'important'
                        ]
                    );

                    $this->logger->info('Event logging completed', [
                        'dependency_id' => $dependencyId,
                        'log_result' => $logResult
                    ]);
                } catch (\Exception $e) {
                    // Логируем ошибку, но не прерываем создание зависимости
                    $this->logger->error('Failed to log dependency creation event', [
                        'error' => $e->getMessage(),
                        'dependency_id' => $dependencyId,
                        'trace' => $e->getTraceAsString(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine()
                    ]);
                }

                $this->logger->info('Committing transaction', [
                    'dependency_id' => $dependencyId
                ]);

                $connection->commit();

                $this->logger->info('Transaction committed successfully', [
                    'dependency_id' => $dependencyId
                ]);

                Flight::json([
                    'error_code' => 0,
                    'status' => 'success',
                    'message' => 'Dependency created successfully',
                    'data' => [
                        'dependency_id' => $dependencyId
                    ]
                ]);

            } catch (Exception $e) {
                $connection->rollBack();
                $this->logger->error('Failed to create dependency', [
                    'project_id' => $projectId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'data' => $data
                ]);

                Flight::json([
                    'error_code' => 500,
                    'status' => 'error',
                    'message' => 'Failed to create dependency: ' . $e->getMessage(),
                    'data' => null
                ], 500);
            }
        } catch (Exception $e) {
            $this->logger->error('Failed to create dependency - outer catch', [
                'project_id' => $projectId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Internal server error',
                'data' => null
            ], 500);
        }
    }

    /**
     * Обновить зависимость
     * PUT /api/v1/dependencies/{id}
     *
     * @OA\Put(
     *     path="/api/v1/dependencies/{dependency_id}",
     *     summary="Update task dependency",
     *     tags={"Dependencies"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="dependency_id",
     *         in="path",
     *         required=true,
     *         description="Dependency ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="dependency_type", type="string", enum={"FS", "SS", "FF", "SF"}, description="Dependency type"),
     *             @OA\Property(property="lag_days", type="integer", description="Lag days"),
     *             @OA\Property(property="priority", type="integer", description="Priority")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Dependency updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Dependency updated successfully"),
     *             @OA\Property(property="data", type="object", nullable=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=400),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="No fields to update"),
     *             @OA\Property(property="data", type="object", nullable=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=401),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Invalid or expired token"),
     *             @OA\Property(property="data", type="object", nullable=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=500),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Internal server error"),
     *             @OA\Property(property="data", type="object", nullable=true)
     *         )
     *     )
     * )
     */
    public function updateDependency(int $dependencyId): void
    {
        $request = Flight::request();
        $data = json_decode($request->getBody(), true);

        $connection = $this->database->getConnection();
        
        // Получаем текущие данные зависимости перед обновлением
        $beforeResult = $connection->executeQuery(
            "SELECT id, project_id, from_task_id, to_task_id, dependency_type, lag_days, priority
             FROM fw_prj_task_dependencies WHERE id = ?",
            [$dependencyId]
        );
        $beforeData = $beforeResult->fetchAssociative();
        
        if (!$beforeData) {
            Flight::json([
                'error_code' => 404,
                'status' => 'error',
                'message' => 'Dependency not found',
                'data' => null
            ], 404);
            return;
        }

        $connection->beginTransaction();

        try {
            $updateFields = [];
            $params = [];

            if (isset($data['dependency_type'])) {
                $updateFields[] = 'dependency_type = ?';
                $params[] = $data['dependency_type'];
            }

            if (isset($data['lag_days'])) {
                $updateFields[] = 'lag_days = ?';
                $params[] = $data['lag_days'];
            }

            if (isset($data['priority'])) {
                $updateFields[] = 'priority = ?';
                $params[] = $data['priority'];
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

            $params[] = $dependencyId;
            $sql = "UPDATE fw_prj_task_dependencies SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $connection->executeStatement($sql, $params);

            // Получаем обновленные данные
            $afterResult = $connection->executeQuery(
                "SELECT id, project_id, from_task_id, to_task_id, dependency_type, lag_days, priority
                 FROM fw_prj_task_dependencies WHERE id = ?",
                [$dependencyId]
            );
            $afterData = $afterResult->fetchAssociative();

            // Логируем событие обновления зависимости
            try {
                $user = Flight::get('current_user');
                $actorId = $user['id'] ?? null;

                $this->eventLoggingService->logSimple(
                    entityType: 'task_dependency',
                    entityId: $dependencyId,
                    eventType: 'TASK_DEPENDENCY_UPDATED',
                    afterData: [
                        'id' => (int)$afterData['id'],
                        'project_id' => (int)$afterData['project_id'],
                        'from_task_id' => (int)$afterData['from_task_id'],
                        'to_task_id' => (int)$afterData['to_task_id'],
                        'dependency_type' => $afterData['dependency_type'],
                        'lag_days' => (int)$afterData['lag_days'],
                        'priority' => (int)$afterData['priority'],
                        'updated_at' => date('c')
                    ],
                    options: [
                        'actor_type' => 'user',
                        'actor_id' => $actorId,
                        'before_data' => [
                            'id' => (int)$beforeData['id'],
                            'dependency_type' => $beforeData['dependency_type'],
                            'lag_days' => (int)$beforeData['lag_days'],
                            'priority' => (int)$beforeData['priority']
                        ],
                        'changed_fields' => array_keys($data),
                        'comment' => "Task dependency updated: task {$afterData['from_task_id']} -> task {$afterData['to_task_id']}",
                        'ip' => Flight::request()->ip ?? null,
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                        'severity' => 'important'
                    ]
                );
            } catch (\Exception $e) {
                $this->logger->warning('Failed to log dependency update event', [
                    'error' => $e->getMessage(),
                    'dependency_id' => $dependencyId
                ]);
            }

            $connection->commit();

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Dependency updated successfully',
                'data' => null
            ]);

        } catch (Exception $e) {
            $connection->rollBack();
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Internal server error',
                'data' => null
            ], 500);
        }
    }

    /**
     * Удалить зависимость
     * DELETE /api/v1/dependencies/{id}
     *
     * @OA\Delete(
     *     path="/api/v1/dependencies/{dependency_id}",
     *     summary="Delete task dependency",
     *     tags={"Dependencies"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="dependency_id",
     *         in="path",
     *         required=true,
     *         description="Dependency ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Dependency deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Dependency deleted successfully"),
     *             @OA\Property(property="data", type="object", nullable=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=401),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Invalid or expired token"),
     *             @OA\Property(property="data", type="object", nullable=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=500),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Internal server error"),
     *             @OA\Property(property="data", type="object", nullable=true)
     *         )
     *     )
     * )
     */
    public function deleteDependency(int $dependencyId): void
    {
        $connection = $this->database->getConnection();
        
        // Получаем данные зависимости перед удалением для логирования
        $dependencyResult = $connection->executeQuery(
            "SELECT id, project_id, from_task_id, to_task_id, dependency_type, lag_days, priority
             FROM fw_prj_task_dependencies WHERE id = ?",
            [$dependencyId]
        );
        $dependencyData = $dependencyResult->fetchAssociative();
        
        if (!$dependencyData) {
            Flight::json([
                'error_code' => 404,
                'status' => 'error',
                'message' => 'Dependency not found',
                'data' => null
            ], 404);
            return;
        }

        $connection->beginTransaction();

        try {
            $connection->executeStatement(
                "DELETE FROM fw_prj_task_dependencies WHERE id = ?",
                [$dependencyId]
            );

            // Логируем событие удаления зависимости
            try {
                $user = Flight::get('current_user');
                $actorId = $user['id'] ?? null;

                $this->eventLoggingService->logSimple(
                    entityType: 'task_dependency',
                    entityId: $dependencyId,
                    eventType: 'TASK_DEPENDENCY_REMOVED',
                    afterData: [
                        'id' => (int)$dependencyData['id'],
                        'project_id' => (int)$dependencyData['project_id'],
                        'from_task_id' => (int)$dependencyData['from_task_id'],
                        'to_task_id' => (int)$dependencyData['to_task_id'],
                        'dependency_type' => $dependencyData['dependency_type'],
                        'deleted_at' => date('c')
                    ],
                    options: [
                        'actor_type' => 'user',
                        'actor_id' => $actorId,
                        'before_data' => [
                            'id' => (int)$dependencyData['id'],
                            'project_id' => (int)$dependencyData['project_id'],
                            'from_task_id' => (int)$dependencyData['from_task_id'],
                            'to_task_id' => (int)$dependencyData['to_task_id'],
                            'dependency_type' => $dependencyData['dependency_type'],
                            'lag_days' => (int)$dependencyData['lag_days'],
                            'priority' => (int)$dependencyData['priority']
                        ],
                        'changed_fields' => ['deleted'],
                        'comment' => "Task dependency removed: task {$dependencyData['from_task_id']} -> task {$dependencyData['to_task_id']} (type: {$dependencyData['dependency_type']})",
                        'ip' => Flight::request()->ip ?? null,
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                        'severity' => 'important'
                    ]
                );
            } catch (\Exception $e) {
                $this->logger->warning('Failed to log dependency deletion event', [
                    'error' => $e->getMessage(),
                    'dependency_id' => $dependencyId
                ]);
            }

            $connection->commit();

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Dependency deleted successfully',
                'data' => null
            ]);

        } catch (Exception $e) {
            $connection->rollBack();
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Internal server error',
                'data' => null
            ], 500);
        }
    }

    /**
     * Получить все зависимости проекта
     * GET /api/v1/projects/{project_id}/dependencies
     *
     * @OA\Get(
     *     path="/api/v1/projects/{project_id}/dependencies",
     *     summary="Get project dependencies",
     *     tags={"Dependencies"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="project_id",
     *         in="path",
     *         required=true,
     *         description="Project ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Project dependencies retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Project dependencies retrieved successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="dependencies", type="array",
     *                     @OA\Items(
     *                         @OA\Property(property="id", type="integer"),
     *                         @OA\Property(property="project_id", type="integer"),
     *                         @OA\Property(property="from_task_id", type="integer"),
     *                         @OA\Property(property="to_task_id", type="integer"),
     *                         @OA\Property(property="dependency_type", type="string"),
     *                         @OA\Property(property="lag_days", type="integer"),
     *                         @OA\Property(property="priority", type="integer"),
     *                         @OA\Property(property="created_by", type="integer"),
     *                         @OA\Property(property="created_at", type="string", format="date-time"),
     *                         @OA\Property(property="updated_at", type="string", format="date-time")
     *                     )
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
     *             @OA\Property(property="message", type="string", example="Invalid or expired token"),
     *             @OA\Property(property="data", type="object", nullable=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=500),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Internal server error"),
     *             @OA\Property(property="data", type="object", nullable=true)
     *         )
     *     )
     * )
     */
    public function getProjectDependencies(int $projectId): void
    {
        try {
            $connection = $this->database->getConnection();
            
            $result = $connection->executeQuery(
                "SELECT id, project_id, from_task_id, to_task_id, dependency_type, lag_days, priority, created_by, created_at, updated_at FROM fw_prj_task_dependencies WHERE project_id = ? ORDER BY id ASC",
                [$projectId]
            );
            $dependencies = $result->fetchAllAssociative();

            // Форматируем зависимости
            $formattedDependencies = array_map(function($dep) {
                return [
                    'id' => (int)$dep['id'],
                    'project_id' => (int)$dep['project_id'],
                    'from_task_id' => (int)$dep['from_task_id'],
                    'to_task_id' => (int)$dep['to_task_id'],
                    'dependency_type' => $dep['dependency_type'],
                    'lag_days' => (int)$dep['lag_days'],
                    'priority' => (int)$dep['priority'],
                    'created_by' => (int)$dep['created_by'],
                    'created_at' => $dep['created_at'],
                    'updated_at' => $dep['updated_at']
                ];
            }, $dependencies);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Project dependencies retrieved successfully',
                'data' => [
                    'dependencies' => $formattedDependencies
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to retrieve project dependencies', [
                'project_id' => $projectId,
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Internal server error',
                'data' => null
            ], 500);
        }
    }

    /**
     * Получить зависимости конкретной задачи
     * GET /api/v1/tasks/{task_id}/dependencies
     *
     * @OA\Get(
     *     path="/api/v1/tasks/{task_id}/dependencies",
     *     summary="Get task dependencies",
     *     tags={"Dependencies"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="task_id",
     *         in="path",
     *         required=true,
     *         description="Task ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Task dependencies retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Task dependencies retrieved successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="dependencies", type="array",
     *                     @OA\Items(
     *                         @OA\Property(property="id", type="integer"),
     *                         @OA\Property(property="project_id", type="integer"),
     *                         @OA\Property(property="from_task_id", type="integer"),
     *                         @OA\Property(property="to_task_id", type="integer"),
     *                         @OA\Property(property="dependency_type", type="string"),
     *                         @OA\Property(property="lag_days", type="integer"),
     *                         @OA\Property(property="priority", type="integer"),
     *                         @OA\Property(property="created_by", type="integer"),
     *                         @OA\Property(property="created_at", type="string", format="date-time"),
     *                         @OA\Property(property="updated_at", type="string", format="date-time")
     *                     )
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
     *             @OA\Property(property="message", type="string", example="Invalid or expired token"),
     *             @OA\Property(property="data", type="object", nullable=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=500),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Internal server error"),
     *             @OA\Property(property="data", type="object", nullable=true)
     *         )
     *     )
     * )
     */
    public function getTaskDependencies(int $taskId): void
    {
        try {
            $connection = $this->database->getConnection();
            
            $result = $connection->executeQuery(
                "SELECT id, project_id, from_task_id, to_task_id, dependency_type, lag_days, priority, created_by, created_at, updated_at FROM fw_prj_task_dependencies WHERE from_task_id = ? OR to_task_id = ? ORDER BY id ASC",
                [$taskId, $taskId]
            );
            $dependencies = $result->fetchAllAssociative();

            // Форматируем зависимости
            $formattedDependencies = array_map(function($dep) {
                return [
                    'id' => (int)$dep['id'],
                    'project_id' => (int)$dep['project_id'],
                    'from_task_id' => (int)$dep['from_task_id'],
                    'to_task_id' => (int)$dep['to_task_id'],
                    'dependency_type' => $dep['dependency_type'],
                    'lag_days' => (int)$dep['lag_days'],
                    'priority' => (int)$dep['priority'],
                    'created_by' => (int)$dep['created_by'],
                    'created_at' => $dep['created_at'],
                    'updated_at' => $dep['updated_at']
                ];
            }, $dependencies);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Task dependencies retrieved successfully',
                'data' => [
                    'dependencies' => $formattedDependencies
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to retrieve task dependencies', [
                'task_id' => $taskId,
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Internal server error',
                'data' => null
            ], 500);
        }
    }

    /**
     * Нормализовать порядок всех задач в проекте
     * PUT /api/v1/projects/{project_id}/tasks/normalize-order
     */
    public function normalizeTaskOrder(int $projectId): void
    {
        try {
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

            // Начинаем транзакцию
            $connection->beginTransaction();

            try {
                // Сначала сбрасываем ВСЕ task_order в 0
                $connection->executeStatement(
                    "UPDATE fw_prj_tasks SET task_order = 0 WHERE project_id = ?",
                    [$projectId]
                );
                
                // Получаем все задачи проекта
                $allTasksResult = $connection->executeQuery(
                    "SELECT id FROM fw_prj_tasks WHERE project_id = ? ORDER BY id ASC",
                    [$projectId]
                );
                $allTasks = $allTasksResult->fetchFirstColumn();
                
                // Устанавливаем правильный порядок 1, 2, 3, 4, 5...
                foreach ($allTasks as $index => $taskId) {
                    $connection->executeStatement(
                        "UPDATE fw_prj_tasks SET task_order = ? WHERE id = ? AND project_id = ?",
                        [$index + 1, (int)$taskId, $projectId]
                    );
                }

                // Подтверждаем транзакцию
                $connection->commit();

                Flight::json([
                    'error_code' => 0,
                    'status' => 'success',
                    'message' => 'Task order normalized successfully',
                    'data' => [
                        'normalized_count' => count($allTasks)
                    ]
                ]);

            } catch (Exception $e) {
                // Откатываем транзакцию в случае ошибки
                $connection->rollBack();
                throw $e;
            }

        } catch (Exception $e) {
            $this->logger->error('Failed to normalize task order', [
                'project_id' => $projectId,
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Internal server error',
                'data' => null
            ], 500);
        }
    }

    /**
     * GET /api/v1/projects/{project_id}/tasks/stats
     */
    public function getTaskStats(int $projectId): void
    {
        try {
            $connection = $this->database->getConnection();
            $projectCheck = $connection->executeQuery(
                'SELECT id FROM fw_projects WHERE id = ?',
                [$projectId]
            );

            if (!$projectCheck->fetchOne()) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Project not found',
                    'data' => null,
                ], 404);
                return;
            }

            $row = $connection->executeQuery(
                "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN status = 'planned' THEN 1 ELSE 0 END) AS planned,
                    SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) AS scheduled,
                    SUM(CASE WHEN status = 'scheduled_accepted' THEN 1 ELSE 0 END) AS scheduled_accepted,
                    SUM(CASE WHEN status IN ('in_progress', 'in-progress') THEN 1 ELSE 0 END) AS in_progress,
                    SUM(CASE WHEN status = 'partially_completed' THEN 1 ELSE 0 END) AS partially_completed,
                    SUM(CASE WHEN status IN ('delayed_due_to_issue', 'delayed', 'blocked') THEN 1 ELSE 0 END) AS delayed_due_to_issue,
                    SUM(CASE WHEN status = 'ready_for_inspection' THEN 1 ELSE 0 END) AS ready_for_inspection,
                    SUM(CASE WHEN status IN ('completed', 'done') THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN milestone IS NOT NULL AND milestone != '' THEN 1 ELSE 0 END) AS milestones,
                    AVG(COALESCE(progress_pct, 0)) AS avg_progress
                FROM fw_prj_tasks
                WHERE project_id = ?",
                [$projectId]
            )->fetchAssociative();

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Task statistics retrieved successfully',
                'data' => [
                    'total' => (int) ($row['total'] ?? 0),
                    'planned' => (int) ($row['planned'] ?? 0),
                    'scheduled' => (int) ($row['scheduled'] ?? 0),
                    'scheduledAccepted' => (int) ($row['scheduled_accepted'] ?? 0),
                    'inProgress' => (int) ($row['in_progress'] ?? 0),
                    'partiallyCompleted' => (int) ($row['partially_completed'] ?? 0),
                    'delayedDueToIssue' => (int) ($row['delayed_due_to_issue'] ?? 0),
                    'readyForInspection' => (int) ($row['ready_for_inspection'] ?? 0),
                    'completed' => (int) ($row['completed'] ?? 0),
                    'milestones' => (int) ($row['milestones'] ?? 0),
                    'avgProgress' => round((float) ($row['avg_progress'] ?? 0), 1),
                ],
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch task stats', [
                'project_id' => $projectId,
                'error' => $e->getMessage(),
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Internal server error',
                'data' => null,
            ], 500);
        }
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
     * Get all team members for a task (including invited people)
     * GET /api/v1/projects/{projectId}/tasks/{taskId}/team
     */
    public function getTaskTeam($projectId, $taskId)
    {
        try {
            $connection = $this->database->getConnection();
            
            // Проверяем, что задача существует и принадлежит проекту
            $taskCheck = $connection->executeQuery(
                "SELECT id, milestone FROM fw_prj_tasks WHERE id = ? AND project_id = ?",
                [$taskId, $projectId]
            );
            $task = $taskCheck->fetchAssociative();
            
            if (!$task) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Task not found',
                    'data' => null
                ], 404);
                return;
            }
            
            // Получаем всех участников команды для этой задачи
            $sql = "
                SELECT 
                    tm.id,
                    tm.project_id,
                    tm.task_id,
                    tm.user_id,
                    tm.role_in_project,
                    tm.assigned_at,
                    tm.invited_people,
                    u.id as user_table_id,
                    u.first_name,
                    u.last_name,
                    u.email,
                    u.job_title,
                    u.role_code,
                    u.role_name
                FROM fw_prj_team_members tm
                LEFT JOIN fw_v_users u ON tm.user_id = u.id
                WHERE tm.task_id = ? AND tm.project_id = ?
                ORDER BY tm.assigned_at ASC
            ";
            
            $result = $connection->executeQuery($sql, [$taskId, $projectId]);
            $teamMembers = $result->fetchAllAssociative();
            
            // Определяем, является ли задача milestone
            $isMilestone = $task['milestone'] !== null && $task['milestone'] !== '';
            
            $formattedMembers = [];
            
            if ($isMilestone) {
                // Для milestone: ОДНА запись с task_lead и JSON массивом invited_people
                foreach ($teamMembers as $member) {
                    if ($member['role_in_project'] === 'task_lead') {
                        $formattedMember = [
                            'id' => (int)$member['id'],
                            'project_id' => (int)$member['project_id'],
                            'task_id' => (int)$member['task_id'],
                            'user_id' => $member['user_id'] ? (int)$member['user_id'] : null,
                            'role_in_project' => $member['role_in_project'],
                            'assigned_at' => $member['assigned_at']
                        ];
                        
                        // Данные ответственного из fw_users
                        if ($member['user_id']) {
                            $formattedMember['name'] = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
                            $formattedMember['email'] = $member['email'] ?? null;
                            $formattedMember['user_type'] = $member['role_code'] ?? null;
                            $formattedMember['job_title'] = $member['job_title'] ?? null;
                        }
                        
                        // invited_people - это JSON массив
                        if ($member['invited_people']) {
                            $invitedPeopleArray = json_decode($member['invited_people'], true);
                            if ($invitedPeopleArray && is_array($invitedPeopleArray)) {
                                $formattedMember['invited_people'] = $invitedPeopleArray;
                            }
                        }
                        
                        $formattedMembers[] = $formattedMember;
                        break; // Только одна запись для milestone
                    }
                }
            } else {
                // Для обычной задачи: отдельные записи для каждого члена бригады
                foreach ($teamMembers as $member) {
                    $formattedMember = [
                        'id' => (int)$member['id'],
                        'project_id' => (int)$member['project_id'],
                        'task_id' => (int)$member['task_id'],
                        'user_id' => $member['user_id'] ? (int)$member['user_id'] : null,
                        'role_in_project' => $member['role_in_project'],
                        'assigned_at' => $member['assigned_at']
                    ];
                    
                    // Обычный участник команды - используем данные из fw_users
                    if ($member['user_id']) {
                        $formattedMember['name'] = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
                        $formattedMember['email'] = $member['email'] ?? null;
                        $formattedMember['user_type'] = $member['role_code'] ?? null;
                        $formattedMember['job_title'] = $member['job_title'] ?? null;
                    }
                    
                    $formattedMembers[] = $formattedMember;
                }
            }
            
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'data' => [
                    'team_members' => $formattedMembers
                ]
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Error getting task team', [
                'project_id' => $projectId,
                'task_id' => $taskId,
                'error' => $e->getMessage()
            ]);
            
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to get task team',
                'data' => null
            ], 500);
        }
    }

    /**
     * @deprecated This method is no longer used. Invited people are added via createTask/updateTask
     * Add invited person to milestone
     * POST /api/v1/projects/{projectId}/tasks/{taskId}/invited
     */
    public function addInvitedPerson($projectId, $taskId)
    {
        try {
            $connection = $this->database->getConnection();
            
            // Проверяем, что задача существует и является milestone
            $taskCheck = $connection->executeQuery(
                "SELECT id, milestone FROM fw_prj_tasks WHERE id = ? AND project_id = ?",
                [$taskId, $projectId]
            );
            $task = $taskCheck->fetchAssociative();
            
            if (!$task) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Task not found or does not belong to this project',
                    'data' => null
                ], 404);
                return;
            }
            
            if (!$task['milestone']) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Task is not a milestone',
                    'data' => null
                ], 400);
                return;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            // Валидация
            if (!isset($input['invited_people']) || !isset($input['invited_people']['name']) || empty(trim($input['invited_people']['name']))) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'invited_people.name is required',
                    'data' => null
                ], 400);
                return;
            }
            
            // Валидация email, если указан
            if (isset($input['invited_people']['email']) && !empty($input['invited_people']['email'])) {
                if (!filter_var($input['invited_people']['email'], FILTER_VALIDATE_EMAIL)) {
                    Flight::json([
                        'error_code' => 400,
                        'status' => 'error',
                        'message' => 'Invalid email format',
                        'data' => null
                    ], 400);
                    return;
                }
            }
            
            // Подготавливаем JSON данные для invited_people
            $invitedPeopleData = [
                'name' => trim($input['invited_people']['name']),
                'email' => isset($input['invited_people']['email']) ? trim($input['invited_people']['email']) : null,
                'company' => isset($input['invited_people']['company']) ? trim($input['invited_people']['company']) : null,
                'phone' => isset($input['invited_people']['phone']) ? trim($input['invited_people']['phone']) : null,
                'notes' => isset($input['invited_people']['notes']) ? trim($input['invited_people']['notes']) : null,
                'avatar' => isset($input['invited_people']['avatar']) ? trim($input['invited_people']['avatar']) : null
            ];
            
            // Удаляем null значения для чистоты JSON
            $invitedPeopleData = array_filter($invitedPeopleData, function($value) {
                return $value !== null && $value !== '';
            });
            
            $invitedPeopleJson = json_encode($invitedPeopleData, JSON_UNESCAPED_UNICODE);
            
            // user_id может быть указан, если человек уже есть в системе, иначе NULL
            $invitedUserId = isset($input['user_id']) && is_numeric($input['user_id']) 
                ? (int)$input['user_id'] 
                : null;
            
            // Добавляем приглашенного человека
            // Проверяем, что invited_people не пустой (должен содержать хотя бы name)
            if (empty($invitedPeopleJson) || $invitedPeopleJson === '[]' || $invitedPeopleJson === '{}') {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'invited_people data is empty',
                    'data' => null
                ], 400);
                return;
            }
            
            $connection->executeStatement(
                "INSERT INTO fw_prj_team_members (project_id, task_id, user_id, role_in_project, invited_people) VALUES (?, ?, ?, 'invited', ?)",
                [$projectId, $taskId, $invitedUserId, $invitedPeopleJson]
            );
            
            $teamMemberId = $connection->lastInsertId();
            
            // Получаем созданную запись
            if (!$teamMemberId || $teamMemberId === 0) {
                throw new \Exception("Failed to get inserted team member ID");
            }
            
            $result = $connection->executeQuery(
                "SELECT id, project_id, task_id, user_id, role_in_project, assigned_at, invited_people FROM fw_prj_team_members WHERE id = ?",
                [$teamMemberId]
            );
            $member = $result->fetchAssociative();
            
            if (!$member) {
                throw new \Exception("Failed to retrieve created team member with ID: {$teamMemberId}");
            }
            
            $formattedMember = [
                'id' => (int)$member['id'],
                'project_id' => (int)$member['project_id'],
                'task_id' => (int)$member['task_id'],
                'user_id' => $member['user_id'] ? (int)$member['user_id'] : null,
                'role_in_project' => $member['role_in_project'],
                'assigned_at' => $member['assigned_at'],
                'invited_people' => $member['invited_people'] ? json_decode($member['invited_people'], true) : null
            ];
            
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'data' => [
                    'team_member' => $formattedMember
                ]
            ], 201);
            
        } catch (\Exception $e) {
            $this->logger->error('Error adding invited person', [
                'project_id' => $projectId,
                'task_id' => $taskId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'input' => $input ?? null
            ]);
            
            $errorMessage = 'Failed to add invited person';
            $debugInfo = null;
            
            // В режиме разработки добавляем детальную информацию об ошибке
            if (isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] !== 'production') {
                $debugInfo = [
                    'error' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ];
            }
            
            $response = [
                'error_code' => 500,
                'status' => 'error',
                'message' => $errorMessage,
                'data' => null
            ];
            
            if ($debugInfo) {
                $response['debug'] = $debugInfo;
            }
            
            Flight::json($response, 500);
        }
    }

    /**
     * Worker / foreman phone geo check-in for task field work start or end.
     * Stored on fw_prj_tasks (separate from schedule slot trip columns).
     * POST /api/v1/projects/{projectId}/tasks/{taskId}/field-work/check-in
     * Body: { phase: "start"|"end", lat: number, lng: number }
     */
    public function checkInFieldWork(int $projectId, int $taskId): void
    {
        try {
            $payload = json_decode(Flight::request()->getBody(), true) ?? [];
            $phase = isset($payload['phase']) && is_string($payload['phase'])
                ? strtolower(trim($payload['phase']))
                : '';
            if ($phase !== 'start' && $phase !== 'end') {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'phase must be start or end',
                    'data' => null,
                ], 400);
                return;
            }
            if (!isset($payload['lat'], $payload['lng']) || !is_numeric($payload['lat']) || !is_numeric($payload['lng'])) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'lat and lng are required numbers',
                    'data' => null,
                ], 400);
                return;
            }
            $lat = (float) $payload['lat'];
            $lng = (float) $payload['lng'];
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'lat/lng out of range',
                    'data' => null,
                ], 400);
                return;
            }

            $connection = $this->database->getConnection();
            $user = Flight::get('current_user');
            $actorId = isset($user['id']) ? (int) $user['id'] : 0;
            if ($actorId <= 0) {
                Flight::json([
                    'error_code' => 401,
                    'status' => 'error',
                    'message' => 'Unauthorized',
                    'data' => null,
                ], 401);
                return;
            }

            if (!$this->taskAuth->canSubmitFieldWork($connection, $projectId, $taskId, $actorId)) {
                Flight::json([
                    'error_code' => 403,
                    'status' => 'error',
                    'message' => 'You are not allowed to record field work for this task',
                    'data' => null,
                ], 403);
                return;
            }

            $requiredCols = $phase === 'start'
                ? ['field_work_started_at', 'field_work_started_by', 'field_work_start_lat', 'field_work_start_lng']
                : ['field_work_ended_at', 'field_work_ended_by', 'field_work_end_lat', 'field_work_end_lng'];
            foreach ($requiredCols as $col) {
                if (!$this->taskOptionalColumnPresent($connection, $col)) {
                    Flight::json([
                        'error_code' => 503,
                        'status' => 'error',
                        'message' => 'Field work geo check-in is not available until database migration is applied',
                        'data' => null,
                    ], 503);
                    return;
                }
            }

            $beforeData = $connection->executeQuery(
                'SELECT ' . $this->resolveTaskDetailSelect($connection) . ' FROM fw_prj_tasks WHERE id = ? AND project_id = ?',
                [$taskId, $projectId]
            )->fetchAssociative();
            if (!$beforeData) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Task not found',
                    'data' => null,
                ], 404);
                return;
            }

            if (!empty($beforeData['field_submitted_at'])) {
                Flight::json([
                    'error_code' => 409,
                    'status' => 'error',
                    'message' => 'Work already submitted for PM review',
                    'data' => null,
                ], 409);
                return;
            }

            if ($phase === 'start') {
                if (!empty($beforeData['field_work_started_at'])) {
                    Flight::json([
                        'error_code' => 409,
                        'status' => 'error',
                        'message' => 'Start already recorded for this task',
                        'data' => null,
                    ], 409);
                    return;
                }
                $connection->executeStatement(
                    'UPDATE fw_prj_tasks
                     SET field_work_started_at = NOW(3),
                         field_work_started_by = ?,
                         field_work_start_lat = ?,
                         field_work_start_lng = ?,
                         updated_at = NOW(3)
                     WHERE id = ? AND project_id = ?',
                    [$actorId, $lat, $lng, $taskId, $projectId]
                );
            } else {
                if (empty($beforeData['field_work_started_at'])) {
                    Flight::json([
                        'error_code' => 409,
                        'status' => 'error',
                        'message' => 'Start work before ending',
                        'data' => null,
                    ], 409);
                    return;
                }
                if (!empty($beforeData['field_work_ended_at'])) {
                    Flight::json([
                        'error_code' => 409,
                        'status' => 'error',
                        'message' => 'End already recorded for this task',
                        'data' => null,
                    ], 409);
                    return;
                }
                $connection->executeStatement(
                    'UPDATE fw_prj_tasks
                     SET field_work_ended_at = NOW(3),
                         field_work_ended_by = ?,
                         field_work_end_lat = ?,
                         field_work_end_lng = ?,
                         updated_at = NOW(3)
                     WHERE id = ? AND project_id = ?',
                    [$actorId, $lat, $lng, $taskId, $projectId]
                );
            }

            $task = $connection->executeQuery(
                'SELECT ' . $this->resolveTaskDetailSelect($connection) . ' FROM fw_prj_tasks WHERE id = ? AND project_id = ?',
                [$taskId, $projectId]
            )->fetchAssociative();
            if (!$task) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Task not found after check-in',
                    'data' => null,
                ], 404);
                return;
            }

            $eventType = $phase === 'start' ? 'TASK_FIELD_WORK_STARTED' : 'TASK_FIELD_WORK_ENDED';
            $eventPhase = $phase === 'start' ? 'started' : 'ended';
            $this->logFieldWorkInitiatorEvent(
                $connection,
                $projectId,
                $taskId,
                $task,
                $beforeData,
                $actorId,
                $eventType,
                $eventPhase,
            );
            $this->fieldWorkNotifications->notifyManagers(
                $projectId,
                $taskId,
                $task,
                $actorId,
                $eventPhase,
                false,
            );

            $this->getTask($projectId, $taskId);
        } catch (\Exception $e) {
            $this->logger->error('Failed field work check-in', [
                'project_id' => $projectId,
                'task_id' => $taskId,
                'error' => $e->getMessage(),
            ]);
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to record field work check-in',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Foreman (task_lead) submits field work for PM review. Does not change official task status.
     */
    public function submitTask(int $projectId, int $taskId): void
    {
        try {
            $request = Flight::request();
            $body = json_decode($request->getBody(), true);
            $fieldNotes = is_array($body) && isset($body['field_notes']) && is_string($body['field_notes'])
                ? trim($body['field_notes'])
                : null;

            $connection = $this->database->getConnection();

            $taskRow = $connection->executeQuery(
                'SELECT ' . $this->resolveTaskDetailSelect($connection) . ' FROM fw_prj_tasks WHERE id = ? AND project_id = ?',
                [$taskId, $projectId]
            )->fetchAssociative();

            if (!$taskRow) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Task not found',
                    'data' => null,
                ], 404);
                return;
            }

            $user = Flight::get('current_user');
            $actorId = isset($user['id']) ? (int) $user['id'] : 0;
            if ($actorId <= 0) {
                Flight::json([
                    'error_code' => 401,
                    'status' => 'error',
                    'message' => 'Unauthorized',
                    'data' => null,
                ], 401);
                return;
            }

            if (!$this->taskAuth->canSubmitFieldWork($connection, $projectId, $taskId, $actorId)) {
                Flight::json([
                    'error_code' => 403,
                    'status' => 'error',
                    'message' => 'You are not allowed to submit field work for this task',
                    'data' => null,
                ], 403);
                return;
            }

            if (!empty($taskRow['field_submitted_at'])) {
                Flight::json([
                    'error_code' => 409,
                    'status' => 'error',
                    'message' => 'Work already submitted for PM review',
                    'data' => null,
                ], 409);
                return;
            }

            if (
                $this->taskOptionalColumnPresent($connection, 'field_work_started_at')
                && $this->taskOptionalColumnPresent($connection, 'field_work_ended_at')
                && (empty($taskRow['field_work_started_at']) || empty($taskRow['field_work_ended_at']))
            ) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Start and end work times are required before submitting',
                    'data' => null,
                ], 400);
                return;
            }

            if (
                !$this->taskOptionalColumnPresent($connection, 'field_submitted_at')
                || !$this->taskOptionalColumnPresent($connection, 'field_submitted_by')
            ) {
                Flight::json([
                    'error_code' => 503,
                    'status' => 'error',
                    'message' => 'Field submission is not available until database migration is applied',
                    'data' => null,
                ], 503);
                return;
            }

            $updateSql = 'UPDATE fw_prj_tasks SET field_submitted_at = NOW(), field_submitted_by = ?, updated_at = NOW()';
            $params = [$actorId];
            if ($fieldNotes !== null && $fieldNotes !== '' && $this->taskOptionalColumnPresent($connection, 'field_notes')) {
                $updateSql .= ', field_notes = ?';
                $params[] = $fieldNotes;
            }
            $updateSql .= ' WHERE id = ? AND project_id = ?';
            $params[] = $taskId;
            $params[] = $projectId;
            $connection->executeStatement($updateSql, $params);

            $accountableForemanId = $this->taskAuth->resolveAccountableForemanUserId($connection, $projectId, $taskId);
            $onBehalfOfForeman = $accountableForemanId !== null && $actorId !== $accountableForemanId;

            $afterRow = $connection->executeQuery(
                'SELECT ' . $this->resolveTaskDetailSelect($connection) . ' FROM fw_prj_tasks WHERE id = ? AND project_id = ?',
                [$taskId, $projectId]
            )->fetchAssociative();

            $this->eventLoggingService->logEvent(
                'task',
                $taskId,
                'TASK_FOREMAN_SUBMITTED',
                $this->fieldSubmissionPayloadFromRow($taskRow),
                array_merge(
                    $this->fieldSubmissionPayloadFromRow($afterRow ?: []),
                    [
                        'task_id' => $taskId,
                        'project_id' => $projectId,
                        'task_name' => $taskRow['name'] ?? null,
                        'status' => $taskRow['status'] ?? null,
                        'project_foreman_id' => $this->taskAuth->resolveProjectForemanUserId($connection, $projectId),
                        'task_lead_id' => $this->taskAuth->resolveTaskLeadUserId($connection, $taskId),
                        'accountable_foreman_id' => $accountableForemanId,
                        'initiated_by_user_id' => $actorId,
                        'submitted_by_user_id' => $actorId,
                        'submitted_on_behalf_of_lead' => $onBehalfOfForeman,
                        'on_behalf_of_foreman' => $onBehalfOfForeman,
                    ]
                ),
                ['field_submitted_at', 'field_submitted_by', 'field_notes'],
                [
                    'actor_type' => 'user',
                    'actor_id' => $actorId,
                    'comment' => $onBehalfOfForeman
                        ? 'Crew member submitted field work for PM review (accountable foreman on record)'
                        : 'Foreman submitted field work for PM review',
                    'ip' => Flight::request()->ip ?? null,
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                ]
            );

            // Re-use getTask response shape
            $this->getTask($projectId, $taskId);
        } catch (Exception $e) {
            $this->logger->error('Failed to submit task field work', [
                'project_id' => $projectId,
                'task_id' => $taskId,
                'error' => $e->getMessage(),
            ]);
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to submit work',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Normalize datetime for MySQL DATETIME — store wall-clock as entered (no UTC shift).
     */
    private function normalizeNullableDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(?::(\d{2}))?/', $trimmed, $m)) {
            $sec = isset($m[3]) && $m[3] !== '' ? $m[3] : '00';

            return sprintf('%s %s:%s', $m[1], $m[2], $sec);
        }

        try {
            $dt = new \DateTimeImmutable($trimmed);

            return $dt->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    private function isTruthyRequestFlag(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }
        if (!is_string($value)) {
            return false;
        }
        $normalized = strtolower(trim($value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Пересчитать date_start и date_end проекта по задачам (MIN(start_planned), MAX(end_planned)).
     * Вызывается после создания, обновления или удаления задачи.
     */
    private function recalculateProjectDates(int $projectId): void
    {
        try {
            $connection = $this->database->getConnection();
            $connection->executeStatement(
                "UPDATE fw_projects p
                 SET
                   date_start = (SELECT MIN(start_planned) FROM fw_prj_tasks WHERE project_id = p.id AND start_planned IS NOT NULL),
                   date_end = (SELECT MAX(end_planned) FROM fw_prj_tasks WHERE project_id = p.id AND end_planned IS NOT NULL),
                   updated_at = NOW()
                 WHERE p.id = ?",
                [$projectId]
            );
        } catch (\Exception $e) {
            $this->logger->warning('Failed to recalculate project dates', [
                'project_id' => $projectId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
