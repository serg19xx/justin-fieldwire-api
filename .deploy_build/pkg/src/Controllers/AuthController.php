<?php

namespace App\Controllers;

use App\Database\Database;
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
    private Database $database;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        
        try {
            $this->database = new Database();
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize AuthController database', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * @OA\Post(
     *     path="/auth/login",
     *     summary="User login",
     *     description="Authenticate user with email and password",
     *     tags={"Authentication"},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Login credentials",
     *         @OA\JsonContent(
     *             required={"email","password"},
     *             @OA\Property(property="email", type="string", format="email", example="user@example.com", description="User email address"),
     *             @OA\Property(property="password", type="string", example="password123", description="User password")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Login successful",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Login successful"),
     *             @OA\Property(property="token", type="string", example="eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...", description="JWT authentication token"),
     *             @OA\Property(property="user", type="object", 
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="email", type="string", example="user@example.com"),
     *                 @OA\Property(property="name", type="string", example="John Doe")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request - validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Validation failed"),
     *             @OA\Property(property="errors", type="array", @OA\Items(type="string"))
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Invalid credentials",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Invalid email or password")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Internal server error")
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

            // Authenticate user
            error_log('Calling authenticateUser');
            $user = $this->authenticateUser($email, $password);
            error_log('authenticateUser returned: ' . print_r($user, true));

            if (!$user) {
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
                            'user_type' => $user['user_type'] ?? null,
                            'job_title' => $user['job_title'] ?? null,
                            'status' => $user['status'] ?? 'active',
                            'additional_info' => $user['additional_info'] ?? null,
                            'avatar_url' => $user['avatar_url'] ?? null,
                            'two_factor_enabled' => true,
                            'last_login' => $user['last_login'] ?? null
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
                        'user_type' => $user['user_type'] ?? null,
                        'job_title' => $user['job_title'] ?? null,
                        'status' => $user['status'] ?? 'active',
                        'additional_info' => $user['additional_info'] ?? null,
                        'avatar_url' => $user['avatar_url'] ?? null,
                        'two_factor_enabled' => false,
                        'last_login' => $user['last_login'] ?? null
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
            
            $connection = $this->database->getConnection();
            error_log('Database connection OK');
            
            // Правильный SQL
            $sql = "SELECT * FROM fw_users WHERE email = ? LIMIT 1";
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

            error_log('User found, checking password');
            
            // ИСПРАВЛЯЕМ: используем правильное название колонки password_hash
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
}
