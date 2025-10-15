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
     * Decode JWT token with HMAC verification
     */
    private function decodeJWT(string $token): ?array
    {
        try {
            // Split JWT into parts
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }
            
            [$header, $payload, $signature] = $parts;
            
            // Decode header and payload
            $header = str_replace(['-', '_'], ['+', '/'], $header);
            $payload = str_replace(['-', '_'], ['+', '/'], $payload);
            
            // Add padding if needed
            $header = str_pad($header, strlen($header) % 4, '=', STR_PAD_RIGHT);
            $payload = str_pad($payload, strlen($payload) % 4, '=', STR_PAD_RIGHT);
            
            $headerDecoded = base64_decode($header);
            $payloadDecoded = base64_decode($payload);
            
            if ($headerDecoded === false || $payloadDecoded === false) {
                return null;
            }
            
            $headerData = json_decode($headerDecoded, true);
            $payloadData = json_decode($payloadDecoded, true);
            
            if (!$headerData || !$payloadData) {
                return null;
            }
            
            // Verify signature
            $secret = $_ENV['JWT_SECRET'] ?? 'your-secret-key-change-in-production';
            $expectedSignature = hash_hmac('sha256', $header . "." . $payload, $secret, true);
            $expectedSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($expectedSignature));
            
            if (!hash_equals($expectedSignature, $signature)) {
                return null;
            }
            
            // Check expiration
            if (!isset($payloadData['exp']) || $payloadData['exp'] < time()) {
                return null;
            }
            
            return $payloadData;
        } catch (\Exception $e) {
            $this->logger->error('JWT decode error', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get user by ID
     */
    private function getUserById(int $userId): ?array
    {
        try {
            $connection = Database::getConnection();
            
            $sql = 'SELECT id, email, first_name, last_name, phone, job_title, status, 
                           additional_info, avatar_url, two_factor_enabled, last_login, created_at, updated_at 
                    FROM fw_v_users 
                    WHERE id = ? AND archived_at IS NULL';
            
            $result = $connection->executeQuery($sql, [$userId]);
            $user = $result->fetchAssociative();

            return $user ?: null;
        } catch (\Exception $e) {
            $this->logger->error('Error getting user by ID', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
