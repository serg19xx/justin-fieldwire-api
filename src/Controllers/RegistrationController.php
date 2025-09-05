<?php

namespace App\Controllers;

use App\Database\Database;
use Doctrine\DBAL\Exception;
use Flight;
use Monolog\Logger;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Registration",
 *     description="User registration and invitation system endpoints"
 * )
 */
class RegistrationController
{
    private Logger $logger;
    private Database $database;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        
        try {
            $this->database = new Database();
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize RegistrationController database', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Проверить валидность токена приглашения
     * GET /api/v1/registration/validate/{token}
     *
     * @OA\Get(
     *     path="/api/v1/registration/validate/{token}",
     *     summary="Validate invitation token",
     *     description="Check if invitation token is valid and not expired",
     *     tags={"Registration"},
     *     @OA\Parameter(
     *         name="token",
     *         in="path",
     *         description="Invitation token",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Token validation result",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Token is valid"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="valid", type="boolean", example=true),
     *                 @OA\Property(property="user", type="object",
     *                     @OA\Property(property="email", type="string", example="worker@example.com"),
     *                     @OA\Property(property="first_name", type="string", example="John"),
     *                     @OA\Property(property="last_name", type="string", example="Doe"),
     *                     @OA\Property(property="user_type", type="string", example="Employee"),
     *                     @OA\Property(property="job_title", type="string", example="Developer")
     *                 ),
     *                 @OA\Property(property="expires_at", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid or expired token",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=400),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Invalid or expired invitation token")
     *         )
     *     )
     * )
     */
    public function validateToken($token = null): void
    {
        if (!$token) {
            Flight::json([
                'error_code' => 400,
                'status' => 'error',
                'message' => 'Token is required',
                'data' => null
            ], 400);
            return;
        }

        try {
            $connection = $this->database->getConnection();
            
            $sql = "SELECT email, first_name, last_name, user_type, job_title, 
                           invitation_status, invitation_expires_at
                    FROM fw_users 
                    WHERE invitation_token = ? AND invitation_status = 'invited'";
            
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

            // Проверяем, не истек ли токен
            if (strtotime($user['invitation_expires_at']) < time()) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Invitation token has expired',
                    'data' => null
                ], 400);
                return;
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Token is valid',
                'data' => [
                    'valid' => true,
                    'user' => [
                        'email' => $user['email'],
                        'first_name' => $user['first_name'],
                        'last_name' => $user['last_name'],
                        'user_type' => $user['user_type'],
                        'job_title' => $user['job_title']
                    ],
                    'expires_at' => $user['invitation_expires_at']
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error validating token: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to validate token',
                'data' => null
            ], 500);
        }
    }

    /**
     * Завершить регистрацию по приглашению
     * POST /api/v1/registration/complete
     *
     * @OA\Post(
     *     path="/api/v1/registration/complete",
     *     summary="Complete registration with invitation",
     *     description="Complete user registration using invitation token and set password",
     *     tags={"Registration"},
     *     @OA\RequestBody(
     *         required=true,
     *         description="Registration completion data",
     *         @OA\JsonContent(
     *             required={"token", "password"},
     *             @OA\Property(property="token", type="string", example="abc123...", description="Invitation token"),
     *             @OA\Property(property="password", type="string", example="newpassword123", description="New password"),
     *             @OA\Property(property="phone", type="string", example="+1234567890", description="Phone number (optional)")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Registration completed successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Registration completed successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="user", type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="email", type="string", example="worker@example.com"),
     *                     @OA\Property(property="first_name", type="string", example="John"),
     *                     @OA\Property(property="last_name", type="string", example="Doe"),
     *                     @OA\Property(property="user_type", type="string", example="Employee"),
     *                     @OA\Property(property="job_title", type="string", example="Developer")
     *                 ),
     *                 @OA\Property(property="token", type="string", example="eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."),
     *                 @OA\Property(property="expires_at", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid token or password",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=400),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Invalid or expired invitation token")
     *         )
     *     )
     * )
     */
    public function completeRegistration(): void
    {
        try {
            $data = Flight::request()->data;

            // Валидация обязательных полей
            if (empty($data->token) || empty($data->password)) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Token and password are required',
                    'data' => null
                ], 400);
                return;
            }

            $token = $data->token;
            $password = $data->password;
            $phone = $data->phone ?? null;

            // Валидация пароля
            if (strlen($password) < 8) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Password must be at least 8 characters long',
                    'data' => null
                ], 400);
                return;
            }

            $connection = $this->database->getConnection();
            
            // Проверяем токен и получаем данные пользователя
            $sql = "SELECT id, email, first_name, last_name, user_type, job_title, 
                           invitation_status, invitation_expires_at
                    FROM fw_users 
                    WHERE invitation_token = ? AND invitation_status = 'invited'";
            
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

            // Проверяем, не истек ли токен
            if (strtotime($user['invitation_expires_at']) < time()) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Invitation token has expired',
                    'data' => null
                ], 400);
                return;
            }

            // Хешируем пароль
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            // Обновляем пользователя
            $updateSql = "UPDATE fw_users SET 
                            password_hash = ?, 
                            phone = ?,
                            invitation_status = 'registered',
                            invitation_token = NULL,
                            registration_completed_at = NOW(),
                            status = 1,
                            updated_at = NOW()
                          WHERE id = ?";
            
            $connection->executeStatement($updateSql, [
                $passwordHash, 
                $phone, 
                $user['id']
            ]);

            // Генерируем JWT токен
            $jwtToken = $this->generateToken([
                'user_id' => $user['id'],
                'email' => $user['email'],
                'exp' => time() + 86400 // 24 часа
            ]);

            $this->logger->info('Registration completed', [
                'user_id' => $user['id'],
                'email' => $user['email']
            ]);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Registration completed successfully',
                'data' => [
                    'user' => [
                        'id' => (int)$user['id'],
                        'email' => $user['email'],
                        'first_name' => $user['first_name'],
                        'last_name' => $user['last_name'],
                        'user_type' => $user['user_type'],
                        'job_title' => $user['job_title']
                    ],
                    'token' => $jwtToken,
                    'expires_at' => date('c', time() + 86400)
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error completing registration: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to complete registration',
                'data' => null
            ], 500);
        }
    }

    /**
     * Генерация JWT токена
     */
    private function generateToken(array $payload): string
    {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode($payload);
        
        $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
        
        $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, $_ENV['JWT_SECRET'] ?? 'default-secret', true);
        $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        
        return $base64Header . "." . $base64Payload . "." . $base64Signature;
    }
}
