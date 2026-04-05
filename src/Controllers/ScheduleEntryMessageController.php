<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use Doctrine\DBAL\Connection;
use Flight;
use Monolog\Logger;
use OpenApi\Annotations as OA;

/**
 * Schedule slot messages (foreman vs PM channels). OpenAPI tag: Schedule (see ScheduleWeekController).
 *
 * One thread per (fw_worker_task_schedules.id, channel): GET lists all messages for that pair (no author filter);
 * POST binds worker_task_schedule_id to the slot row loaded from the path. See docs/SCHEDULE_WEEKS_API.md §4.1.
 */
class ScheduleEntryMessageController
{
    private const CHANNELS = ['foreman', 'pm'];

    private const MESSAGE_BODY_MAX_LEN = 4000;

    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 100;

    public function __construct(
        private readonly Logger $logger
    ) {
    }

    /**
     * GET /api/v1/projects/{projectId}/schedule-entries/{scheduleEntryId}/messages?channel=foreman|pm&limit=&before_id=
     *
     * @OA\Get(
     *     path="/api/v1/projects/{project_id}/schedule-entries/{schedule_entry_id}/messages",
     *     tags={"Schedule"},
     *     summary="List messages for one schedule entry and channel",
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(name="project_id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="schedule_entry_id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="channel", in="query", required=true, @OA\Schema(type="string", enum={"foreman","pm"})),
     *     @OA\Parameter(name="limit", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="before_id", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK"),
     *     @OA\Response(response=400, description="Invalid channel or pagination"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function index(int $projectId, int $scheduleEntryId): void
    {
        $conn = Database::getConnection();
        $channel = $this->parseChannelQuery(Flight::request()->query['channel'] ?? null);
        if ($channel === null) {
            $this->error('channel query parameter is required and must be exactly foreman or pm', 400);
            return;
        }

        $limit = $this->parseLimit(Flight::request()->query['limit'] ?? null);
        if ($limit === null) {
            $this->error('limit must be between 1 and ' . self::MAX_LIMIT, 400);
            return;
        }

        $beforeId = $this->parseBeforeId(Flight::request()->query['before_id'] ?? null);
        if ($beforeId === false) {
            $this->error('before_id must be a positive integer', 400);
            return;
        }

        if (!$this->projectExists($conn, $projectId)) {
            $this->error('Project not found', 404);
            return;
        }

        $entry = $this->fetchScheduleEntry($conn, $scheduleEntryId, $projectId);
        if ($entry === null) {
            $this->error('Schedule entry not found', 404);
            return;
        }

        $actorId = (int) Flight::get('current_user')['id'];
        if (!$this->canViewEntryMessages($conn, $actorId, $entry, $projectId)) {
            $this->error('Forbidden', 403);
            return;
        }

        $slotPk = (int) $entry['id'];
        if ($slotPk !== $scheduleEntryId) {
            $this->logger->critical('Schedule message GET: path id mismatch vs loaded row', [
                'path_schedule_entry_id' => $scheduleEntryId,
                'row_id' => $slotPk,
                'project_id' => $projectId,
            ]);
            $this->error('Internal error: schedule entry id mismatch', 500);
            return;
        }

        // List full channel history: filter only worker_task_schedule_id + channel + not soft-deleted.
        // Do NOT restrict by author_user_id — any user who may view the slot sees the same messages.
        $sql = '
            SELECT id, worker_task_schedule_id, channel, author_user_id, body, created_at, updated_at, deleted_at
            FROM fw_worker_task_schedule_messages
            WHERE worker_task_schedule_id = ?
              AND channel = ?
              AND deleted_at IS NULL
        ';
        $params = [$slotPk, $channel];
        if ($beforeId !== null) {
            $sql .= ' AND id < ?';
            $params[] = $beforeId;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . (int) $limit;

        $rows = $conn->executeQuery($sql, $params)->fetchAllAssociative();
        $rows = array_reverse($rows);

        Flight::json([
            'error_code' => 0,
            'status' => 'success',
            'message' => 'Messages retrieved',
            'data' => [
                'channel' => $channel,
                'messages' => array_map(fn (array $r) => $this->formatMessageRow($r), $rows),
            ],
        ]);
    }

    /**
     * POST /api/v1/projects/{projectId}/schedule-entries/{scheduleEntryId}/messages
     *
     * @OA\Post(
     *     path="/api/v1/projects/{project_id}/schedule-entries/{schedule_entry_id}/messages",
     *     tags={"Schedule"},
     *     summary="Post a message to foreman or PM channel",
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(name="project_id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="schedule_entry_id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"channel","body"},
     *             @OA\Property(property="channel", type="string", enum={"foreman","pm"}),
     *             @OA\Property(property="body", type="string", maxLength=4000)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Created"),
     *     @OA\Response(response=400, description="Validation"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function create(int $projectId, int $scheduleEntryId): void
    {
        $payload = json_decode(Flight::request()->getBody(), true);
        if (!is_array($payload)) {
            $this->error('JSON body required', 400);
            return;
        }

        // Slot id is ONLY the path segment .../schedule-entries/{id}/messages (same as GET week entries[].id).
        // A second id in the body has caused wrong worker_task_schedule_id in the past (e.g. FE mixing ids).
        foreach (['worker_task_schedule_id', 'schedule_entry_id', 'entry_id', 'slot_id'] as $forbiddenKey) {
            if (array_key_exists($forbiddenKey, $payload)) {
                $this->error(
                    'Do not send "' . $forbiddenKey . '" in the JSON body. Use the URL path id only; it must equal fw_worker_task_schedules.id / entries[].id.',
                    400
                );
                return;
            }
        }

        $channel = $this->normalizeChannel($payload['channel'] ?? null);
        if ($channel === null) {
            $this->error('body.channel is required and must be exactly foreman or pm', 400);
            return;
        }

        $bodyRaw = $payload['body'] ?? null;
        if (!is_string($bodyRaw)) {
            $this->error('body.body is required and must be a string', 400);
            return;
        }
        $body = trim($bodyRaw);
        if ($body === '') {
            $this->error('body.body must not be empty', 400);
            return;
        }
        if ($this->stringCharLength($body) > self::MESSAGE_BODY_MAX_LEN) {
            $this->error('body.body must not exceed ' . self::MESSAGE_BODY_MAX_LEN . ' characters', 400);
            return;
        }

        $conn = Database::getConnection();
        if (!$this->projectExists($conn, $projectId)) {
            $this->error('Project not found', 404);
            return;
        }

        $entry = $this->fetchScheduleEntry($conn, $scheduleEntryId, $projectId);
        if ($entry === null) {
            $this->error('Schedule entry not found', 404);
            return;
        }

        $actorId = (int) Flight::get('current_user')['id'];
        if (!$this->canViewEntryMessages($conn, $actorId, $entry, $projectId)) {
            $this->error('Forbidden', 403);
            return;
        }

        if ($channel === 'foreman') {
            if (!$this->canPostToForemanChannel($conn, $actorId, $entry, $projectId)) {
                $this->error('Forbidden: cannot post to foreman channel', 403);
                return;
            }
        } elseif (!$this->canPostToPmChannel($conn, $actorId, $entry, $projectId)) {
            $this->error('Forbidden: cannot post to pm channel', 403);
            return;
        }

        $slotPk = (int) $entry['id'];
        if ($slotPk !== $scheduleEntryId) {
            $this->logger->critical('Schedule message POST: path id mismatch vs loaded row', [
                'path_schedule_entry_id' => $scheduleEntryId,
                'row_id' => $slotPk,
                'project_id' => $projectId,
            ]);
            $this->error('Internal error: schedule entry id mismatch', 500);
            return;
        }

        try {
            $conn->insert('fw_worker_task_schedule_messages', [
                'worker_task_schedule_id' => $slotPk,
                'channel' => $channel,
                'author_user_id' => $actorId,
                'body' => $body,
            ]);
            $id = (int) $conn->lastInsertId();
            $this->logger->info('Schedule slot message created', [
                'worker_task_schedule_id' => $slotPk,
                'project_id' => $projectId,
                'channel' => $channel,
                'author_user_id' => $actorId,
                'message_id' => $id,
            ]);
            $row = $conn->executeQuery(
                'SELECT id, worker_task_schedule_id, channel, author_user_id, body, created_at, updated_at, deleted_at
                 FROM fw_worker_task_schedule_messages WHERE id = ?',
                [$id]
            )->fetchAssociative();
            if (!$row) {
                throw new \RuntimeException('Failed to load created message');
            }
        } catch (\Throwable $e) {
            $this->logger->error('schedule entry message create failed', ['error' => $e->getMessage()]);
            $this->error('Failed to create message', 500);
            return;
        }

        Flight::json([
            'error_code' => 0,
            'status' => 'success',
            'message' => 'Message created',
            'data' => [
                'message' => $this->formatMessageRow($row),
            ],
        ]);
    }

    private function parseChannelQuery(mixed $raw): ?string
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        return $this->normalizeChannel($raw);
    }

    private function normalizeChannel(mixed $raw): ?string
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        if (!in_array($raw, self::CHANNELS, true)) {
            return null;
        }

        return $raw;
    }

    private function parseLimit(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return self::DEFAULT_LIMIT;
        }
        if (is_string($raw) && ctype_digit($raw)) {
            $n = (int) $raw;
        } elseif (is_int($raw)) {
            $n = $raw;
        } else {
            return null;
        }
        if ($n < 1 || $n > self::MAX_LIMIT) {
            return null;
        }

        return $n;
    }

    /** @return false|null|int null = omitted */
    private function parseBeforeId(mixed $raw): false|null|int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_string($raw) && ctype_digit($raw)) {
            $n = (int) $raw;
        } elseif (is_int($raw)) {
            $n = $raw;
        } else {
            return false;
        }
        if ($n < 1) {
            return false;
        }

        return $n;
    }

    /** @return array<string, mixed>|null */
    private function fetchScheduleEntry(Connection $conn, int $entryId, int $projectId): ?array
    {
        $row = $conn->executeQuery(
            'SELECT id, project_id, user_id, task_id, schedule_week_id, work_date, day_part
             FROM fw_worker_task_schedules WHERE id = ? AND project_id = ?',
            [$entryId, $projectId]
        )->fetchAssociative();

        return $row ?: null;
    }

    private function canViewEntryMessages(Connection $conn, int $actorId, array $entry, int $projectId): bool
    {
        if ((int) $entry['user_id'] === $actorId) {
            return true;
        }

        return $this->canViewProjectSchedule($conn, $actorId, $projectId);
    }

    private function canPostToForemanChannel(Connection $conn, int $actorId, array $entry, int $projectId): bool
    {
        if ((int) $entry['user_id'] === $actorId) {
            return true;
        }
        if ($this->isTaskLeadSupervisorOrManagerOnTask($conn, $actorId, (int) $entry['task_id'], $projectId)) {
            return true;
        }
        $role = $this->getRoleCode($conn, $actorId);

        return $role === 'admin';
    }

    private function canPostToPmChannel(Connection $conn, int $actorId, array $entry, int $projectId): bool
    {
        if ((int) $entry['user_id'] === $actorId) {
            return true;
        }

        return $this->canManageSchedule($conn, $actorId, $projectId);
    }

    private function isTaskLeadSupervisorOrManagerOnTask(Connection $conn, int $userId, int $taskId, int $projectId): bool
    {
        $rows = $conn->executeQuery(
            'SELECT role_in_project FROM fw_prj_team_members
             WHERE project_id = ? AND task_id = ? AND user_id = ?',
            [$projectId, $taskId, $userId]
        )->fetchAllAssociative();
        foreach ($rows as $ro) {
            $role = $ro['role_in_project'] ?? null;
            if (!is_string($role) || $role === '') {
                continue;
            }
            $r = strtolower($role);
            if (str_contains($r, 'lead')
                || str_contains($r, 'supervisor')
                || str_contains($r, 'manager')) {
                return true;
            }
        }

        return false;
    }

    private function canManageSchedule(Connection $conn, int $userId, int $projectId): bool
    {
        $role = $this->getRoleCode($conn, $userId);
        if (in_array($role, ['admin', 'project_manager'], true)) {
            return true;
        }
        $row = $conn->executeQuery(
            'SELECT prj_manager FROM fw_projects WHERE id = ?',
            [$projectId]
        )->fetchAssociative();
        if ($row && isset($row['prj_manager']) && $row['prj_manager'] !== null && (int) $row['prj_manager'] === $userId) {
            return true;
        }

        return false;
    }

    private function canViewProjectSchedule(Connection $conn, int $userId, int $projectId): bool
    {
        if ($this->canManageSchedule($conn, $userId, $projectId)) {
            return true;
        }
        $one = $conn->executeQuery(
            'SELECT 1 FROM fw_prj_team_members WHERE project_id = ? AND user_id = ? LIMIT 1',
            [$projectId, $userId]
        )->fetchOne();

        return (bool) $one;
    }

    private function projectExists(Connection $conn, int $projectId): bool
    {
        return (bool) $conn->executeQuery('SELECT id FROM fw_projects WHERE id = ?', [$projectId])->fetchOne();
    }

    private function getRoleCode(Connection $conn, int $userId): ?string
    {
        $r = $conn->executeQuery(
            'SELECT role_code FROM fw_v_users WHERE id = ? AND archived_at IS NULL',
            [$userId]
        )->fetchAssociative();

        return $r['role_code'] ?? null;
    }

    private function stringCharLength(string $s): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($s);
        }

        return strlen($s);
    }

    /** @param array<string, mixed> $row */
    private function formatMessageRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'worker_task_schedule_id' => (int) $row['worker_task_schedule_id'],
            'channel' => $row['channel'],
            'author_user_id' => (int) $row['author_user_id'],
            'body' => $row['body'],
            'created_at' => $this->formatInstantUtc($row['created_at'] ?? null),
            'updated_at' => $this->formatInstantUtc($row['updated_at'] ?? null),
            'deleted_at' => $this->formatInstantUtc($row['deleted_at'] ?? null),
        ];
    }

    private function formatInstantUtc(mixed $db): ?string
    {
        if ($db === null || $db === '') {
            return null;
        }
        if (!is_string($db)) {
            return null;
        }
        try {
            $dt = (new \DateTimeImmutable($db))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
        $micro = $dt->format('u');
        $ms = strlen($micro) >= 3 ? substr($micro, 0, 3) : str_pad($micro, 3, '0');

        return $dt->format('Y-m-d\TH:i:s') . '.' . $ms . 'Z';
    }

    private function error(string $message, int $http): void
    {
        Flight::json([
            'error_code' => $http,
            'status' => 'error',
            'message' => $message,
            'data' => null,
        ], $http);
    }
}
