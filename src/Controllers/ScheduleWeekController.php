<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Flight;
use Monolog\Logger;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Schedule",
 *     description="Weekly worker–task schedule (draft / published)"
 * )
 */
class ScheduleWeekController
{
    private const DAY_PARTS = ['am', 'pm', 'full'];

    /** Inclusive window max length for schedule range queries (`from`…`to`). */
    private const MAX_SCHEDULE_RANGE_DAYS = 62;

    public function __construct(
        private readonly Logger $logger
    ) {
    }

    /**
     * GET /api/v1/projects/{projectId}/schedule-weeks?week_start=YYYY-MM-DD
     *
     * @OA\Get(
     *     path="/api/v1/projects/{project_id}/schedule-weeks",
     *     tags={"Schedule"},
     *     summary="Get schedule week and entries",
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(name="project_id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="week_start", in="query", required=true, @OA\Schema(type="string", format="date")),
     *     @OA\Response(response=200, description="OK"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Project not found")
     * )
     */
    public function getWeek(int $projectId): void
    {
        $weekStartRaw = Flight::request()->query['week_start'] ?? null;
        if (!$weekStartRaw || !is_string($weekStartRaw)) {
            $this->error('week_start query parameter is required (YYYY-MM-DD)', 400);
            return;
        }
        try {
            $weekStart = $this->normalizeWeekStartDate($weekStartRaw);
        } catch (\Throwable) {
            $this->error('Invalid week_start date', 400);
            return;
        }
        $conn = Database::getConnection();
        if (!$this->projectExists($conn, $projectId)) {
            $this->error('Project not found', 404);
            return;
        }
        $userId = (int) Flight::get('current_user')['id'];
        if (!$this->canViewProjectSchedule($conn, $userId, $projectId)) {
            $this->error('Forbidden', 403);
            return;
        }

        $week = $conn->executeQuery(
            'SELECT id, project_id, week_start, status, published_at, published_by, created_at, updated_at
             FROM fw_schedule_weeks WHERE project_id = ? AND week_start = ?',
            [$projectId, $weekStart]
        )->fetchAssociative();

        if (!$week) {
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'No schedule week for this project and week_start',
                'data' => [
                    'schedule_week' => null,
                    'entries' => [],
                ],
            ]);
            return;
        }

        $entries = $this->fetchEntryRowsForWeek($conn, (int) $week['id']);

        Flight::json([
            'error_code' => 0,
            'status' => 'success',
            'message' => 'Schedule week retrieved',
            'data' => [
                'schedule_week' => $this->formatWeekRow($week),
                'entries' => array_map(fn ($r) => $this->formatEntryRow($r), $entries),
            ],
        ]);
    }

    /**
     * POST /api/v1/projects/{projectId}/schedule-weeks — ensure a draft week row exists
     * Body: { "week_start": "YYYY-MM-DD" } (any day in the week; normalized to Monday)
     *
     * @OA\Post(
     *     path="/api/v1/projects/{project_id}/schedule-weeks",
     *     tags={"Schedule"},
     *     summary="Create or return draft schedule week",
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(name="project_id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"week_start"},
     *             @OA\Property(property="week_start", type="string", format="date", example="2026-03-23")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Draft already existed; same data shape as create (schedule_week + entries)"),
     *     @OA\Response(response=201, description="Draft created; schedule_week + entries (empty array if none yet)"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=409, description="Week already published")
     * )
     */
    public function ensureDraftWeek(int $projectId): void
    {
        $payload = json_decode(Flight::request()->getBody(), true) ?? [];
        $weekStartRaw = $payload['week_start'] ?? null;
        if (!$weekStartRaw || !is_string($weekStartRaw)) {
            $this->error('week_start is required in body', 400);
            return;
        }
        try {
            $weekStart = $this->normalizeWeekStartDate($weekStartRaw);
        } catch (\Throwable) {
            $this->error('Invalid week_start date', 400);
            return;
        }
        $conn = Database::getConnection();
        if (!$this->projectExists($conn, $projectId)) {
            $this->error('Project not found', 404);
            return;
        }
        $userId = (int) Flight::get('current_user')['id'];
        if (!$this->canManageSchedule($conn, $userId, $projectId)) {
            $this->error('Forbidden', 403);
            return;
        }

        $existing = $conn->executeQuery(
            'SELECT id, project_id, week_start, status, published_at, published_by, created_at, updated_at
             FROM fw_schedule_weeks WHERE project_id = ? AND week_start = ?',
            [$projectId, $weekStart]
        )->fetchAssociative();

        if ($existing) {
            if (($existing['status'] ?? '') === 'published') {
                $this->error('Week is already published; cannot create draft for the same week_start', 409);
                return;
            }
            $entries = $this->fetchEntryRowsForWeek($conn, (int) $existing['id']);
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Draft schedule week already exists',
                'data' => [
                    'schedule_week' => $this->formatWeekRow($existing),
                    'entries' => array_map(fn ($r) => $this->formatEntryRow($r), $entries),
                ],
            ]);
            return;
        }

        $conn->insert('fw_schedule_weeks', [
            'project_id' => $projectId,
            'week_start' => $weekStart,
            'status' => 'draft',
            'published_at' => null,
            'published_by' => null,
        ]);

        $id = (int) $conn->lastInsertId();
        $week = $conn->executeQuery(
            'SELECT id, project_id, week_start, status, published_at, published_by, created_at, updated_at
             FROM fw_schedule_weeks WHERE id = ?',
            [$id]
        )->fetchAssociative();

        Flight::json([
            'error_code' => 0,
            'status' => 'success',
            'message' => 'Draft schedule week created',
            'data' => [
                'schedule_week' => $this->formatWeekRow($week),
                'entries' => [],
            ],
        ], 201);
    }

    /**
     * PUT /api/v1/projects/{projectId}/schedule-weeks/{weekId}/entries
     * Body: { "entries": [ { "user_id", "task_id", "work_date", "day_part" }, ... ] }
     *
     * @OA\Put(
     *     path="/api/v1/projects/{project_id}/schedule-weeks/{week_id}/entries",
     *     tags={"Schedule"},
     *     summary="Replace all draft entries for a week",
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(name="project_id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="week_id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"entries"},
     *             @OA\Property(
     *                 property="entries",
     *                 type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="user_id", type="integer"),
     *                     @OA\Property(property="task_id", type="integer"),
     *                     @OA\Property(property="work_date", type="string", format="date"),
     *                     @OA\Property(property="day_part", type="string", enum={"am","pm","full"})
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=200, description="OK"),
     *     @OA\Response(response=409, description="Not draft or duplicate slot")
     * )
     */
    public function replaceEntries(int $projectId, int $weekId): void
    {
        $payload = json_decode(Flight::request()->getBody(), true) ?? [];
        $entries = $payload['entries'] ?? null;
        if (!is_array($entries)) {
            $this->error('entries must be an array', 400);
            return;
        }

        $conn = Database::getConnection();
        if (!$this->projectExists($conn, $projectId)) {
            $this->error('Project not found', 404);
            return;
        }
        $userId = (int) Flight::get('current_user')['id'];
        if (!$this->canManageSchedule($conn, $userId, $projectId)) {
            $this->error('Forbidden', 403);
            return;
        }

        $week = $conn->executeQuery(
            'SELECT id, project_id, week_start, status FROM fw_schedule_weeks WHERE id = ? AND project_id = ?',
            [$weekId, $projectId]
        )->fetchAssociative();
        if (!$week) {
            $this->error('Schedule week not found', 404);
            return;
        }
        if (($week['status'] ?? '') !== 'draft') {
            $this->error('Cannot edit entries: schedule week is not draft', 409);
            return;
        }

        $normalized = [];
        $slotKeys = [];
        foreach ($entries as $i => $row) {
            if (!is_array($row)) {
                $this->error("entries[{$i}] must be an object", 400);
                return;
            }
            $wid = isset($row['user_id']) ? (int) $row['user_id'] : 0;
            $tid = isset($row['task_id']) ? (int) $row['task_id'] : 0;
            $wdate = $row['work_date'] ?? null;
            $dp = $row['day_part'] ?? null;
            if ($wid <= 0 || $tid <= 0 || !$wdate || !is_string($wdate) || !$dp || !is_string($dp)) {
                $this->error("entries[{$i}]: user_id, task_id, work_date, day_part required", 400);
                return;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $wdate)) {
                $this->error("entries[{$i}]: work_date must be YYYY-MM-DD", 400);
                return;
            }
            $dp = strtolower(trim($dp));
            if (!in_array($dp, self::DAY_PARTS, true)) {
                $this->error('day_part must be one of: am, pm, full', 400);
                return;
            }
            if (!$this->workDateInScheduleWeek((string) $week['week_start'], $wdate)) {
                $this->error("entries[{$i}]: work_date must fall within the schedule week (Monday–Sunday of week_start)", 400);
                return;
            }
            if (!$this->taskBelongsToProject($conn, $tid, $projectId)) {
                $this->error("entries[{$i}]: task not found in this project", 400);
                return;
            }
            if (!$this->userIsTaskAssignee($conn, $wid, $tid, $projectId)) {
                $this->error("entries[{$i}]: user is not assigned to this task", 400);
                return;
            }
            $k = $wid . '|' . $wdate . '|' . $dp;
            if (isset($slotKeys[$k])) {
                $this->error('Duplicate slot in payload: same user_id, work_date, day_part', 409);
                return;
            }
            $slotKeys[$k] = true;
            $normalized[] = ['user_id' => $wid, 'task_id' => $tid, 'work_date' => $wdate, 'day_part' => $dp];
        }

        try {
            $conn->beginTransaction();
            $conn->executeStatement(
                'DELETE FROM fw_worker_task_schedules WHERE schedule_week_id = ?',
                [$weekId]
            );
            foreach ($normalized as $e) {
                $conn->insert('fw_worker_task_schedules', [
                    'schedule_week_id' => $weekId,
                    'project_id' => $projectId,
                    'user_id' => $e['user_id'],
                    'task_id' => $e['task_id'],
                    'work_date' => $e['work_date'],
                    'day_part' => $e['day_part'],
                ]);
            }
            $conn->commit();
        } catch (UniqueConstraintViolationException $e) {
            $conn->rollBack();
            $this->logger->warning('Schedule entries unique violation', ['error' => $e->getMessage()]);
            $this->error('Duplicate schedule slot', 409);
            return;
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->logger->error('replaceEntries failed', ['error' => $e->getMessage()]);
            $this->error('Failed to save entries', 500);
            return;
        }

        // TODO: emit domain event SCHEDULE_WEEK_ENTRIES_UPDATED for notifications / automation

        $weekFull = $conn->executeQuery(
            'SELECT id, project_id, week_start, status, published_at, published_by, created_at, updated_at
             FROM fw_schedule_weeks WHERE id = ?',
            [$weekId]
        )->fetchAssociative();

        $saved = $this->fetchEntryRowsForWeek($conn, $weekId);

        Flight::json([
            'error_code' => 0,
            'status' => 'success',
            'message' => 'Entries replaced',
            'data' => [
                'schedule_week' => $this->formatWeekRow($weekFull ?: $week),
                'entries' => array_map(fn ($r) => $this->formatEntryRow($r), $saved),
            ],
        ]);
    }

    /**
     * POST /api/v1/projects/{projectId}/schedule-weeks/{weekId}/publish
     *
     * @OA\Post(
     *     path="/api/v1/projects/{project_id}/schedule-weeks/{week_id}/publish",
     *     tags={"Schedule"},
     *     summary="Publish schedule week",
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(name="project_id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="week_id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK"),
     *     @OA\Response(response=409, description="Not draft")
     * )
     */
    public function publishWeek(int $projectId, int $weekId): void
    {
        $conn = Database::getConnection();
        if (!$this->projectExists($conn, $projectId)) {
            $this->error('Project not found', 404);
            return;
        }
        $actorId = (int) Flight::get('current_user')['id'];
        if (!$this->canManageSchedule($conn, $actorId, $projectId)) {
            $this->error('Forbidden', 403);
            return;
        }

        $week = $conn->executeQuery(
            'SELECT id, project_id, week_start, status FROM fw_schedule_weeks WHERE id = ? AND project_id = ?',
            [$weekId, $projectId]
        )->fetchAssociative();
        if (!$week) {
            $this->error('Schedule week not found', 404);
            return;
        }
        if (($week['status'] ?? '') !== 'draft') {
            $this->error('Week is not in draft state', 409);
            return;
        }

        $rows = $conn->executeQuery(
            'SELECT user_id, task_id, work_date, day_part FROM fw_worker_task_schedules WHERE schedule_week_id = ?',
            [$weekId]
        )->fetchAllAssociative();
        foreach ($rows as $i => $row) {
            $ws = (string) $week['week_start'];
            if (!$this->workDateInScheduleWeek($ws, (string) $row['work_date'])) {
                $this->error("Invalid entry at index {$i}: work_date outside week", 400);
                return;
            }
            if (!$this->taskBelongsToProject($conn, (int) $row['task_id'], $projectId)) {
                $this->error("Invalid entry at index {$i}: task not in project", 400);
                return;
            }
            if (!$this->userIsTaskAssignee($conn, (int) $row['user_id'], (int) $row['task_id'], $projectId)) {
                $this->error("Invalid entry at index {$i}: user not assignee", 400);
                return;
            }
        }

        $conn->executeStatement(
            'UPDATE fw_schedule_weeks SET status = ?, published_at = NOW(3), published_by = ?, updated_at = NOW(3) WHERE id = ?',
            ['published', $actorId, $weekId]
        );

        // TODO: emit SCHEDULE_WEEK_PUBLISHED

        $fresh = $conn->executeQuery(
            'SELECT id, project_id, week_start, status, published_at, published_by, created_at, updated_at
             FROM fw_schedule_weeks WHERE id = ?',
            [$weekId]
        )->fetchAssociative();

        Flight::json([
            'error_code' => 0,
            'status' => 'success',
            'message' => 'Schedule week published',
            'data' => ['schedule_week' => $this->formatWeekRow($fresh)],
        ]);
    }

    /**
     * GET /api/v1/me/schedule?from=YYYY-MM-DD&to=YYYY-MM-DD
     *
     * @OA\Get(
     *     path="/api/v1/me/schedule",
     *     tags={"Schedule"},
     *     summary="Current user schedule (published weeks only)",
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(name="from", in="query", required=true, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="to", in="query", required=true, @OA\Schema(type="string", format="date")),
     *     @OA\Response(response=200, description="OK")
     * )
     */
    public function getMySchedule(): void
    {
        $from = Flight::request()->query['from'] ?? null;
        $to = Flight::request()->query['to'] ?? null;
        $rangeError = $this->validateScheduleQueryRange($from, $to);
        if ($rangeError !== null) {
            $this->error($rangeError, 400);
            return;
        }

        $userId = (int) Flight::get('current_user')['id'];
        $conn = Database::getConnection();
        $out = $this->buildPublishedScheduleEntries($conn, $userId, (string) $from, (string) $to);

        Flight::json([
            'error_code' => 0,
            'status' => 'success',
            'message' => 'Schedule retrieved',
            'data' => ['entries' => $out],
        ]);
    }

    /**
     * GET /api/v1/users/{userId}/schedule?from=&to= — published slots across all projects (PM / admin).
     *
     * @OA\Get(
     *     path="/api/v1/users/{user_id}/schedule",
     *     tags={"Schedule"},
     *     summary="User schedule across projects (published weeks only)",
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(name="user_id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="from", in="query", required=true, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="to", in="query", required=true, @OA\Schema(type="string", format="date")),
     *     @OA\Response(response=200, description="OK"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="User not found")
     * )
     */
    public function getUserSchedule(int $userId): void
    {
        $from = Flight::request()->query['from'] ?? null;
        $to = Flight::request()->query['to'] ?? null;
        $rangeError = $this->validateScheduleQueryRange($from, $to);
        if ($rangeError !== null) {
            $this->error($rangeError, 400);
            return;
        }

        $actorId = (int) Flight::get('current_user')['id'];
        $conn = Database::getConnection();

        if (!$this->activeUserExists($conn, $userId)) {
            $this->error('User not found', 404);
            return;
        }
        if (!$this->canViewOtherUserSchedule($conn, $actorId, $userId)) {
            $this->error('Forbidden', 403);
            return;
        }

        $out = $this->buildPublishedScheduleEntries($conn, $userId, (string) $from, (string) $to);

        Flight::json([
            'error_code' => 0,
            'status' => 'success',
            'message' => 'Schedule retrieved',
            'data' => ['entries' => $out],
        ]);
    }

    /** @return string|null Error message for 400, or null if OK */
    private function validateScheduleQueryRange(mixed $from, mixed $to): ?string
    {
        if (!$from || !is_string($from) || !$to || !is_string($to)) {
            return 'from and to query parameters required (YYYY-MM-DD)';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            return 'from and to must be YYYY-MM-DD';
        }
        if ($from > $to) {
            return 'from must be <= to';
        }
        $d0 = new DateTimeImmutable($from);
        $d1 = new DateTimeImmutable($to);
        $inclusiveDays = (int) $d0->diff($d1)->format('%a') + 1;
        if ($inclusiveDays > self::MAX_SCHEDULE_RANGE_DAYS) {
            return 'Date range must not exceed ' . self::MAX_SCHEDULE_RANGE_DAYS . ' days';
        }

        return null;
    }

    /**
     * Match TaskController::formatTaskAddressForResponse for nested task in schedule entries.
     */
    private function formatTaskAddressForSchedule(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }
        $decoded = json_decode($trimmed, true);
        if (is_string($decoded)) {
            return $decoded === '' ? null : $decoded;
        }
        if (is_array($decoded)) {
            $enc = json_encode($decoded, JSON_UNESCAPED_UNICODE);

            return ($enc === false || $enc === '') ? null : $enc;
        }

        return $trimmed;
    }

    /** @return list<array<string, mixed>> Same entry shape for /me/schedule and /users/{id}/schedule */
    private function buildPublishedScheduleEntries(Connection $conn, int $userId, string $from, string $to): array
    {
        $sql = '
            SELECT s.id, s.project_id, s.user_id, s.task_id, s.work_date, s.day_part, s.schedule_week_id,
                   t.name AS task_name, t.status AS task_status, t.project_id AS task_project_id,
                   t.address AS task_address,
                   p.prj_name AS project_name
            FROM fw_worker_task_schedules s
            INNER JOIN fw_schedule_weeks w ON w.id = s.schedule_week_id
            INNER JOIN fw_prj_tasks t ON t.id = s.task_id
            INNER JOIN fw_projects p ON p.id = s.project_id
            WHERE s.user_id = ?
              AND w.status = \'published\'
              AND s.work_date >= ?
              AND s.work_date <= ?
            ORDER BY s.work_date, CASE s.day_part WHEN \'am\' THEN 1 WHEN \'pm\' THEN 2 WHEN \'full\' THEN 3 ELSE 4 END, s.id
        ';
        $rows = $conn->executeQuery($sql, [$userId, $from, $to])->fetchAllAssociative();

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'project_id' => (int) $r['project_id'],
                'user_id' => (int) $r['user_id'],
                'task_id' => (int) $r['task_id'],
                'work_date' => $r['work_date'],
                'day_part' => $r['day_part'],
                'schedule_week_id' => (int) $r['schedule_week_id'],
                'project_name' => $r['project_name'] !== null && $r['project_name'] !== '' ? (string) $r['project_name'] : null,
                'task' => [
                    'id' => (int) $r['task_id'],
                    'name' => $r['task_name'],
                    'project_id' => (int) $r['task_project_id'],
                    'status' => $r['task_status'],
                    'address' => $this->formatTaskAddressForSchedule(
                        isset($r['task_address']) && $r['task_address'] !== '' && $r['task_address'] !== null
                            ? (string) $r['task_address']
                            : null
                    ),
                ],
            ];
        }

        return $out;
    }

    private function activeUserExists(Connection $conn, int $userId): bool
    {
        $id = $conn->executeQuery(
            'SELECT id FROM fw_v_users WHERE id = ? AND archived_at IS NULL',
            [$userId]
        )->fetchOne();

        return (bool) $id;
    }

    /** Whether $actor may load published schedule slots for $target (cross-project). */
    private function canViewOtherUserSchedule(Connection $conn, int $actorId, int $targetUserId): bool
    {
        if ($actorId === $targetUserId) {
            return true;
        }
        $role = $this->getRoleCode($conn, $actorId);
        if (in_array($role, ['admin', 'project_manager'], true)) {
            return true;
        }

        $one = $conn->executeQuery(
            'SELECT 1
             FROM fw_projects p
             INNER JOIN fw_prj_team_members tm ON tm.project_id = p.id AND tm.user_id = ?
             WHERE p.prj_manager = ?
             LIMIT 1',
            [$targetUserId, $actorId]
        )->fetchOne();

        return (bool) $one;
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

    /**
     * Monday of the ISO week containing the given calendar date.
     */
    private function normalizeWeekStartDate(string $ymd): string
    {
        $dt = new DateTimeImmutable($ymd);
        $dow = (int) $dt->format('N');
        if ($dow !== 1) {
            $dt = $dt->modify('-' . ($dow - 1) . ' days');
        }

        return $dt->format('Y-m-d');
    }

    /** week_start is stored as Monday YYYY-MM-DD; work_date must be that Monday through Sunday inclusive. */
    private function workDateInScheduleWeek(string $weekStartMondayYmd, string $workDateYmd): bool
    {
        $start = new DateTimeImmutable($weekStartMondayYmd);
        $end = $start->modify('+6 days');
        $d = new DateTimeImmutable($workDateYmd);

        return $d >= $start && $d <= $end;
    }

    private function projectExists(Connection $conn, int $projectId): bool
    {
        $id = $conn->executeQuery('SELECT id FROM fw_projects WHERE id = ?', [$projectId])->fetchOne();

        return (bool) $id;
    }

    private function getRoleCode(Connection $conn, int $userId): ?string
    {
        $r = $conn->executeQuery(
            'SELECT role_code FROM fw_v_users WHERE id = ? AND archived_at IS NULL',
            [$userId]
        )->fetchAssociative();

        return $r['role_code'] ?? null;
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

    private function taskBelongsToProject(Connection $conn, int $taskId, int $projectId): bool
    {
        $id = $conn->executeQuery(
            'SELECT id FROM fw_prj_tasks WHERE id = ? AND project_id = ?',
            [$taskId, $projectId]
        )->fetchOne();

        return (bool) $id;
    }

    private function userIsTaskAssignee(Connection $conn, int $userId, int $taskId, int $projectId): bool
    {
        $one = $conn->executeQuery(
            'SELECT 1 FROM fw_prj_team_members
             WHERE project_id = ? AND task_id = ? AND user_id = ? LIMIT 1',
            [$projectId, $taskId, $userId]
        )->fetchOne();

        return (bool) $one;
    }

    private function formatWeekRow(array $week): array
    {
        return [
            'id' => (int) $week['id'],
            'project_id' => (int) $week['project_id'],
            'week_start' => $week['week_start'],
            'status' => $week['status'],
            'published_at' => $week['published_at'] ?? null,
            'published_by' => isset($week['published_by']) && $week['published_by'] !== null
                ? (int) $week['published_by']
                : null,
            'created_at' => $week['created_at'] ?? null,
            'updated_at' => $week['updated_at'] ?? null,
        ];
    }

    private function formatEntryRow(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'user_id' => (int) $r['user_id'],
            'task_id' => (int) $r['task_id'],
            'work_date' => $r['work_date'],
            'day_part' => $r['day_part'],
            'schedule_week_id' => (int) $r['schedule_week_id'],
            'project_id' => (int) $r['project_id'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function fetchEntryRowsForWeek(Connection $conn, int $weekId): array
    {
        return $conn->executeQuery(
            'SELECT id, user_id, task_id, work_date, day_part, schedule_week_id, project_id
             FROM fw_worker_task_schedules
             WHERE schedule_week_id = ?
             ORDER BY work_date, CASE day_part WHEN \'am\' THEN 1 WHEN \'pm\' THEN 2 WHEN \'full\' THEN 3 ELSE 4 END, id',
            [$weekId]
        )->fetchAllAssociative();
    }
}
