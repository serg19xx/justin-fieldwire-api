<?php

namespace App\Middleware;

use App\Database\Database;
use Doctrine\DBAL\Exception;
use Flight;
use Monolog\Logger;

class AuthMiddleware
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Verify JWT token and set user context
     */
    public function handle(): bool
    {
        $headers = getallheaders();
        $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (empty($authorization) || !str_starts_with($authorization, 'Bearer ')) {
            Flight::json([
                'error_code' => 401,
                'status' => 'error',
                'message' => 'Authorization header required',
                'data' => null
            ], 401);
            return false;
        }

        $token = substr($authorization, 7);
        
        try {
            $payload = $this->decodeJWT($token);
            if (!$payload) {
                Flight::json([
                    'error_code' => 401,
                    'status' => 'error',
                    'message' => 'Invalid or expired token',
                    'data' => null
                ], 401);
                return false;
            }

            // Get user from database
            $user = $this->getUserById($payload['user_id']);
            if (!$user) {
                Flight::json([
                    'error_code' => 401,
                    'status' => 'error',
                    'message' => 'User not found',
                    'data' => null
                ], 401);
                return false;
            }

            // Set user context for the request
            Flight::set('current_user', $user);
            
            return true;

        } catch (Exception $e) {
            $this->logger->error('Error in auth middleware', [
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Internal server error',
                'data' => null
            ], 500);
            return false;
        }
    }

    /**
     * Decode JWT token
     */
    private function decodeJWT(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
        
        if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    /**
     * Get user by ID
     */
    private function getUserById(int $userId): ?array
    {
        $connection = Database::getConnection();
        
        $sql = 'SELECT id, email, first_name, last_name, phone, user_type, job_title, status, 
                       additional_info, avatar_url, two_factor_enabled, last_login, created_at, updated_at 
                FROM fw_users 
                WHERE id = ? AND status = "active"';
        
        $result = $connection->executeQuery($sql, [$userId]);
        $user = $result->fetchAssociative();

        return $user ?: null;
    }
}
