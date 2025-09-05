<?php

namespace App\Controllers;

use App\Database\Database;
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

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        
        try {
            $this->database = new Database();
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize WorkerController database', [
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
     *         name="status",
     *         in="query",
     *         description="Filter by invitation status: active, invited, registered",
     *         required=false,
     *         @OA\Schema(type="string", enum={"active", "invited", "registered"})
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Search by name or email",
     *         required=false,
     *         @OA\Schema(type="string")
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
     *                     @OA\Property(property="first_name", type="string", example="John"),
     *                     @OA\Property(property="last_name", type="string", example="Doe"),
     *                     @OA\Property(property="phone", type="string", example="+1234567890"),
     *                     @OA\Property(property="user_type", type="string", example="Employee"),
     *                     @OA\Property(property="job_title", type="string", example="Developer"),
     *                     @OA\Property(property="status", type="integer", example=1),
     *                     @OA\Property(property="invitation_status", type="string", example="active"),
     *                     @OA\Property(property="invitation_sent_at", type="string", format="date-time", nullable=true),
     *                     @OA\Property(property="invitation_expires_at", type="string", format="date-time", nullable=true),
     *                     @OA\Property(property="invited_by", type="integer", nullable=true),
     *                     @OA\Property(property="registration_completed_at", type="string", format="date-time", nullable=true),
     *                     @OA\Property(property="last_login", type="string", format="date-time"),
     *                     @OA\Property(property="created_at", type="string", format="date-time")
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
        // Проверка токена
        if (!$this->checkAuth()) {
            return;
        }

        try {
            $request = Flight::request();
            $page = (int)($request->query['page'] ?? 1);
            $limit = min((int)($request->query['limit'] ?? 20), 100);
            $status = $request->query['status'] ?? null;
            $search = $request->query['search'] ?? null;

            $offset = ($page - 1) * $limit;

            // Базовый SQL запрос
            $sql = "SELECT 
                        id, email, first_name, last_name, phone, user_type, job_title, status,
                        invitation_status, invitation_sent_at, invitation_expires_at, 
                        invited_by, registration_completed_at, last_login, created_at
                    FROM fw_users 
                    WHERE 1=1";

            $params = [];
            $paramCount = 0;

            // Фильтр по статусу приглашения
            if ($status && in_array($status, ['active', 'invited', 'registered'])) {
                $sql .= " AND invitation_status = ?";
                $params[] = $status;
            }

            // Поиск по имени или email
            if ($search) {
                $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
                $searchTerm = "%{$search}%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            // Подсчет общего количества
            $countSql = "SELECT COUNT(*) as total FROM fw_users WHERE 1=1";
            $countParams = [];
            
            if ($status && in_array($status, ['active', 'invited', 'registered'])) {
                $countSql .= " AND invitation_status = ?";
                $countParams[] = $status;
            }
            
            if ($search) {
                $countSql .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
                $searchTerm = "%{$search}%";
                $countParams[] = $searchTerm;
                $countParams[] = $searchTerm;
                $countParams[] = $searchTerm;
            }

            $connection = $this->database->getConnection();
            $countResult = $connection->executeQuery($countSql, $countParams);
            $total = $countResult->fetchOne();

            // Добавляем сортировку и пагинацию
            $sql .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;

            $result = $connection->executeQuery($sql, $params);
            $workers = $result->fetchAllAssociative();

            // Форматируем данные
            $formattedWorkers = array_map(function($worker) {
                return [
                    'id' => (int)$worker['id'],
                    'email' => $worker['email'],
                    'first_name' => $worker['first_name'],
                    'last_name' => $worker['last_name'],
                    'phone' => $worker['phone'],
                    'user_type' => $worker['user_type'],
                    'job_title' => $worker['job_title'],
                    'status' => (int)$worker['status'],
                    'invitation_status' => $worker['invitation_status'],
                    'invitation_sent_at' => $worker['invitation_sent_at'],
                    'invitation_expires_at' => $worker['invitation_expires_at'],
                    'invited_by' => $worker['invited_by'] ? (int)$worker['invited_by'] : null,
                    'registration_completed_at' => $worker['registration_completed_at'],
                    'last_login' => $worker['last_login'],
                    'created_at' => $worker['created_at']
                ];
            }, $workers);

            $lastPage = ceil($total / $limit);

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
                        'last_page' => $lastPage
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
     *             @OA\Property(property="email", type="string", format="email", example="newworker@example.com"),
     *             @OA\Property(property="first_name", type="string", example="John"),
     *             @OA\Property(property="last_name", type="string", example="Doe"),
     *             @OA\Property(property="user_type", type="string", example="Employee"),
     *             @OA\Property(property="job_title", type="string", example="Developer"),
     *             @OA\Property(property="phone", type="string", example="+1234567890")
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
        // Проверка токена
        if (!$this->checkAuth()) {
            return;
        }

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
            $userType = $data->user_type ?? 'Employee';
            $jobTitle = $data->job_title ?? null;
            $phone = $data->phone ?? null;

            // Проверяем, не существует ли уже пользователь с таким email
            $connection = $this->database->getConnection();
            $existingUser = $connection->executeQuery(
                "SELECT id, invitation_status FROM fw_users WHERE email = ?",
                [$email]
            )->fetchAssociative();

            if ($existingUser) {
                if ($existingUser['invitation_status'] === 'active') {
                    Flight::json([
                        'error_code' => 400,
                        'status' => 'error',
                        'message' => 'User with this email already exists and is active',
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

            // Получаем ID текущего пользователя (администратора)
            $currentUserId = $this->getCurrentUserId();

            if ($existingUser) {
                // Обновляем существующего пользователя
                $sql = "UPDATE fw_users SET 
                            first_name = ?, last_name = ?, user_type = ?, job_title = ?, phone = ?,
                            invitation_status = 'invited', invitation_token = ?, 
                            invitation_sent_at = NOW(), invitation_expires_at = ?, invited_by = ?
                        WHERE email = ?";
                
                $connection->executeStatement($sql, [
                    $firstName, $lastName, $userType, $jobTitle, $phone,
                    $invitationToken, $expiresAt, $currentUserId, $email
                ]);
            } else {
                // Создаем нового пользователя
                $sql = "INSERT INTO fw_users (
                            email, first_name, last_name, user_type, job_title, phone,
                            invitation_status, invitation_token, invitation_sent_at, 
                            invitation_expires_at, invited_by, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, 'invited', ?, NOW(), ?, ?, NOW())";
                
                $connection->executeStatement($sql, [
                    $email, $firstName, $lastName, $userType, $jobTitle, $phone,
                    $invitationToken, $expiresAt, $currentUserId
                ]);
            }

            // TODO: Отправить email с приглашением
            // $this->sendInvitationEmail($email, $firstName, $lastName, $invitationToken);

            $this->logger->info('Invitation sent', [
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
            $decoded = base64_decode($token);
            $payload = json_decode($decoded, true);
            
            if (!$payload || !isset($payload['user_id'])) {
                Flight::json([
                    'error_code' => 401,
                    'status' => 'error',
                    'message' => 'Invalid token',
                    'data' => null
                ], 401);
                return false;
            }

            // Проверяем, не истек ли токен
            if (isset($payload['exp']) && $payload['exp'] < time()) {
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
}
