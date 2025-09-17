<?php

namespace App\Services;

use App\Database\Database;
use Doctrine\DBAL\Connection;
use Monolog\Logger;

class UserAuditService
{
    private Connection $connection;
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->connection = Database::getConnection();
        $this->logger = $logger;
    }

    /**
     * Log user action for audit purposes
     */
    public function logUserAction(
        ?int $userId,
        string $actionType,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $sessionId = null,
        ?int $durationSeconds = null,
        bool $success = true,
        ?string $errorMessage = null,
        ?array $metadata = null
    ): int {
        try {
            $this->connection->insert('fw_user_audit_log', [
                'user_id' => $userId,
                'action_type' => $actionType,
                'ip_address' => $ipAddress ?? $this->getClientIp(),
                'user_agent' => $userAgent ?? $this->getUserAgent(),
                'session_id' => $sessionId ?? session_id(),
                'duration_seconds' => $durationSeconds,
                'success' => $success,
                'error_message' => $errorMessage,
                'metadata' => $metadata ? json_encode($metadata) : null,
                'created_at' => (new \DateTime())->format('Y-m-d H:i:s')
            ]);

            $auditLogId = (int)$this->connection->lastInsertId();

            $this->logger->info('User action logged', [
                'audit_log_id' => $auditLogId,
                'user_id' => $userId,
                'action_type' => $actionType,
                'success' => $success
            ]);

            return $auditLogId;

        } catch (\Exception $e) {
            $this->logger->error('Failed to log user action', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'action_type' => $actionType
            ]);
            throw $e;
        }
    }

    /**
     * Log user login
     */
    public function logLogin(int $userId, bool $success = true, ?string $errorMessage = null): int
    {
        return $this->logUserAction(
            userId: $userId,
            actionType: $success ? 'login' : 'login_failed',
            success: $success,
            errorMessage: $errorMessage
        );
    }

    /**
     * Log user logout
     */
    public function logLogout(int $userId, ?int $sessionDurationSeconds = null): int
    {
        return $this->logUserAction(
            userId: $userId,
            actionType: 'logout',
            durationSeconds: $sessionDurationSeconds
        );
    }

    /**
     * Log session start
     */
    public function logSessionStart(int $userId, string $sessionId): int
    {
        return $this->logUserAction(
            userId: $userId,
            actionType: 'session_start',
            sessionId: $sessionId
        );
    }

    /**
     * Log session end
     */
    public function logSessionEnd(int $userId, string $sessionId, int $durationSeconds): int
    {
        return $this->logUserAction(
            userId: $userId,
            actionType: 'session_end',
            sessionId: $sessionId,
            durationSeconds: $durationSeconds
        );
    }

    /**
     * Log password change
     */
    public function logPasswordChange(int $userId): int
    {
        return $this->logUserAction(
            userId: $userId,
            actionType: 'password_change'
        );
    }

    /**
     * Log profile update
     */
    public function logProfileUpdate(int $userId, array $changedFields): int
    {
        return $this->logUserAction(
            userId: $userId,
            actionType: 'profile_update',
            metadata: ['changed_fields' => $changedFields]
        );
    }

    /**
     * Get user audit logs
     */
    public function getUserAuditLogs(
        ?int $userId = null,
        ?string $actionType = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        int $limit = 50,
        int $offset = 0
    ): array {
        try {
            $whereConditions = [];
            $params = [];

            if ($userId) {
                $whereConditions[] = 'user_id = ?';
                $params[] = $userId;
            }

            if ($actionType) {
                $whereConditions[] = 'action_type = ?';
                $params[] = $actionType;
            }

            if ($dateFrom) {
                $whereConditions[] = 'created_at >= ?';
                $params[] = $dateFrom . ' 00:00:00';
            }

            if ($dateTo) {
                $whereConditions[] = 'created_at <= ?';
                $params[] = $dateTo . ' 23:59:59';
            }

            $whereClause = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

            // Get total count
            $countSql = "SELECT COUNT(*) FROM fw_user_audit_log $whereClause";
            $totalResult = $this->connection->executeQuery($countSql, $params);
            $total = (int)$totalResult->fetchOne();

            // Get logs
            $sql = "SELECT * FROM fw_user_audit_log $whereClause ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
            $result = $this->connection->executeQuery($sql, $params);

            $logs = [];
            while ($row = $result->fetchAssociative()) {
                $row['metadata'] = $row['metadata'] ? json_decode($row['metadata'], true) : null;
                $logs[] = $row;
            }

            return [
                'logs' => $logs,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to get user audit logs', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'action_type' => $actionType
            ]);
            throw $e;
        }
    }

    /**
     * Get user activity summary
     */
    public function getUserActivitySummary(int $userId, int $days = 30): array
    {
        try {
            $dateFrom = date('Y-m-d', strtotime("-$days days"));

            $sql = "
                SELECT 
                    action_type,
                    COUNT(*) as count,
                    SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as success_count,
                    SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed_count,
                    AVG(duration_seconds) as avg_duration
                FROM fw_user_audit_log 
                WHERE user_id = ? AND created_at >= ?
                GROUP BY action_type
                ORDER BY count DESC
            ";

            $result = $this->connection->executeQuery($sql, [$userId, $dateFrom . ' 00:00:00']);

            $summary = [];
            while ($row = $result->fetchAssociative()) {
                $summary[] = $row;
            }

            return $summary;

        } catch (\Exception $e) {
            $this->logger->error('Failed to get user activity summary', [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
            throw $e;
        }
    }

    /**
     * Get client IP address
     */
    private function getClientIp(): ?string
    {
        $ipKeys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Handle comma-separated IPs (from proxies)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? null;
    }

    /**
     * Get user agent
     */
    private function getUserAgent(): ?string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? null;
    }
}
