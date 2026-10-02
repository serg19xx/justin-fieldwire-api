<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use App\Services\EmailService;
use App\Services\TwilioService;
use App\Support\JwtToken;
use Doctrine\DBAL\Connection;
use Flight;
use Monolog\Logger;

/**
 * Temporary contractor access keys for a single task (shareable, non-personalized).
 */
class TaskAccessKeyController
{
    private Logger $logger;
    private Database $database;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->database = new Database();
    }

    private function currentUser(): ?array
    {
        $user = Flight::get('current_user');

        return is_array($user) ? $user : null;
    }

    private function canIssueKeys(?array $user): bool
    {
        if ($user === null) {
            return false;
        }
        if (($user['auth_type'] ?? null) === 'contractor_access') {
            return false;
        }
        $role = strtolower((string) ($user['role_code'] ?? ''));

        return in_array($role, ['admin', 'project_manager', 'foreman'], true);
    }

    /**
     * GET /api/v1/projects/{projectId}/tasks/{taskId}/access-key
     */
    public function getForTask(int $projectId, int $taskId): void
    {
        $user = $this->currentUser();
        if (!$this->canIssueKeys($user)) {
            Flight::json(['error_code' => 403, 'status' => 'error', 'message' => 'Forbidden', 'data' => null], 403);

            return;
        }

        try {
            $connection = $this->database->getConnection();
            if (!$this->taskBelongsToProject($connection, $projectId, $taskId)) {
                Flight::json(['error_code' => 404, 'status' => 'error', 'message' => 'Task not found', 'data' => null], 404);

                return;
            }

            $row = $connection->executeQuery(
                'SELECT id, task_id, project_id, contractor_id, key_code, expires_at, revoked_at, created_by, last_used_at, created_at
                 FROM fw_task_access_keys
                 WHERE task_id = ? AND project_id = ? AND revoked_at IS NULL
                 ORDER BY id DESC
                 LIMIT 1',
                [$taskId, $projectId]
            )->fetchAssociative();

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Access key status',
                'data' => [
                    'access_key' => $row ? $this->formatKeyRow($row, true) : null,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get access key', ['error' => $e->getMessage()]);
            Flight::json(['error_code' => 500, 'status' => 'error', 'message' => $e->getMessage(), 'data' => null], 500);
        }
    }

    /**
     * POST /api/v1/projects/{projectId}/tasks/{taskId}/access-key
     * Body: { expires_days?: number } — default from task end_planned or 7 days
     * Revokes any previous active key for the task (one shared key).
     */
    public function createOrRotate(int $projectId, int $taskId): void
    {
        $user = $this->currentUser();
        if (!$this->canIssueKeys($user)) {
            Flight::json(['error_code' => 403, 'status' => 'error', 'message' => 'Forbidden', 'data' => null], 403);

            return;
        }

        try {
            $connection = $this->database->getConnection();
            $task = $connection->executeQuery(
                'SELECT id, project_id, name, end_planned, executor_type, contractor_id
                 FROM fw_prj_tasks WHERE id = ? AND project_id = ? LIMIT 1',
                [$taskId, $projectId]
            )->fetchAssociative();

            if (!$task) {
                Flight::json(['error_code' => 404, 'status' => 'error', 'message' => 'Task not found', 'data' => null], 404);

                return;
            }

            $executorType = strtolower((string) ($task['executor_type'] ?? ''));
            $contractorId = isset($task['contractor_id']) && (int) $task['contractor_id'] > 0
                ? (int) $task['contractor_id']
                : null;
            if ($executorType !== 'contractor' || $contractorId === null) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Select a contractor as the task assignee before generating an access key',
                    'data' => null,
                ], 400);

                return;
            }

            $data = Flight::request()->data->getData();
            if (!is_array($data)) {
                $data = [];
            }

            $expiresAt = $this->resolveExpiresAt($task, $data);
            $keyCode = $this->generateKeyCode();
            $keyHash = hash('sha256', strtoupper($keyCode));
            $createdBy = isset($user['id']) && (int) $user['id'] > 0 ? (int) $user['id'] : null;

            $connection->beginTransaction();
            try {
                $connection->executeStatement(
                    'UPDATE fw_task_access_keys SET revoked_at = NOW()
                     WHERE task_id = ? AND project_id = ? AND revoked_at IS NULL',
                    [$taskId, $projectId]
                );
                $connection->executeStatement(
                    'INSERT INTO fw_task_access_keys
                     (task_id, project_id, contractor_id, key_code, key_hash, expires_at, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$taskId, $projectId, $contractorId, $keyCode, $keyHash, $expiresAt, $createdBy]
                );
                $id = (int) $connection->lastInsertId();
                $connection->commit();
            } catch (\Throwable $e) {
                $connection->rollBack();
                throw $e;
            }

            $row = $connection->executeQuery(
                'SELECT id, task_id, project_id, contractor_id, key_code, expires_at, revoked_at, created_by, last_used_at, created_at
                 FROM fw_task_access_keys WHERE id = ?',
                [$id]
            )->fetchAssociative();

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Access key created',
                'data' => [
                    'access_key' => $this->formatKeyRow($row ?: [], true),
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create access key', ['error' => $e->getMessage()]);
            Flight::json(['error_code' => 500, 'status' => 'error', 'message' => $e->getMessage(), 'data' => null], 500);
        }
    }

    /**
     * POST /api/v1/projects/{projectId}/tasks/{taskId}/access-key/send
     * Delivers the active key to the assigned contractor via email and/or SMS.
     */
    public function send(int $projectId, int $taskId): void
    {
        $user = $this->currentUser();
        if (!$this->canIssueKeys($user)) {
            Flight::json(['error_code' => 403, 'status' => 'error', 'message' => 'Forbidden', 'data' => null], 403);

            return;
        }

        try {
            $connection = $this->database->getConnection();
            $task = $connection->executeQuery(
                'SELECT id, project_id, name, executor_type, contractor_id
                 FROM fw_prj_tasks WHERE id = ? AND project_id = ? LIMIT 1',
                [$taskId, $projectId]
            )->fetchAssociative();

            if (!$task) {
                Flight::json(['error_code' => 404, 'status' => 'error', 'message' => 'Task not found', 'data' => null], 404);

                return;
            }

            $contractorId = isset($task['contractor_id']) && (int) $task['contractor_id'] > 0
                ? (int) $task['contractor_id']
                : null;
            if ($contractorId === null) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Task has no contractor assigned',
                    'data' => null,
                ], 400);

                return;
            }

            $keyRow = $connection->executeQuery(
                'SELECT id, key_code, expires_at, revoked_at
                 FROM fw_task_access_keys
                 WHERE task_id = ? AND project_id = ? AND revoked_at IS NULL
                 ORDER BY id DESC
                 LIMIT 1',
                [$taskId, $projectId]
            )->fetchAssociative();

            if (!$keyRow || empty($keyRow['key_code'])) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Generate an access key before sending',
                    'data' => null,
                ], 400);

                return;
            }
            if (strtotime((string) $keyRow['expires_at']) < time()) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Access key has expired — generate a new one',
                    'data' => null,
                ], 400);

                return;
            }

            $contractor = $connection->executeQuery(
                'SELECT id, name, email, phone FROM fw_contractors WHERE id = ? LIMIT 1',
                [$contractorId]
            )->fetchAssociative();

            if (!$contractor) {
                Flight::json(['error_code' => 404, 'status' => 'error', 'message' => 'Contractor not found', 'data' => null], 404);

                return;
            }

            $email = trim((string) ($contractor['email'] ?? ''));
            $phone = trim((string) ($contractor['phone'] ?? ''));
            if ($email === '' && $phone === '') {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Contractor has no email or phone — copy the key and send it manually',
                    'data' => null,
                ], 400);

                return;
            }

            $keyCode = (string) $keyRow['key_code'];
            $expiresAt = (string) $keyRow['expires_at'];
            $taskName = (string) ($task['name'] ?? 'Task');
            $contractorName = trim((string) ($contractor['name'] ?? 'Contractor'));
            $loginUrl = 'https://fieldwire.medicalcontractor.ca/login';

            $channels = [];
            $sent = ['email' => null, 'sms' => null];

            if ($email !== '') {
                $channels['email'] = $email;
                $safeName = htmlspecialchars($contractorName, ENT_QUOTES, 'UTF-8');
                $safeTask = htmlspecialchars($taskName, ENT_QUOTES, 'UTF-8');
                $safeKey = htmlspecialchars($keyCode, ENT_QUOTES, 'UTF-8');
                $safeExpires = htmlspecialchars($expiresAt, ENT_QUOTES, 'UTF-8');
                $subject = 'Your FieldWire login code — ' . $taskName;
                $text = "Hello {$contractorName},\n\n"
                    . "Your temporary FieldWire login code for task \"{$taskName}\" is:\n\n"
                    . "{$keyCode}\n\n"
                    . "Expires: {$expiresAt}\n"
                    . "Sign in: {$loginUrl}\n"
                    . "(Choose Contractor on the sign-in page.)\n\n"
                    . "You may share this code with your crew on site.\n";
                $html = '<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;color:#111;line-height:1.5;">'
                    . '<p>Hello ' . $safeName . ',</p>'
                    . '<p>Your temporary FieldWire login code for task <strong>' . $safeTask . '</strong>:</p>'
                    . '<p style="font-size:22px;letter-spacing:2px;font-family:monospace;"><strong>' . $safeKey . '</strong></p>'
                    . '<p>Expires: ' . $safeExpires . '</p>'
                    . '<p>Sign in as <strong>Contractor</strong> at<br>'
                    . '<a href="' . $loginUrl . '">' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '</a></p>'
                    . '<p style="color:#555;font-size:13px;">You may share this code with your crew on site.</p>'
                    . '</body></html>';
                $emailService = new EmailService($this->logger);
                $sent['email'] = $emailService->sendEmailWithTemplates(
                    $email,
                    $subject,
                    $html,
                    $text,
                    $contractorName
                );
            }

            if ($phone !== '') {
                $channels['phone'] = $phone;
                $smsBody = 'FieldWire access key for "' . $taskName . '": ' . $keyCode
                    . '. Expires ' . $expiresAt . '. Login: ' . $loginUrl
                    . ' (Contractor mode). Share with your crew if needed.';
                $twilio = new TwilioService($this->logger);
                $sent['sms'] = $twilio->sendSms($phone, $smsBody);
            }

            $parts = [];
            if ($sent['email'] === true) {
                $parts[] = 'email';
            } elseif ($sent['email'] === false) {
                $parts[] = 'email failed';
            }
            if ($sent['sms'] === true) {
                $parts[] = 'SMS';
            } elseif ($sent['sms'] === false) {
                $parts[] = 'SMS failed';
            }

            $anyOk = ($sent['email'] === true) || ($sent['sms'] === true);
            Flight::json([
                'error_code' => $anyOk ? 0 : 500,
                'status' => $anyOk ? 'success' : 'error',
                'message' => $anyOk
                    ? ('Sent: ' . implode(', ', $parts))
                    : ('Delivery failed: ' . implode(', ', $parts)),
                'data' => [
                    'sent' => $sent,
                    'channels' => $channels,
                ],
            ], $anyOk ? 200 : 500);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send access key', ['error' => $e->getMessage()]);
            Flight::json(['error_code' => 500, 'status' => 'error', 'message' => $e->getMessage(), 'data' => null], 500);
        }
    }

    /**
     * POST /api/v1/projects/{projectId}/tasks/{taskId}/access-key/revoke
     */
    public function revoke(int $projectId, int $taskId): void
    {
        $user = $this->currentUser();
        if (!$this->canIssueKeys($user)) {
            Flight::json(['error_code' => 403, 'status' => 'error', 'message' => 'Forbidden', 'data' => null], 403);

            return;
        }

        try {
            $connection = $this->database->getConnection();
            $affected = $connection->executeStatement(
                'UPDATE fw_task_access_keys SET revoked_at = NOW()
                 WHERE task_id = ? AND project_id = ? AND revoked_at IS NULL',
                [$taskId, $projectId]
            );

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => $affected > 0 ? 'Access key revoked' : 'No active key',
                'data' => ['revoked' => $affected > 0],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to revoke access key', ['error' => $e->getMessage()]);
            Flight::json(['error_code' => 500, 'status' => 'error', 'message' => $e->getMessage(), 'data' => null], 500);
        }
    }

    /**
     * POST /api/v1/auth/contractor-access  (public)
     * Body: { key: "FW-...." }
     */
    public function redeem(): void
    {
        try {
            $data = Flight::request()->data->getData();
            if (!is_array($data)) {
                $data = [];
            }
            $rawKey = strtoupper(trim((string) ($data['key'] ?? $data['access_key'] ?? '')));
            $rawKey = preg_replace('/\s+/', '', $rawKey) ?? '';
            if ($rawKey === '') {
                Flight::json(['error_code' => 400, 'status' => 'error', 'message' => 'Access key is required', 'data' => null], 400);

                return;
            }

            $connection = $this->database->getConnection();
            $hash = hash('sha256', $rawKey);
            $row = $connection->executeQuery(
                'SELECT k.*, t.name AS task_name, t.status AS task_status, t.start_planned, t.end_planned,
                        p.prj_name AS project_name
                 FROM fw_task_access_keys k
                 INNER JOIN fw_prj_tasks t ON t.id = k.task_id
                 LEFT JOIN fw_projects p ON p.id = k.project_id
                 WHERE k.key_hash = ?
                 LIMIT 1',
                [$hash]
            )->fetchAssociative();

            if (!$row) {
                Flight::json(['error_code' => 401, 'status' => 'error', 'message' => 'Invalid access key', 'data' => null], 401);

                return;
            }
            if ($row['revoked_at'] !== null) {
                Flight::json(['error_code' => 401, 'status' => 'error', 'message' => 'Access key has been revoked', 'data' => null], 401);

                return;
            }
            if (strtotime((string) $row['expires_at']) < time()) {
                Flight::json(['error_code' => 401, 'status' => 'error', 'message' => 'Access key has expired', 'data' => null], 401);

                return;
            }

            $connection->executeStatement(
                'UPDATE fw_task_access_keys SET last_used_at = NOW() WHERE id = ?',
                [(int) $row['id']]
            );

            $expiresAtTs = strtotime((string) $row['expires_at']);
            $tokenTtl = max(300, min(86400, $expiresAtTs - time())); // session up to 24h or until key expires
            $token = JwtToken::encode([
                'typ' => 'contractor_access',
                'access_key_id' => (int) $row['id'],
                'task_id' => (int) $row['task_id'],
                'project_id' => (int) $row['project_id'],
                'contractor_id' => $row['contractor_id'] !== null ? (int) $row['contractor_id'] : null,
                'iat' => time(),
                'exp' => time() + $tokenTtl,
            ]);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Access granted',
                'data' => [
                    'token' => $token,
                    'expires_at' => date('c', time() + $tokenTtl),
                    'key_expires_at' => $row['expires_at'],
                    'user' => [
                        'id' => 0,
                        'auth_type' => 'contractor_access',
                        'role_code' => 'contractor_access',
                        'role_category' => 'contractor_access',
                        'name' => 'Contractor',
                        'task_id' => (int) $row['task_id'],
                        'project_id' => (int) $row['project_id'],
                        'task_name' => $row['task_name'] ?? null,
                        'project_name' => $row['project_name'] ?? null,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to redeem access key', ['error' => $e->getMessage()]);
            Flight::json(['error_code' => 500, 'status' => 'error', 'message' => $e->getMessage(), 'data' => null], 500);
        }
    }

    private function taskBelongsToProject(Connection $connection, int $projectId, int $taskId): bool
    {
        $id = $connection->executeQuery(
            'SELECT id FROM fw_prj_tasks WHERE id = ? AND project_id = ? LIMIT 1',
            [$taskId, $projectId]
        )->fetchOne();

        return $id !== false && $id !== null;
    }

    /**
     * @param array<string, mixed> $task
     * @param array<string, mixed> $data
     */
    private function resolveExpiresAt(array $task, array $data): string
    {
        if (isset($data['expires_days']) && is_numeric($data['expires_days'])) {
            $days = max(1, min(90, (int) $data['expires_days']));

            return date('Y-m-d 23:59:59', strtotime('+' . $days . ' days'));
        }
        if (isset($data['expires_at']) && is_string($data['expires_at']) && trim($data['expires_at']) !== '') {
            $ts = strtotime(trim($data['expires_at']));
            if ($ts !== false && $ts > time()) {
                return date('Y-m-d H:i:s', $ts);
            }
        }
        $end = $task['end_planned'] ?? null;
        if (is_string($end) && $end !== '') {
            $ts = strtotime($end . ' 23:59:59');
            if ($ts !== false && $ts > time()) {
                return date('Y-m-d H:i:s', $ts);
            }
        }

        return date('Y-m-d 23:59:59', strtotime('+7 days'));
    }

    private function generateKeyCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $part = static function () use ($alphabet): string {
            $out = '';
            for ($i = 0; $i < 4; $i++) {
                $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }

            return $out;
        };

        return 'FW-' . $part() . '-' . $part();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatKeyRow(array $row, bool $includeCode): array
    {
        $expiresAt = $row['expires_at'] ?? null;
        $revokedAt = $row['revoked_at'] ?? null;
        $isActive = $revokedAt === null && $expiresAt !== null && strtotime((string) $expiresAt) >= time();

        $out = [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'task_id' => isset($row['task_id']) ? (int) $row['task_id'] : null,
            'project_id' => isset($row['project_id']) ? (int) $row['project_id'] : null,
            'contractor_id' => isset($row['contractor_id']) && $row['contractor_id'] !== null ? (int) $row['contractor_id'] : null,
            'expires_at' => $expiresAt,
            'revoked_at' => $revokedAt,
            'is_active' => $isActive,
            'last_used_at' => $row['last_used_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ];
        if ($includeCode) {
            $out['key_code'] = $row['key_code'] ?? null;
        }

        return $out;
    }
}
