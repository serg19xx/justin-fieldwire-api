<?php

namespace App\Middleware;

use App\Database\Database;
use App\Support\JwtToken;
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

            // Get user from database — or contractor temporary access session
            if (($payload['typ'] ?? null) === 'contractor_access') {
                $contractorUser = $this->resolveContractorAccessUser($payload);
                if ($contractorUser === null) {
                    Flight::json([
                        'error_code' => 401,
                        'status' => 'error',
                        'message' => 'Invalid or expired contractor access',
                        'data' => null
                    ], 401);
                    return false;
                }
                Flight::set('current_user', $contractorUser);
                $this->logger->info('Contractor access auth successful', [
                    'task_id' => $contractorUser['task_id'] ?? null,
                    'access_key_id' => $contractorUser['access_key_id'] ?? null,
                ]);

                return true;
            }

            $this->logger->info('Getting user from database', ['user_id' => $payload['user_id'] ?? null]);
            if (!isset($payload['user_id'])) {
                Flight::json([
                    'error_code' => 401,
                    'status' => 'error',
                    'message' => 'Invalid or expired token',
                    'data' => null
                ], 401);
                return false;
            }
            $user = $this->getUserById((int) $payload['user_id']);
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
            $this->renewTokenIfAging($payload, $user);
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
     * Decode JWT token; null when the signature does not match or the token has expired.
     */
    private function decodeJWT(string $token): ?array
    {
        try {
            $payload = JwtToken::decode($token);
            if ($payload === null) {
                $this->logger->warning('JWT rejected: bad signature, malformed or expired');
            }

            return $payload;
        } catch (\Throwable $e) {
            $this->logger->error('JWT decode error', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Sliding session: hand an active user a fresh token so they are not logged out mid-week.
     */
    private function renewTokenIfAging(array $payload, array $user): void
    {
        $issuedAt = (int) ($payload['iat'] ?? 0);
        if ($issuedAt > 0 && time() - $issuedAt < JwtToken::RENEW_AFTER_SECONDS) {
            return;
        }

        try {
            header(JwtToken::RENEWED_TOKEN_HEADER . ': ' . JwtToken::issueAccessToken($user));
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to renew access token', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Resolve temporary contractor session from JWT claims; re-check key still active.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function resolveContractorAccessUser(array $payload): ?array
    {
        $accessKeyId = isset($payload['access_key_id']) ? (int) $payload['access_key_id'] : 0;
        $taskId = isset($payload['task_id']) ? (int) $payload['task_id'] : 0;
        $projectId = isset($payload['project_id']) ? (int) $payload['project_id'] : 0;
        if ($accessKeyId <= 0 || $taskId <= 0 || $projectId <= 0) {
            return null;
        }

        // Prefer hard expiry for contractor sessions
        if (isset($payload['exp']) && (int) $payload['exp'] < time()) {
            return null;
        }

        try {
            $connection = Database::getConnection();
            $row = $connection->executeQuery(
                'SELECT id, task_id, project_id, contractor_id, expires_at, revoked_at
                 FROM fw_task_access_keys WHERE id = ? LIMIT 1',
                [$accessKeyId]
            )->fetchAssociative();
            if (!$row) {
                return null;
            }
            if ($row['revoked_at'] !== null) {
                return null;
            }
            if (strtotime((string) $row['expires_at']) < time()) {
                return null;
            }
            if ((int) $row['task_id'] !== $taskId || (int) $row['project_id'] !== $projectId) {
                return null;
            }

            return [
                'id' => 0,
                'auth_type' => 'contractor_access',
                'role_code' => 'contractor_access',
                'role_category' => 'contractor_access',
                'email' => null,
                'name' => 'Contractor',
                'first_name' => 'Contractor',
                'last_name' => '',
                'task_id' => $taskId,
                'project_id' => $projectId,
                'contractor_id' => $row['contractor_id'] !== null ? (int) $row['contractor_id'] : null,
                'access_key_id' => $accessKeyId,
                'key_expires_at' => $row['expires_at'],
                'status' => 1,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Error resolving contractor access', ['error' => $e->getMessage()]);

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
                           role_code, role_name,
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
