<?php

namespace App\Controllers;

use App\Database\Database;
use App\Services\UserAuditService;
use App\Services\EventLoggingService;
use Doctrine\DBAL\Exception;
use Flight;
use Monolog\Logger;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Authentication",
 *     description="User authentication and authorization endpoints"
 * )
 */
class AuthController
{
    private Logger $logger;
    private UserAuditService $userAuditService;
    private EventLoggingService $eventLoggingService;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        
        try {
            $this->userAuditService = new UserAuditService($logger);
            $this->eventLoggingService = new EventLoggingService($logger);
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize AuthController', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/auth/login",
     *     summary="User login",
     *     description="Authenticate user with email and password (including temporary passwords for invited users)",
     *     tags={"Authentication"},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Login credentials",
     *         @OA\JsonContent(
     *             required={"email", "password"},
     *             @OA\Property(property="email", type="string", format="email", example="user@example.com", description="User email address"),
     *             @OA\Property(property="password", type="string", example="password123", description="User password (regular or temporary)")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Login successful",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Login successful"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="token", type="string", example="eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...", description="JWT authentication token"),
     *                 @OA\Property(property="user", type="object", 
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="email", type="string", example="user@example.com"),
     *                     @OA\Property(property="first_name", type="string", example="John"),
     *                     @OA\Property(property="last_name", type="string", example="Doe")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request - validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=400),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Email and password are required"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Invalid credentials",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=401),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Invalid email or password"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=500),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Internal server error"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     )
     * )
     */
    public function login(): void
    {
        try {
            error_log('=== LOGIN METHOD START ===');
            
            // Get request body
            $requestBody = Flight::request()->getBody();
            $data = json_decode($requestBody, true);
            
            error_log('Request body: ' . $requestBody);
            error_log('Parsed data: ' . print_r($data, true));

            // Validate input
            if (!$this->validateLoginData($data)) {
                error_log('Validation failed');
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Invalid input data. Email and password are required.',
                    'data' => null,
                    'details' => [
                        'email' => 'Valid email address is required',
                        'password' => 'Password is required'
                    ]
                ], 400);
                return;
            }

            error_log('Validation passed');
            
            $email = $data['email'];
            
            $password = $data['password'];
            
            // Regular password authentication (including temporary passwords)
            error_log('Calling authenticateUser');
            $user = $this->authenticateUser($email, $password);
            error_log('authenticateUser returned: ' . print_r($user, true));

            if (!$user) {
                // Логируем неудачную попытку входа
                // Не логируем неудачную попытку, так как user_id неизвестен

                $this->logger->warning('Failed login attempt', [
                    'email' => $email,
                    'ip' => Flight::request()->ip
                ]);

                Flight::json([
                    'error_code' => 401,
                    'status' => 'error',
                    'message' => 'Invalid email or password',
                    'data' => null
                ], 401);
                return;
            }

            // Проверяем, не архивирован ли пользователь
            if (isset($user['error']) && $user['error'] === 'archived') {
                // Логируем попытку входа архивированного пользователя
                $this->userAuditService->logLogin(null, false, 'User account archived');

                $this->logger->warning('Login attempt for archived user', [
                    'email' => $email,
                    'ip' => Flight::request()->ip
                ]);

                Flight::json([
                    'error_code' => 401,
                    'status' => 'error',
                    'message' => 'User account has been deleted',
                    'data' => null
                ], 401);
                return;
            }

            // Check if 2FA is enabled for this user
            $twoFactorEnabled = (bool)($user['two_factor_enabled'] ?? false);

            if ($twoFactorEnabled) {
                // If 2FA is enabled, return user info without token
                // Frontend should then call 2FA send-code endpoint
                $this->logger->info('Login successful, 2FA required', [
                    'user_id' => $user['id'],
                    'email' => $email,
                    'ip' => Flight::request()->ip
                ]);

                Flight::json([
                    'error_code' => 0,
                    'status' => 'success',
                    'message' => 'Login successful, 2FA required',
                    'data' => [
                        'user' => [
                            'id' => $user['id'],
                            'email' => $user['email'],
                            'first_name' => $user['first_name'] ?? null,
                            'last_name' => $user['last_name'] ?? null,
                            'name' => ($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''),
                            'phone' => $user['phone'] ?? null,
                            'job_title' => $user['job_title'] ?? null,
                            'status' => $user['status'] ?? 'active',
                            'additional_info' => $user['additional_info'] ?? null,
                            'avatar_url' => $user['avatar_url'] ?? null,
                            'two_factor_enabled' => true,
                            'last_login' => $user['last_login'] ?? null,
                            'role_id' => $user['role_id'] ?? null,
                            'role_code' => $user['role_code'] ?? null,
                            'role_name' => $user['role_name'] ?? null,
                            'role_category' => $user['role_category'] ?? null,
                            'role_description' => $user['role_description'] ?? null
                        ],
                        'requires_2fa' => true,
                        'token' => null,
                        'expires_at' => null
                    ]
                ]);
                return;
            }

            // If 2FA is not enabled, proceed with normal login
            $token = $this->generateToken($user);

            // Логируем успешный вход в аудит
            $this->userAuditService->logLogin($user['id'], true);

            $this->logger->info('Successful login (no 2FA)', [
                'user_id' => $user['id'],
                'email' => $email,
                'ip' => Flight::request()->ip
            ]);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Login successful',
                'data' => [
                    'user' => [
                        'id' => $user['id'],
                        'email' => $user['email'],
                        'first_name' => $user['first_name'] ?? null,
                        'last_name' => $user['last_name'] ?? null,
                        'name' => ($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''),
                        'phone' => $user['phone'] ?? null,
                        'job_title' => $user['job_title'] ?? null,
                        'status' => $user['status'] ?? 'active',
                        'additional_info' => $user['additional_info'] ?? null,
                        'avatar_url' => $user['avatar_url'] ?? null,
                        'two_factor_enabled' => false,
                        'last_login' => $user['last_login'] ?? null,
                        'role_id' => $user['role_id'] ?? null,
                        'role_code' => $user['role_code'] ?? null,
                        'role_name' => $user['role_name'] ?? null,
                        'role_category' => $user['role_category'] ?? null,
                        'role_description' => $user['role_description'] ?? null
                    ],
                    'requires_2fa' => false,
                    'token' => $token,
                    'expires_at' => date('c', time() + 86400) // 24 hours from now
                ]
            ]);

        } catch (\Exception $e) {
            error_log('=== LOGIN METHOD ERROR ===');
            error_log('Error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Internal server error',
                'data' => null,
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Validate login input data
     */
    private function validateLoginData(array $data): bool
    {
        // Regular login requires email and password
        if (empty($data['email']) || empty($data['password'])) {
            return false;
        }
        
        // Basic email validation
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        
        return true;
    }

    /**
     * Authenticate user with database
     */
    private function authenticateUser(string $email, string $password): ?array
    {
        try {
            error_log('=== AUTHENTICATE USER START ===');
            
            $connection = Database::getConnection();
            error_log('Database connection OK');
            
            // Используем таблицу fw_v_users с правильными колонками
            $sql = "SELECT id, email, password_hash, first_name, last_name, phone, role_id, job_title, status, status_reason, status_details, additional_info, avatar_url, two_factor_enabled, two_factor_secret, last_login, status_changed_at, status_end_at, dob, gender, nationality, country_of_origin, workforce_group, city, emergency, created_at, updated_at, invitation_status, invitation_token, invitation_sent_at, invitation_expires_at, invited_by, registration_completed_at, invitation_attempts, last_reminder_sent_at, archived_at, role_code, role_name, role_category, role_description FROM fw_v_users WHERE email = ? LIMIT 1";
            error_log('SQL: ' . $sql);
            error_log('Email: ' . $email);
            
            // Правильный способ выполнения запроса в Doctrine DBAL
            $result = $connection->executeQuery($sql, [$email]);
            error_log('Query executed OK');
            
            $user = $result->fetchAssociative();
            error_log('User data: ' . print_r($user, true));

            if (!$user) {
                error_log('User not found');
                return null;
            }

            // Проверяем, не архивирован ли пользователь
            if ($user['archived_at'] !== null) {
                error_log('User is archived');
                return ['error' => 'archived'];
            }

            error_log('User found, checking password');
            
            // Проверяем пароль - используем password_hash
            if (password_verify($password, $user['password_hash'])) {
                error_log('Password verified OK');
                return $user;
            }

            error_log('Password verification failed');
            return null;

        } catch (\Exception $e) {
            error_log('=== AUTHENTICATE USER ERROR ===');
            error_log('Error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * Authenticate user with invitation code
     */
    private function checkInvitationToken(string $invitationToken): bool
    {
        try {
            error_log('=== VALIDATE INVITATION TOKEN START ===');
            
            $connection = Database::getConnection();
            error_log('Database connection OK');
            
            // Check if token exists
            $sql = "SELECT invitation_expires_at FROM fw_v_users WHERE invitation_token = ? AND invitation_status = 'invited' LIMIT 1";
            error_log('SQL: ' . $sql);
            error_log('Invitation token: ' . $invitationToken);
            
            $result = $connection->executeQuery($sql, [$invitationToken]);
            error_log('Query executed OK');
            
            $row = $result->fetchAssociative();
            error_log('Token data: ' . print_r($row, true));

            if (!$row) {
                error_log('Token does not exist');
                return false;
            }

            // Check if invitation is expired
            $expiresAt = $row['invitation_expires_at'];
            if ($expiresAt && strtotime($expiresAt) < time()) {
                error_log('Token expired');
                return false;
            }

            error_log('Token is valid');
            return true;

        } catch (\Exception $e) {
            error_log('=== VALIDATE INVITATION TOKEN ERROR ===');
            error_log('Error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            return false;
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/auth/change-password",
     *     summary="Change password for invited user",
     *     description="Change password for users with 'invited' status. Updates password and changes status to 'registered'.",
     *     tags={"Authentication"},
     *     @OA\RequestBody(
     *         required=true,
     *         description="New password",
     *         @OA\JsonContent(
     *             required={"new_password"},
     *             @OA\Property(property="new_password", type="string", example="newpassword123", description="New password (minimum 8 characters)")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Password changed successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Password changed successfully"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request - validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=400),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="New password is required"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=500),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Internal server error"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     )
     * )
     */
    public function changePassword(): void
    {
        try {
            $data = json_decode(Flight::request()->getBody(), true);
            
            if (!$data || !isset($data['new_password'])) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'New password is required',
                    'data' => null
                ], 400);
                return;
            }
            
            $newPassword = $data['new_password'];
            
            // Validate password strength
            if (strlen($newPassword) < 8) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Password must be at least 8 characters long',
                    'data' => null
                ], 400);
                return;
            }
            
            $connection = Database::getConnection();
            
            // Find user by invitation status (only invited users can change password this way)
            $sql = "SELECT id, email FROM fw_v_users WHERE invitation_status = 'invited' LIMIT 1";
            $result = $connection->executeQuery($sql);
            $user = $result->fetchAssociative();
            
            if (!$user) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'No invited user found',
                    'data' => null
                ], 400);
                return;
            }
            
            // Start transaction
            $connection->beginTransaction();
            
            try {
                // Hash new password
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                
                // Update user: set new password, change status to registered, clear invitation fields
                $updateSql = "UPDATE fw_users SET 
                                password_hash = ?,
                                invitation_status = 'registered',
                                invitation_token = NULL,
                                invitation_sent_at = NULL,
                                invitation_expires_at = NULL,
                                invited_by = NULL,
                                last_login = NOW()
                              WHERE id = ?";
                
                $connection->executeStatement($updateSql, [$hashedPassword, $user['id']]);
                
                // Commit transaction
                $connection->commit();
                
                $this->logger->info('Password changed for invited user', [
                    'user_id' => $user['id'],
                    'email' => $user['email'],
                    'ip' => Flight::request()->ip
                ]);
                
                Flight::json([
                    'error_code' => 0,
                    'status' => 'success',
                    'message' => 'Password changed successfully',
                    'data' => null
                ]);
                
            } catch (\Exception $e) {
                // Rollback transaction on error
                $connection->rollBack();
                throw $e;
            }
            
        } catch (\Exception $e) {
            error_log('Change password error: ' . $e->getMessage());
            
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Internal server error',
                'data' => null
            ], 500);
        }
    }

    /**
     * Generate JWT token for user
     */
    private function generateToken(array $user): string
    {
        // For now, return a simple token
        // In production, you should use proper JWT library
        $payload = [
            'user_id' => $user['id'],
            'email' => $user['email'],
            'exp' => time() + 86400
        ];
        
        return base64_encode(json_encode($payload));
    }

    /**
     * @OA\Get(
     *     path="/api/v1/auth/validate-invitation-token",
     *     summary="Validate invitation token",
     *     description="Check if invitation token is valid and not expired. Returns only validation status without user data.",
     *     tags={"Authentication"},
     *     @OA\Parameter(
     *         name="token",
     *         in="query",
     *         required=true,
     *         description="Invitation token from email",
     *         @OA\Schema(type="string", example="abc123def456")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Token is valid",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Token is valid"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid or expired token",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=400),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Invalid invitation token"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=500),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Internal server error"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     )
     * )
     */
    public function validateInvitationToken(): void
    {
        try {
            $token = Flight::request()->query['token'] ?? '';
            
            if (empty($token)) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Invitation token is required',
                    'data' => null
                ], 400);
                return;
            }
            
            $connection = Database::getConnection();
            
            // Check if token exists and is valid
            $sql = "SELECT invitation_expires_at 
                    FROM fw_v_users 
                    WHERE invitation_token = ? AND invitation_status = 'invited' 
                    LIMIT 1";
            
            $result = $connection->executeQuery($sql, [$token]);
            $user = $result->fetchAssociative();
            
            if (!$user) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Invalid invitation token',
                    'data' => null
                ], 400);
                return;
            }
            
            // Check if token is expired
            $expiresAt = $user['invitation_expires_at'];
            if ($expiresAt && strtotime($expiresAt) < time()) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Invitation token has expired',
                    'data' => null
                ], 400);
                return;
            }
            
            // Token is valid
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Token is valid',
                'data' => null
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Token validation failed', [
                'error' => $e->getMessage(),
                'token' => $token ?? 'not provided'
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
     * @OA\Post(
     *     path="/api/v1/auth/logout",
     *     summary="User logout",
     *     description="Logout user and invalidate session",
     *     tags={"Authentication"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Logout successful",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Logout successful"),
     *             @OA\Property(property="data", type="null")
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
     *     )
     * )
     */
    public function logout(): void
    {
        try {
            // Get user from Flight context (set by middleware)
            $user = Flight::get('current_user');
            
            if (!$user) {
                Flight::json([
                    'error_code' => 401,
                    'status' => 'error',
                    'message' => 'User not authenticated',
                    'data' => null
                ], 401);
                return;
            }

            // Calculate session duration
            $sessionDuration = $this->calculateSessionDuration($user['id']);

            // Log logout in audit
            $this->userAuditService->logLogout($user['id'], $sessionDuration);

            $this->logger->info('User logout', [
                'user_id' => $user['id'],
                'email' => $user['email'],
                'ip' => Flight::request()->ip,
                'session_duration' => $sessionDuration
            ]);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Logout successful',
                'data' => null
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Logout error', [
                'error' => $e->getMessage(),
                'ip' => Flight::request()->ip
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
     * @OA\Post(
     *     path="/api/v1/auth/check-session",
     *     summary="Check session validity",
     *     description="Check if user session is still valid and update last activity",
     *     tags={"Authentication"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Session is valid",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Session is valid"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="user", type="object"),
     *                 @OA\Property(property="session_valid", type="boolean", example=true),
     *                 @OA\Property(property="last_activity", type="string", example="2024-01-15T10:30:00Z")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Session expired or invalid",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=401),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Session expired"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     )
     * )
     */
    public function checkSession(): void
    {
        try {
            $user = $this->getUserFromToken();
            
            if (!$user) {
                Flight::json([
                    'error_code' => 401,
                    'status' => 'error',
                    'message' => 'Session expired or invalid',
                    'data' => null
                ], 401);
                return;
            }

            // Update last activity
            $this->updateLastActivity($user['id']);

            // Log API call for audit
            $this->userAuditService->logUserAction(
                userId: $user['id'],
                actionType: 'api_call',
                metadata: ['endpoint' => 'check-session']
            );

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Session is valid',
                'data' => [
                    'user' => [
                        'id' => $user['id'],
                        'email' => $user['email'],
                        'first_name' => $user['first_name'] ?? null,
                        'last_name' => $user['last_name'] ?? null
                    ],
                    'session_valid' => true,
                    'last_activity' => date('c')
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Session check error', [
                'error' => $e->getMessage(),
                'ip' => Flight::request()->ip
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
     * Get user from JWT token
     */
    private function getUserFromToken(): ?array
    {
        try {
            $authHeader = Flight::request()->getHeader('Authorization');
            
            if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
                return null;
            }

            $token = substr($authHeader, 7); // Remove 'Bearer ' prefix
            
            // Decode token (simplified - in production use proper JWT library)
            $payload = json_decode(base64_decode($token), true);
            
            if (!$payload || !isset($payload['user_id']) || !isset($payload['exp'])) {
                return null;
            }

            // Check if token is expired
            if ($payload['exp'] < time()) {
                return null;
            }

            // Get user from database
            $connection = Database::getConnection();
            $sql = "SELECT id, email, first_name, last_name, status, archived_at FROM fw_v_users WHERE id = ? LIMIT 1";
            $result = $connection->executeQuery($sql, [$payload['user_id']]);
            $user = $result->fetchAssociative();

            if (!$user || $user['archived_at'] !== null) {
                return null;
            }

            return $user;

        } catch (\Exception $e) {
            $this->logger->error('Token validation error', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Calculate session duration in seconds
     */
    private function calculateSessionDuration(int $userId): ?int
    {
        try {
            $connection = Database::getConnection();
            
            // Get last login time
            $sql = "SELECT created_at FROM fw_user_audit_log 
                    WHERE user_id = ? AND action_type = 'login' AND success = 1 
                    ORDER BY created_at DESC LIMIT 1";
            
            $result = $connection->executeQuery($sql, [$userId]);
            $loginRecord = $result->fetchAssociative();
            
            if (!$loginRecord) {
                return null;
            }

            $loginTime = strtotime($loginRecord['created_at']);
            return time() - $loginTime;

        } catch (\Exception $e) {
            $this->logger->error('Failed to calculate session duration', [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
            return null;
        }
    }

    /**
     * Update user's last activity timestamp
     */
    private function updateLastActivity(int $userId): void
    {
        try {
            // Log the activity in audit
            $this->userAuditService->logUserAction(
                userId: $userId,
                actionType: 'api_call',
                metadata: ['activity_check' => true]
            );

        } catch (\Exception $e) {
            $this->logger->error('Failed to update last activity', [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
        }
    }

}
