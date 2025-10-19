<?php

namespace App\Controllers;

use App\Database\Database;
use App\Services\EmailService;
use Doctrine\DBAL\Exception;
use Flight;
use Monolog\Logger;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Workers",
 *     description="Worker management and invitation system endpoints"
 * )
 */
class WorkerController
{
    private Logger $logger;
    private Database $database;
    private EmailService $emailService;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        
        try {
            $this->database = new Database();
            $this->emailService = new EmailService($logger);
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize WorkerController', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Получить список всех работников (активных и приглашенных)
     * GET /api/v1/workers
     *
     * @OA\Get(
     *     path="/api/v1/workers",
     *     summary="Get all workers",
     *     description="Retrieve a paginated list of all workers including active and invited users",
     *     tags={"Workers"},
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
     *         name="id",
     *         in="query",
     *         description="Filter by specific user ID",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="project_id",
     *         in="query",
     *         description="Filter by project ID - show only team members of this project",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="prj_mngr_id",
     *         in="query",
     *         description="Filter by project manager ID - show only users who manage projects",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="invitation_status",
     *         in="query",
     *         description="Filter by invitation status: invited, registered, expired",
     *         required=false,
     *         @OA\Schema(type="string", enum={"invited", "registered", "expired"})
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filter by user status: 1 (active), 0 (inactive)",
     *         required=false,
     *         @OA\Schema(type="integer", enum={0, 1})
     *     ),
     *     @OA\Parameter(
     *         name="role_id",
     *         in="query",
     *         description="Filter by role ID",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="role_code",
     *         in="query",
     *         description="Filter by role code: admin, contractor, architect, project_manager",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="job_title",
     *         in="query",
     *         description="Filter by job title (partial match)",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="archived",
     *         in="query",
     *         description="Filter by archived status: true, false",
     *         required=false,
     *         @OA\Schema(type="boolean")
     *     ),
     *     @OA\Parameter(
     *         name="two_factor",
     *         in="query",
     *         description="Filter by 2FA status: true, false",
     *         required=false,
     *         @OA\Schema(type="boolean")
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Search by name, email, phone, or job title",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="sort_by",
     *         in="query",
     *         description="Sort by field: id, email, first_name, last_name, created_at, updated_at, last_login, role_name, job_title",
     *         required=false,
     *         @OA\Schema(type="string", enum={"id", "email", "first_name", "last_name", "created_at", "updated_at", "last_login", "role_name", "job_title"}, default="created_at")
     *     ),
     *     @OA\Parameter(
     *         name="sort_order",
     *         in="query",
     *         description="Sort order: ASC, DESC",
     *         required=false,
     *         @OA\Schema(type="string", enum={"ASC", "DESC"}, default="DESC")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Workers retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Workers retrieved successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="workers", type="array", @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="email", type="string", example="worker@example.com"),
     *                     @OA\Property(property="password_hash", type="string", example="$2y$10$..."),
     *                     @OA\Property(property="first_name", type="string", example="John"),
     *                     @OA\Property(property="last_name", type="string", example="Doe"),
     *                     @OA\Property(property="phone", type="string", example="+1234567890"),
     *                     @OA\Property(property="role_id", type="integer", example=3),
     *                     @OA\Property(property="job_title", type="string", example="Developer"),
     *                     @OA\Property(property="status", type="integer", example=1),
     *                     @OA\Property(property="status_reason", type="string", nullable=true),
     *                     @OA\Property(property="status_details", type="string", nullable=true),
     *                     @OA\Property(property="additional_info", type="string", nullable=true),
     *                     @OA\Property(property="avatar_url", type="string", nullable=true),
     *                     @OA\Property(property="two_factor_enabled", type="boolean", example=false),
     *                     @OA\Property(property="two_factor_secret", type="string", nullable=true),
     *                     @OA\Property(property="last_login", type="string", format="date-time"),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time"),
     *                     @OA\Property(property="invitation_status", type="string", example="registered"),
     *                     @OA\Property(property="invitation_token", type="string", nullable=true),
     *                     @OA\Property(property="invitation_sent_at", type="string", format="date-time", nullable=true),
     *                     @OA\Property(property="invitation_expires_at", type="string", format="date-time", nullable=true),
     *                     @OA\Property(property="invited_by", type="integer", nullable=true),
     *                     @OA\Property(property="registration_completed_at", type="string", format="date-time", nullable=true),
     *                     @OA\Property(property="invitation_attempts", type="integer", example=0),
     *                     @OA\Property(property="last_reminder_sent_at", type="string", format="date-time", nullable=true),
     *                     @OA\Property(property="archived_at", type="string", format="date-time", nullable=true),
     *                     @OA\Property(property="role_code", type="string", example="contractor"),
     *                     @OA\Property(property="role_name", type="string", example="Contractor"),
     *                     @OA\Property(property="role_category", type="string", example="task"),
     *                     @OA\Property(property="role_description", type="string", nullable=true)
     *                 )),
     *                 @OA\Property(property="pagination", type="object",
     *                     @OA\Property(property="current_page", type="integer", example=1),
     *                     @OA\Property(property="per_page", type="integer", example=20),
     *                     @OA\Property(property="total", type="integer", example=100),
     *                     @OA\Property(property="last_page", type="integer", example=5),
     *                     @OA\Property(property="from", type="integer", example=1),
     *                     @OA\Property(property="to", type="integer", example=20),
     *                     @OA\Property(property="has_next_page", type="boolean", example=true),
     *                     @OA\Property(property="has_prev_page", type="boolean", example=false),
     *                     @OA\Property(property="next_page", type="integer", nullable=true, example=2),
     *                     @OA\Property(property="prev_page", type="integer", nullable=true, example=null)
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
     *             @OA\Property(property="message", type="string", example="Unauthorized")
     *         )
     *     )
     * )
     */
    public function getWorkers(): void
    {
        $this->logger->info('WorkerController::getWorkers called');

        
        
        try {
            $request = Flight::request();
            $page = (int)($request->query['page'] ?? 1);
            $limit = min((int)($request->query['limit'] ?? 20), 100);
            
            // Параметры фильтрации
            $id = $request->query['id'] ?? null; // выборка по ID
            $projectId = $request->query['project_id'] ?? null; // фильтр по проекту
            $prjMngrId = $request->query['prj_mngr_id'] ?? null; // фильтр по менеджеру проекта
            $invitationStatus = $request->query['invitation_status'] ?? null; // invitation_status
            $status = $request->query['status'] ?? null; // user status (active, suspended, etc.)
            $roleId = $request->query['role_id'] ?? null;
            $roleCode = $request->query['role_code'] ?? null;
            $jobTitle = $request->query['job_title'] ?? null;
            $archived = $request->query['archived'] ?? null; // true/false для архивированных
            $twoFactor = $request->query['two_factor'] ?? null; // true/false для 2FA
            $search = $request->query['search'] ?? null;
            $sortBy = $request->query['sort_by'] ?? 'created_at';
            $sortOrder = $request->query['sort_order'] ?? 'DESC';

            $offset = ($page - 1) * $limit;

            // Базовый SQL запрос - возвращаем все поля из fw_v_users
            $params = [];
            
            if ($projectId && is_numeric($projectId)) {
                // Если указан project_id, делаем JOIN с таблицей участников проекта (только участники)
                $sql = "SELECT 
                            u.id, u.email, u.first_name, u.last_name, u.dob, u.gender, u.nationality, u.country_of_origin, 
                            u.workforce_group, u.phone, u.role_id, u.job_title, u.city, u.status, u.emergency, 
                            u.status_changed_at, u.status_end_at, u.status_reason, u.status_details, u.additional_info, 
                            u.full_img_url, u.avatar_url, u.created_at, u.updated_at, u.invitation_status, 
                            u.invitation_sent_at, u.invitation_expires_at, u.invited_by, u.registration_completed_at, 
                            u.invitation_attempts, u.last_reminder_sent_at, u.archived_at,
                            r.id as role_id, r.code as role_code, r.name as role_name, r.category as role_category, r.description as role_description,
                            tm.role_in_project, tm.assigned_at,
                            true as is_project_member
                        FROM fw_users u
                        LEFT JOIN fw_glob_roles r ON u.role_id = r.id
                        INNER JOIN fw_prj_team_members tm ON u.id = tm.user_id
                        WHERE tm.project_id = ?";
                $params[] = (int)$projectId;
            } else {
                // Обычный запрос для всех пользователей (параметр prj_mngr_id игнорируется)
                $sql = "SELECT 
                            u.id, u.email, u.first_name, u.last_name, u.dob, u.gender, u.nationality, u.country_of_origin, 
                            u.workforce_group, u.phone, u.role_id, u.job_title, u.city, u.status, u.emergency, 
                            u.status_changed_at, u.status_end_at, u.status_reason, u.status_details, u.additional_info, 
                            u.full_img_url, u.avatar_url, u.created_at, u.updated_at, u.invitation_status, 
                            u.invitation_sent_at, u.invitation_expires_at, u.invited_by, u.registration_completed_at, 
                            u.invitation_attempts, u.last_reminder_sent_at, u.archived_at,
                            r.id as role_id, r.code as role_code, r.name as role_name, r.category as role_category, r.description as role_description
                        FROM fw_users u
                        LEFT JOIN fw_glob_roles r ON u.role_id = r.id
                        WHERE 1=1";
            }

            // Фильтр по ID (точное совпадение)
            if ($id && is_numeric($id)) {
                $sql .= " AND id = ?";
                $params[] = (int)$id;
            }

            // Фильтр по статусу приглашения
            if ($invitationStatus) {
                if (in_array($invitationStatus, ['invited', 'registered', 'expired'])) {
                    $sql .= " AND invitation_status = ?";
                    $params[] = $invitationStatus;
                } else {
                    // Для невалидных статусов возвращаем пустой результат
                    $sql .= " AND 1=0";
                }
            }

            // Фильтр по статусу пользователя (1=активный, 0=неактивный)
            if ($status !== null && in_array($status, [0, 1, '0', '1'])) {
                $sql .= " AND status = ?";
                $params[] = (int)$status;
            }

            // Фильтр по роли (ID)
            if ($roleId && is_numeric($roleId)) {
                $sql .= " AND role_id = ?";
                $params[] = (int)$roleId;
            }

            // Фильтр по коду роли
            if ($roleCode) {
                $sql .= " AND role_code = ?";
                $params[] = $roleCode;
            }

            // Фильтр по должности
            if ($jobTitle) {
                $sql .= " AND job_title LIKE ?";
                $params[] = "%{$jobTitle}%";
            }

            // Фильтр по архивированным пользователям
            if ($archived !== null) {
                if ($archived === 'true' || $archived === true) {
                    $sql .= " AND archived_at IS NOT NULL";
                } else {
                    $sql .= " AND archived_at IS NULL";
                }
            }

            // Фильтр по 2FA
            if ($twoFactor !== null) {
                if ($twoFactor === 'true' || $twoFactor === true) {
                    $sql .= " AND two_factor_enabled = 1";
                } else {
                    $sql .= " AND two_factor_enabled = 0";
                }
            }

            // Поиск по имени, email, телефону или должности
            if ($search) {
                $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ? OR job_title LIKE ?)";
                $searchTerm = "%{$search}%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            // Подсчет общего количества с теми же фильтрами
            if ($projectId && is_numeric($projectId)) {
                $countSql = "SELECT COUNT(*) as total 
                            FROM fw_users u
                            INNER JOIN fw_prj_team_members tm ON u.id = tm.user_id
                            WHERE tm.project_id = ?";
                $countParams = [(int)$projectId];
            } elseif ($prjMngrId && is_numeric($prjMngrId)) {
                $countSql = "SELECT COUNT(*) as total 
                            FROM fw_users u
                            INNER JOIN fw_projects p ON u.id = p.prj_manager
                            WHERE p.prj_manager = ?";
                $countParams = [(int)$prjMngrId];
            } else {
                $countSql = "SELECT COUNT(*) as total FROM fw_users WHERE 1=1";
                $countParams = [];
            }
            
            // Применяем те же фильтры для подсчета
            if ($id && is_numeric($id)) {
                $countSql .= " AND id = ?";
                $countParams[] = (int)$id;
            }
            
            if ($invitationStatus) {
                if (in_array($invitationStatus, ['invited', 'registered', 'expired'])) {
                    $countSql .= " AND invitation_status = ?";
                    $countParams[] = $invitationStatus;
                } else {
                    // Для невалидных статусов возвращаем пустой результат
                    $countSql .= " AND 1=0";
                }
            }
            
            if ($status !== null && in_array($status, [0, 1, '0', '1'])) {
                $countSql .= " AND status = ?";
                $countParams[] = (int)$status;
            }
            
            if ($roleId && is_numeric($roleId)) {
                $countSql .= " AND role_id = ?";
                $countParams[] = (int)$roleId;
            }
            
            if ($roleCode) {
                $countSql .= " AND role_code = ?";
                $countParams[] = $roleCode;
            }
            
            if ($jobTitle) {
                $countSql .= " AND job_title LIKE ?";
                $countParams[] = "%{$jobTitle}%";
            }
            
            if ($archived !== null) {
                if ($archived === 'true' || $archived === true) {
                    $countSql .= " AND archived_at IS NOT NULL";
                } else {
                    $countSql .= " AND archived_at IS NULL";
                }
            }
            
            if ($twoFactor !== null) {
                if ($twoFactor === 'true' || $twoFactor === true) {
                    $countSql .= " AND two_factor_enabled = 1";
                } else {
                    $countSql .= " AND two_factor_enabled = 0";
                }
            }
            
            if ($search) {
                $countSql .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone LIKE ? OR job_title LIKE ?)";
                $searchTerm = "%{$search}%";
                $countParams[] = $searchTerm;
                $countParams[] = $searchTerm;
                $countParams[] = $searchTerm;
                $countParams[] = $searchTerm;
                $countParams[] = $searchTerm;
            }

            $connection = $this->database->getConnection();
            $countResult = $connection->executeQuery($countSql, $countParams);
            $total = $countResult->fetchOne();

            // Добавляем сортировку и пагинацию
            // Сортировка
            $allowedSortFields = ['id', 'email', 'first_name', 'last_name', 'created_at', 'updated_at', 'last_login', 'role_name', 'job_title'];
            $sortBy = in_array($sortBy, $allowedSortFields) ? $sortBy : 'created_at';
            $sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';
            
            $sql .= " ORDER BY {$sortBy} {$sortOrder} LIMIT {$limit} OFFSET {$offset}";

            $result = $connection->executeQuery($sql, $params);
            $workers = $result->fetchAllAssociative();

            // Форматируем данные с вложенными объектами
            $formattedWorkers = array_map(function($worker) {
                $workerId = (int)$worker['id'];
                
                // Получаем профессиональные данные
                $professionalData = $this->getProfessionalData($workerId);
                
                // Получаем проекты пользователя
                $projects = $this->getUserProjects($workerId);
                
                // Получаем языки пользователя
                $languages = $this->getUserLanguages($workerId);
                
                return [
                    'id' => $workerId,
                    'email' => $worker['email'],
                    'first_name' => $worker['first_name'],
                    'last_name' => $worker['last_name'],
                    'dob' => $worker['dob'],
                    'gender' => $worker['gender'],
                    'nationality' => $worker['nationality'],
                    'country_of_origin' => $worker['country_of_origin'],
                    'workforce_group' => $worker['workforce_group'],
                    'phone' => $worker['phone'],
                    'role_id' => $worker['role_id'] ? (int)$worker['role_id'] : null,
                    'job_title' => $worker['job_title'],
                    'city' => $worker['city'],
                    'status' => (int)$worker['status'],
                    'emergency' => $this->getEmergencyData($workerId),
                    'status_changed_at' => $worker['status_changed_at'],
                    'status_end_at' => $worker['status_end_at'],
                    'status_reason' => $worker['status_reason'],
                    'status_details' => $worker['status_details'],
                    'additional_info' => $worker['additional_info'],
                    'full_img_url' => $worker['full_img_url'],
                    'avatar_url' => $worker['avatar_url'],
                    'created_at' => $worker['created_at'],
                    'updated_at' => $worker['updated_at'],
                    'invitation_status' => $worker['invitation_status'],
                    'invitation_sent_at' => $worker['invitation_sent_at'],
                    'invitation_expires_at' => $worker['invitation_expires_at'],
                    'invited_by' => $worker['invited_by'] ? (int)$worker['invited_by'] : null,
                    'registration_completed_at' => $worker['registration_completed_at'],
                    'invitation_attempts' => (int)$worker['invitation_attempts'],
                    'last_reminder_sent_at' => $worker['last_reminder_sent_at'],
                    'archived_at' => $worker['archived_at'],
                    'code' => $worker['role_code'],
                    'name' => $worker['role_name'],
                    'category' => $worker['role_category'],
                    'description' => $worker['role_description'],
                    'role' => [
                        'id' => $worker['role_id'] ? (int)$worker['role_id'] : null,
                        'code' => $worker['role_code'],
                        'name' => $worker['role_name'],
                        'category' => $worker['role_category']
                    ],
                    'professional_data' => $professionalData,
                    'projects' => $projects,
                    'languages' => $languages
                ];
            }, $workers);

            $lastPage = ceil($total / $limit);
            $hasNextPage = $page < $lastPage;
            $hasPrevPage = $page > 1;

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Workers retrieved successfully',
                'data' => [
                    'workers' => $formattedWorkers,
                    'pagination' => [
                        'current_page' => $page,
                        'per_page' => $limit,
                        'total' => (int)$total,
                        'last_page' => $lastPage,
                        'from' => $total > 0 ? $offset + 1 : 0,
                        'to' => min($offset + $limit, $total),
                        'has_next_page' => $hasNextPage,
                        'has_prev_page' => $hasPrevPage,
                        'next_page' => $hasNextPage ? $page + 1 : null,
                        'prev_page' => $hasPrevPage ? $page - 1 : null
                    ]
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error retrieving workers: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve workers',
                'data' => null
            ], 500);
        }
    }

    /**
     * Отправить приглашение работнику
     * POST /api/v1/workers/invite
     *
     * @OA\Post(
     *     path="/api/v1/workers/invite",
     *     summary="Send invitation to worker",
     *     description="Send an invitation email to a new worker with registration link",
     *     tags={"Workers"},
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Invitation data",
     *         @OA\JsonContent(
     *             required={"email", "first_name", "last_name"},
     *             @OA\Property(property="email", type="string", format="email", example="newworker@example.com", description="Worker's email address"),
     *             @OA\Property(property="first_name", type="string", example="John", description="Worker's first name"),
     *             @OA\Property(property="last_name", type="string", example="Doe", description="Worker's last name"),
     *             @OA\Property(property="role_id", type="integer", example=11, description="Role ID from fw_glob_roles table (optional)"),
     *             @OA\Property(property="user_type", type="string", example="architect", description="User type string (deprecated, use role_id instead)"),
     *             @OA\Property(property="job_title", type="string", example="Senior Architect", description="Job title (optional)"),
     *             @OA\Property(property="phone", type="string", example="+1234567890", description="Phone number (optional)"),
     *             @OA\Property(property="email_provider", type="string", example="auto", description="Email provider: sendgrid, phpmailer, or auto (optional)")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Invitation sent successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Invitation sent successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="invitation_token", type="string", example="abc123..."),
     *                 @OA\Property(property="expires_at", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request - user already exists",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=400),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="User with this email already exists")
     *         )
     *     )
     * )
     */
    public function sendInvitation(): void
    {
        $this->logger->info('WorkerController::sendInvitation called');
        
        try {
            $data = Flight::request()->data;

            // Валидация обязательных полей
            $requiredFields = ['email', 'first_name', 'last_name'];
            foreach ($requiredFields as $field) {
                if (empty($data->$field)) {
                    Flight::json([
                        'error_code' => 400,
                        'status' => 'error',
                        'message' => "Field '$field' is required",
                        'data' => null
                    ], 400);
                    return;
                }
            }

            $email = $data->email;
            $firstName = $data->first_name;
            $lastName = $data->last_name;
            $roleId = $data->role_id ?? null;
            $userType = $data->user_type ?? 'Employee'; // Для обратной совместимости
            $jobTitle = $data->job_title ?? null;
            $phone = $data->phone ?? null;
            $emailProvider = $data->email_provider ?? 'auto';
            
            // Валидация role_id если передан
            if ($roleId !== null && !is_numeric($roleId)) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Field role_id must be a number',
                    'data' => null
                ], 400);
                return;
            }

            // Проверяем, не существует ли уже пользователь с таким email
            $connection = $this->database->getConnection();
            $existingUser = $connection->executeQuery(
                "SELECT id, invitation_status FROM fw_v_users WHERE email = ?",
                [$email]
            )->fetchAssociative();

            if ($existingUser) {
                if ($existingUser['invitation_status'] === 'registered') {
                    Flight::json([
                        'error_code' => 400,
                        'status' => 'error',
                        'message' => 'User with this email already exists and is registered',
                        'data' => null
                    ], 400);
                    return;
                } elseif ($existingUser['invitation_status'] === 'invited') {
                    Flight::json([
                        'error_code' => 400,
                        'status' => 'error',
                        'message' => 'User with this email already has a pending invitation',
                        'data' => null
                    ], 400);
                    return;
                }
            }

            // Генерируем токен приглашения
            $invitationToken = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days')); // Приглашение действует 7 дней

            // Генерируем временный пароль
            $tempPassword = $this->generateTempPassword();
            $tempPasswordHash = password_hash($tempPassword, PASSWORD_DEFAULT);

            // Получаем ID текущего пользователя (администратора)
            $currentUserId = $this->getCurrentUserId();

            // Начинаем транзакцию
            $connection->beginTransaction();

            try {
            if ($existingUser) {
                // Обновляем существующего пользователя
                $sql = "UPDATE fw_users SET 
                            first_name = ?, last_name = ?, job_title = ?, phone = ?,";
                
                if ($roleId !== null) {
                    $sql .= " role_id = ?,";
                }
                
                $sql .= " invitation_status = 'invited', invitation_token = ?, 
                                invitation_sent_at = NOW(), invitation_expires_at = ?, invited_by = ?,
                                password_hash = ?
                        WHERE email = ?";
                
                $params = [$firstName, $lastName, $jobTitle, $phone];
                if ($roleId !== null) {
                    $params[] = $roleId;
                }
                $params = array_merge($params, [$invitationToken, $expiresAt, $currentUserId, $tempPasswordHash, $email]);
                
                $connection->executeStatement($sql, $params);
            } else {
                // Создаем нового пользователя
                $sql = "INSERT INTO fw_users (
                            email, first_name, last_name, job_title, phone,";
                
                if ($roleId !== null) {
                    $sql .= " role_id,";
                }
                
                $sql .= " invitation_status, invitation_token, invitation_sent_at, 
                                invitation_expires_at, invited_by, password_hash, created_at
                            ) VALUES (?, ?, ?, ?, ?,";
                
                if ($roleId !== null) {
                    $sql .= " ?,";
                }
                
                $sql .= " 'invited', ?, NOW(), ?, ?, ?, NOW())";
                
                $params = [$email, $firstName, $lastName, $jobTitle, $phone];
                if ($roleId !== null) {
                    $params[] = $roleId;
                }
                $params = array_merge($params, [$invitationToken, $expiresAt, $currentUserId, $tempPasswordHash]);
                
                $connection->executeStatement($sql, $params);
            }

            // Отправить email с приглашением
            $emailSent = $this->emailService->sendWorkerInvitation(
                $email, 
                $firstName, 
                $lastName, 
                $invitationToken, 
                    $emailProvider,
                    $tempPassword
            );

            if (!$emailSent) {
                    // Если email не отправлен, откатываем транзакцию
                    $connection->rollBack();
                    
                    $this->logger->error('Failed to send invitation email, transaction rolled back', [
                    'email' => $email,
                    'provider' => $emailProvider
                ]);
                    
                    Flight::json([
                        'error_code' => 500,
                        'status' => 'error',
                        'message' => 'Failed to send invitation email',
                        'data' => null
                    ], 500);
                    return;
                }

                // Если все успешно, коммитим транзакцию
                $connection->commit();

                $this->logger->info('Invitation sent successfully', [
                'email' => $email,
                'invited_by' => $currentUserId,
                'expires_at' => $expiresAt
            ]);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Invitation sent successfully',
                'data' => [
                    'invitation_token' => $invitationToken,
                    'expires_at' => $expiresAt
                ]
            ], 201);

            } catch (Exception $e) {
                // В случае любой ошибки откатываем транзакцию
                $connection->rollBack();
                
                $this->logger->error('Error in invitation transaction, rolled back: ' . $e->getMessage(), [
                    'email' => $email,
                    'error' => $e->getMessage()
                ]);
                
                Flight::json([
                    'error_code' => 500,
                    'status' => 'error',
                    'message' => 'Failed to send invitation: ' . $e->getMessage(),
                    'data' => null
                ], 500);
                return;
            }

        } catch (Exception $e) {
            $this->logger->error('Error sending invitation: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to send invitation',
                'data' => null
            ], 500);
        }
    }

    /**
     * Получить доступные email провайдеры
     * GET /api/v1/workers/email-providers
     *
     * @OA\Get(
     *     path="/api/v1/workers/email-providers",
     *     summary="Get available email providers",
     *     description="Get list of available email providers for sending invitations",
     *     tags={"Workers"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Email providers retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Email providers retrieved successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="providers", type="object",
     *                     @OA\Property(property="sendgrid", type="object",
     *                         @OA\Property(property="name", type="string", example="SendGrid"),
     *                         @OA\Property(property="available", type="boolean", example=true),
     *                         @OA\Property(property="description", type="string", example="Professional email delivery service")
     *                     ),
     *                     @OA\Property(property="phpmailer", type="object",
     *                         @OA\Property(property="name", type="string", example="PHPMailer"),
     *                         @OA\Property(property="available", type="boolean", example=true),
     *                         @OA\Property(property="description", type="string", example="Simple SMTP email sending")
     *                     )
     *                 )
     *             )
     *         )
     *     )
     * )
     */
    public function getEmailProviders(): void
    {
        $this->logger->info('WorkerController::getEmailProviders called');
        
        try {
            $providers = $this->emailService->getAvailableProviders();

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Email providers retrieved successfully',
                'data' => [
                    'providers' => $providers
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Error retrieving email providers: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve email providers',
                'data' => null
            ], 500);
        }
    }

    /**
     * Проверка аутентификации
     */
    private function checkAuth(): bool
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            Flight::json([
                'error_code' => 401,
                'status' => 'error',
                'message' => 'Authorization token required',
                'data' => null
            ], 401);
            return false;
        }

        $token = $matches[1];
        
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
                'message' => 'Invalid token format',
                'data' => null
            ], 401);
            return false;
        }
    }

    /**
     * Получить ID текущего пользователя из токена
     */
    private function getCurrentUserId(): int
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            $token = $matches[1];
            $decoded = base64_decode($token);
            $payload = json_decode($decoded, true);
            return (int)($payload['user_id'] ?? 1);
        }
        
        return 1; // Fallback
    }

    /**
     * Генерирует временный пароль для приглашения
     */
    private function generateTempPassword(): string
    {
        // Генерируем пароль из 12 символов: буквы, цифры и специальные символы
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
        $password = '';
        
        // Добавляем минимум одну заглавную букву
        $password .= chr(rand(65, 90));
        
        // Добавляем минимум одну строчную букву
        $password .= chr(rand(97, 122));
        
        // Добавляем минимум одну цифру
        $password .= chr(rand(48, 57));
        
        // Добавляем минимум один специальный символ
        $specialChars = '!@#$%^&*';
        $password .= $specialChars[rand(0, strlen($specialChars) - 1)];
        
        // Заполняем остальные символы случайными
        for ($i = 4; $i < 12; $i++) {
            $password .= $chars[rand(0, strlen($chars) - 1)];
        }
        
        // Перемешиваем символы
        return str_shuffle($password);
    }

    /**
     * Get emergency data for user
     */
    private function getEmergencyData(int $userId): ?array
    {
        try {
            $connection = Database::getConnection();
            $sql = "SELECT emergency FROM fw_users WHERE id = ?";
            $result = $connection->executeQuery($sql, [$userId]);
            $row = $result->fetchAssociative();
            
            if ($row['emergency']) {
                return json_decode($row['emergency'], true) ?: null;
            }
            
            return null;
        } catch (Exception $e) {
            $this->logger->error('Error fetching emergency data', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get professional data for user
     */
    private function getProfessionalData(int $userId): array
    {
        try {
            $connection = Database::getConnection();
            $sql = "SELECT * FROM fw_user_professional WHERE user_id = ?";
            $result = $connection->executeQuery($sql, [$userId]);
            $professional = $result->fetchAllAssociative();
            
            return $professional ?: [];
        } catch (Exception $e) {
            $this->logger->error('Error fetching professional data', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get projects for user
     */
    private function getUserProjects(int $userId): array
    {
        try {
            $connection = Database::getConnection();
            $sql = "SELECT p.id, p.prj_name, p.address, p.date_start, p.date_end, p.priority, p.status, 
                           p.prj_manager, p.created_at, p.updated_at, tm.role_in_project, tm.assigned_at,
                           m.first_name as manager_first_name, m.last_name as manager_last_name, m.email as manager_email, m.phone as manager_phone,
                           m.avatar_url as manager_avatar_url, m.full_img_url as manager_full_img_url
                    FROM fw_projects p
                    INNER JOIN fw_prj_team_members tm ON p.id = tm.project_id
                    LEFT JOIN fw_users m ON p.prj_manager = m.id
                    WHERE tm.user_id = ?";
            
            $result = $connection->executeQuery($sql, [$userId]);
            $projects = $result->fetchAllAssociative();
            
            return array_map(function($project) {
                return [
                    'id' => (int)$project['id'],
                    'name' => $project['prj_name'],
                    'address' => $project['address'],
                    'date_start' => $project['date_start'],
                    'date_end' => $project['date_end'],
                    'priority' => $project['priority'],
                    'status' => $project['status'],
                    'prj_manager' => $project['prj_manager'] ? (int)$project['prj_manager'] : null,
                    'manager' => [
                        'id' => $project['prj_manager'] ? (int)$project['prj_manager'] : null,
                        'first_name' => $project['manager_first_name'],
                        'last_name' => $project['manager_last_name'],
                        'email' => $project['manager_email'],
                        'phone' => $project['manager_phone'],
                        'avatar_url' => $project['manager_avatar_url'],
                        'full_img_url' => $project['manager_full_img_url']
                    ],
                    'created_at' => $project['created_at'],
                    'updated_at' => $project['updated_at'],
                    'role_in_project' => $project['role_in_project'],
                    'assigned_at' => $project['assigned_at']
                ];
            }, $projects);
        } catch (Exception $e) {
            $this->logger->error('Error fetching user projects', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get languages for user
     */
    private function getUserLanguages(int $userId): array
    {
        try {
            $connection = Database::getConnection();
            $sql = "SELECT l.id, l.name, ul.prof_level
                    FROM fw_languages l
                    INNER JOIN fw_user_languages ul ON l.id = ul.language_id
                    WHERE ul.worker_id = ?";
            
            $result = $connection->executeQuery($sql, [$userId]);
            $languages = $result->fetchAllAssociative();
            
            return array_map(function($language) {
                return [
                    'id' => (int)$language['id'],
                    'name' => $language['name'],
                    'prof_level' => $language['prof_level']
                ];
            }, $languages);
        } catch (Exception $e) {
            $this->logger->error('Error fetching user languages', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

}
