<?php

namespace App\Controllers;

use App\Database\Database;
use Doctrine\DBAL\Exception;
use Flight;
use Monolog\Logger;

class AuthController
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Handle user login
     */
    public function login(): void
    {
        try {
            // Get request body
            $requestBody = Flight::request()->getBody();
            $data = json_decode($requestBody, true);
            
            // Log incoming request data (simplified)
            error_log('Login attempt - Request body: ' . $requestBody);
            error_log('Login attempt - Parsed data: ' . print_r($data, true));
            error_log('Login attempt - Email: ' . ($data['email'] ?? 'NOT_SET'));
            error_log('Login attempt - Password: ' . (isset($data['password']) ? 'SET' : 'NOT_SET'));

            // Validate input
            if (!$this->validateLoginData($data)) {
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

            $email = $data['email'];
            $password = $data['password'];

            // Authenticate user
            $user = $this->authenticateUser($email, $password);

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
                    'expires_at' => date('c', time() + 3600) // 1 hour from now
                ]
            ]);

        } catch (Exception $e) {
            error_log('Database error during login: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Internal server error',
                'data' => null,
                'details' => $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            error_log('Unexpected error during login: ' . $e->getMessage());
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
     * Validate login data
     */
    private function validateLoginData(?array $data): bool
    {
        if (!$data || !is_array($data)) {
            return false;
        }

        if (!isset($data['email']) || !isset($data['password'])) {
            return false;
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if (empty($data['password'])) {
            return false;
        }

        return true;
    }

    /**
     * Authenticate user against database
     */
    private function authenticateUser(string $email, string $password): ?array
    {
        try {
            $connection = Database::getConnection();

            // Get user by email
            $sql = 'SELECT id, email, password_hash, first_name, last_name, phone, user_type, job_title, status, 
                           additional_info, avatar_url, two_factor_enabled, last_login, created_at, updated_at 
                    FROM fw_users 
                    WHERE email = ? AND status = "active"';
            $result = $connection->executeQuery($sql, [$email]);
            $user = $result->fetchAssociative();

            if (!$user) {
                return null;
            }

            // Verify password
            if (!password_verify($password, $user['password_hash'])) {
                return null;
            }

            // Remove password from response
            unset($user['password_hash']);

            return $user;

        } catch (Exception $e) {
            $this->logger->error('Database error during authentication', [
                'error' => $e->getMessage(),
                'email' => $email
            ]);
            throw $e;
        }
    }



    /**
     * Generate JWT token (simplified implementation)
     */
    private function generateToken(array $user): string
    {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode([
            'user_id' => $user['id'],
            'email' => $user['email'],
            'name' => ($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''),
            'user_type' => $user['user_type'] ?? 'user',
            'iat' => time(),
            'exp' => time() + 3600 // 1 hour
        ]);

        $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));

        // In production, use a proper secret key from environment
        $secret = $_ENV['JWT_SECRET'] ?? 'your-secret-key-change-in-production';
        $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, $secret, true);
        $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

        return $base64Header . "." . $base64Payload . "." . $base64Signature;
    }
}
