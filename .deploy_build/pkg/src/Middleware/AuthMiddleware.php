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
        $this->logger->info('AuthMiddleware::handle called');
        
        $headers = getallheaders();
        $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        
        $this->logger->info('Authorization header', ['auth' => $authorization ? 'present' : 'missing']);

        if (empty($authorization) || !str_starts_with($authorization, 'Bearer ')) {
            $this->logger->warning('Missing or invalid Authorization header');
            Flight::json([
                'error_code' => 401,
                'status' => 'error',
                'message' => 'Authorization header required',
                'data' => null
            ], 401);
            return false;
        }

        $token = substr($authorization, 7);
        $this->logger->info('Token extracted', [
            'token_length' => strlen($token),
            'token_preview' => substr($token, 0, 20) . '...' . substr($token, -20),
            'has_dots' => substr_count($token, '.')
        ]);
        
        try {
            $this->logger->info('Attempting to decode JWT');
            $payload = $this->decodeJWT($token);
            $this->logger->info('decodeJWT returned', ['payload' => $payload ? 'valid' : 'null']);
            if (!$payload) {
                $this->logger->warning('Payload is null, returning 401');
                Flight::json([
                    'error_code' => 401,
                    'status' => 'error',
                    'message' => 'Invalid or expired token',
                    'data' => null
                ], 401);
                return false;
            }

            // Get user from database
            $this->logger->info('Getting user from database', ['user_id' => $payload['user_id']]);
            $user = $this->getUserById($payload['user_id']);
            $this->logger->info('User retrieved', ['user' => $user ? 'found' : 'not found']);
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
            $this->logger->info('Auth successful, returning true', ['user_id' => $user['id']]);
            
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
            $this->logger->info('decodeJWT: Starting', ['token_length' => strlen($token)]);
            
            // Split JWT into parts
            $parts = explode('.', $token);
            $this->logger->info('decodeJWT: Parts count', ['count' => count($parts)]);
            
            if (count($parts) !== 3) {
                $this->logger->warning('decodeJWT: Invalid parts count');
                return null;
            }
            
            [$headerEncoded, $payloadEncoded, $signature] = $parts;
            
            // Decode header and payload
            $header = str_replace(['-', '_'], ['+', '/'], $headerEncoded);
            $payload = str_replace(['-', '_'], ['+', '/'], $payloadEncoded);
            
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
            
            // Verify signature using original encoded parts
            $secret = $_ENV['JWT_SECRET'] ?? 'your-secret-key-change-in-production';
            $expectedSignature = hash_hmac('sha256', $headerEncoded . "." . $payloadEncoded, $secret, true);
            $expectedSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($expectedSignature));
            
            $this->logger->info('JWT signature verification', [
                'expected' => $expectedSignature,
                'received' => $signature,
                'match' => hash_equals($expectedSignature, $signature)
            ]);
            
            if (!hash_equals($expectedSignature, $signature)) {
                $this->logger->warning('JWT signature mismatch');
                return null;
            }
            
            // Check expiration
            $currentTime = time();
            $this->logger->info('JWT expiration check', [
                'exp' => $payloadData['exp'] ?? 'not set',
                'current_time' => $currentTime,
                'is_expired' => !isset($payloadData['exp']) || $payloadData['exp'] < $currentTime
            ]);
            
            if (!isset($payloadData['exp']) || $payloadData['exp'] < $currentTime) {
                $this->logger->warning('JWT token expired or invalid exp');
                return null;
            }
            
            $this->logger->info('JWT decoded successfully', ['user_id' => $payloadData['user_id'] ?? 'unknown']);
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
