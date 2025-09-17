<?php

namespace App\Services;

use App\Database\Database;
use Monolog\Logger;
use Exception;

class EventLoggingService
{
    private Logger $logger;
    private $connection;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->connection = Database::getConnection();
    }

    /**
     * Log a database change event
     *
     * @param string $entityType Type of entity (e.g., 'task', 'project', 'user')
     * @param int $entityId ID of the entity
     * @param string $eventType Type of event (e.g., 'STATUS_CHANGED', 'TASK_CREATED')
     * @param array $beforeData Data before the change (optional)
     * @param array $afterData Data after the change (optional)
     * @param array $changedFields List of changed fields
     * @param array $options Additional options
     * @return int|null Event log ID or null on failure
     */
    public function logEvent(
        string $entityType,
        int $entityId,
        string $eventType,
        array $beforeData = [],
        array $afterData = [],
        array $changedFields = [],
        array $options = []
    ): ?int {
        try {
            // Get event rule to determine severity and actions
            $eventRule = $this->getEventRule($eventType);
            if (!$eventRule) {
                $this->logger->warning('Event rule not found', ['event_type' => $eventType]);
                return null;
            }

            // Prepare log data
            $logData = [
                'tenant_id' => $options['tenant_id'] ?? null,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'entity_version' => $options['entity_version'] ?? null,
                'event_type' => $eventType,
                'severity' => $eventRule['severity'] ?? 'important',
                'actor_type' => $options['actor_type'] ?? 'user',
                'actor_id' => $options['actor_id'] ?? null,
                'correlation_id' => $options['correlation_id'] ?? $this->generateCorrelationId(),
                'changed_fields' => json_encode($changedFields, JSON_UNESCAPED_UNICODE),
                'before_data' => !empty($beforeData) ? json_encode($beforeData, JSON_UNESCAPED_UNICODE) : null,
                'after_data' => !empty($afterData) ? json_encode($afterData, JSON_UNESCAPED_UNICODE) : null,
                'comment' => $options['comment'] ?? null,
                'ip' => $options['ip'] ?? $this->getClientIp(),
                'user_agent' => $options['user_agent'] ?? $this->getUserAgent(),
            ];

            // Insert event log
            $eventLogId = $this->insertEventLog($logData);
            if (!$eventLogId) {
                return null;
            }

            // Process event actions (create outbox entries)
            $this->processEventActions($eventLogId, $eventType, $eventRule['actions'], $logData);

            $this->logger->info('Event logged successfully', [
                'event_log_id' => $eventLogId,
                'event_type' => $eventType,
                'entity_type' => $entityType,
                'entity_id' => $entityId
            ]);

            return $eventLogId;

        } catch (Exception $e) {
            $this->logger->error('Failed to log event', [
                'error' => $e->getMessage(),
                'event_type' => $eventType,
                'entity_type' => $entityType,
                'entity_id' => $entityId
            ]);
            return null;
        }
    }

    /**
     * Get event rule by event type
     */
    private function getEventRule(string $eventType): ?array
    {
        try {
            $result = $this->connection->executeQuery(
                'SELECT event_type, enabled, actions, severity, conditions, comment 
                 FROM fw_event_rules 
                 WHERE event_type = ? AND enabled = 1',
                [$eventType]
            );

            $rule = $result->fetchAssociative();
            if ($rule) {
                $rule['actions'] = json_decode($rule['actions'], true);
                $rule['conditions'] = $rule['conditions'] ? json_decode($rule['conditions'], true) : null;
            }

            return $rule ?: null;
        } catch (Exception $e) {
            $this->logger->error('Failed to get event rule', [
                'error' => $e->getMessage(),
                'event_type' => $eventType
            ]);
            return null;
        }
    }

    /**
     * Insert event log record
     */
    private function insertEventLog(array $logData): ?int
    {
        try {
            $sql = 'INSERT INTO fw_event_log (
                occurred_at, tenant_id, entity_type, entity_id, entity_version, event_type, severity,
                actor_type, actor_id, correlation_id, changed_fields, before_data, after_data,
                comment, ip, user_agent
            ) VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

            $this->connection->executeStatement($sql, [
                $logData['tenant_id'],
                $logData['entity_type'],
                $logData['entity_id'],
                $logData['entity_version'],
                $logData['event_type'],
                $logData['severity'],
                $logData['actor_type'],
                $logData['actor_id'],
                $logData['correlation_id'],
                $logData['changed_fields'],
                $logData['before_data'],
                $logData['after_data'],
                $logData['comment'],
                $logData['ip'],
                $logData['user_agent']
            ]);

            return $this->connection->lastInsertId();
        } catch (Exception $e) {
            $this->logger->error('Failed to insert event log', [
                'error' => $e->getMessage(),
                'log_data' => $logData
            ]);
            return null;
        }
    }

    /**
     * Process event actions and create outbox entries
     */
    private function processEventActions(int $eventLogId, string $eventType, array $actions, array $logData): void
    {
        try {
            foreach ($actions as $action) {
                $payload = [
                    'event_log_id' => $eventLogId,
                    'event_type' => $eventType,
                    'entity_type' => $logData['entity_type'],
                    'entity_id' => $logData['entity_id'],
                    'severity' => $logData['severity'],
                    'actor_type' => $logData['actor_type'],
                    'actor_id' => $logData['actor_id'],
                    'correlation_id' => $logData['correlation_id'],
                    'changed_fields' => json_decode($logData['changed_fields'], true),
                    'before_data' => $logData['before_data'] ? json_decode($logData['before_data'], true) : null,
                    'after_data' => $logData['after_data'] ? json_decode($logData['after_data'], true) : null,
                    'comment' => $logData['comment'],
                    'action' => $action,
                    'timestamp' => date('c')
                ];

                $this->connection->executeStatement(
                    'INSERT INTO fw_event_outbox (event_log_id, event_type, payload, status) VALUES (?, ?, ?, ?)',
                    [
                        $eventLogId,
                        $eventType,
                        json_encode($payload, JSON_UNESCAPED_UNICODE),
                        'pending'
                    ]
                );
            }
        } catch (Exception $e) {
            $this->logger->error('Failed to process event actions', [
                'error' => $e->getMessage(),
                'event_log_id' => $eventLogId,
                'actions' => $actions
            ]);
        }
    }

    /**
     * Get pending outbox events for processing
     */
    public function getPendingOutboxEvents(int $limit = 100): array
    {
        try {
            $result = $this->connection->executeQuery(
                "SELECT id, event_log_id, event_type, payload, attempts, last_error 
                 FROM fw_event_outbox 
                 WHERE status = ? 
                 ORDER BY created_at ASC 
                 LIMIT $limit",
                ['pending']
            );

            $events = [];
            while ($row = $result->fetchAssociative()) {
                $row['payload'] = json_decode($row['payload'], true);
                $events[] = $row;
            }

            return $events;
        } catch (Exception $e) {
            $this->logger->error('Failed to get pending outbox events', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Update outbox event status
     */
    public function updateOutboxEventStatus(int $outboxId, string $status, ?string $error = null): bool
    {
        try {
            $sql = 'UPDATE fw_event_outbox SET status = ?, attempts = attempts + 1, updated_at = NOW()';
            $params = [$status];

            if ($error) {
                $sql .= ', last_error = ?';
                $params[] = $error;
            }

            $sql .= ' WHERE id = ?';
            $params[] = $outboxId;

            $this->connection->executeStatement($sql, $params);
            return true;
        } catch (Exception $e) {
            $this->logger->error('Failed to update outbox event status', [
                'error' => $e->getMessage(),
                'outbox_id' => $outboxId,
                'status' => $status
            ]);
            return false;
        }
    }

    /**
     * Get event logs with filtering
     */
    public function getEventLogs(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        try {
            $whereConditions = [];
            $params = [];

            // Build WHERE conditions
            if (!empty($filters['entity_type'])) {
                $whereConditions[] = 'entity_type = ?';
                $params[] = $filters['entity_type'];
            }

            if (!empty($filters['entity_id'])) {
                $whereConditions[] = 'entity_id = ?';
                $params[] = $filters['entity_id'];
            }

            if (!empty($filters['event_type'])) {
                $whereConditions[] = 'event_type = ?';
                $params[] = $filters['event_type'];
            }

            if (!empty($filters['severity'])) {
                $whereConditions[] = 'severity = ?';
                $params[] = $filters['severity'];
            }

            if (!empty($filters['actor_type'])) {
                $whereConditions[] = 'actor_type = ?';
                $params[] = $filters['actor_type'];
            }

            if (!empty($filters['date_from'])) {
                $whereConditions[] = 'occurred_at >= ?';
                $params[] = $filters['date_from'];
            }

            if (!empty($filters['date_to'])) {
                $whereConditions[] = 'occurred_at <= ?';
                $params[] = $filters['date_to'];
            }

            $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

            // Get total count
            $countResult = $this->connection->executeQuery(
                "SELECT COUNT(*) as total FROM fw_event_log $whereClause",
                $params
            );
            $total = $countResult->fetchOne();

            // Get logs
            $sql = "SELECT * FROM fw_event_log $whereClause ORDER BY occurred_at DESC LIMIT $limit OFFSET $offset";

            $result = $this->connection->executeQuery($sql, $params);
            $logs = [];

            while ($row = $result->fetchAssociative()) {
                $row['changed_fields'] = json_decode($row['changed_fields'], true);
                $row['before_data'] = $row['before_data'] ? json_decode($row['before_data'], true) : null;
                $row['after_data'] = $row['after_data'] ? json_decode($row['after_data'], true) : null;
                $logs[] = $row;
            }

            return [
                'logs' => $logs,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to get event logs', [
                'error' => $e->getMessage(),
                'filters' => $filters
            ]);
            return ['logs' => [], 'total' => 0, 'limit' => $limit, 'offset' => $offset];
        }
    }

    /**
     * Generate correlation ID
     */
    private function generateCorrelationId(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
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

    /**
     * Get correlation ID for an event log
     */
    public function getCorrelationId(int $eventLogId): ?string
    {
        try {
            $result = $this->connection->executeQuery(
                'SELECT correlation_id FROM fw_event_log WHERE id = ?',
                [$eventLogId]
            );
            
            $row = $result->fetchAssociative();
            return $row ? $row['correlation_id'] : null;
        } catch (Exception $e) {
            $this->logger->error('Failed to get correlation ID', [
                'error' => $e->getMessage(),
                'event_log_id' => $eventLogId
            ]);
            return null;
        }
    }
}
