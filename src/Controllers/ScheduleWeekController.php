<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use App\Services\TaskRosterForScheduleService;
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

    private const ASSIGNMENT_NOTE_MAX_LEN = 2000;

    private const DISTANCE_KM_MAX_LEN = 32;

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
     * Body: { "entries": [ { "user_id", "work_date", "day_part", "assignment_note?", "distance_km?",
     *   "expected_start_time?", "expected_end_time?" }, ... ] }
     * Schedule = presence on this project (person + day). Tasks are independent — task_id is ignored.
     * expected_* = PM planned day times; work_start_at / work_end_at = actual clock-in from phone.
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
     *                     @OA\Property(property="work_date", type="string", format="date"),
     *                     @OA\Property(property="day_part", type="string", enum={"am","pm","full"}),
     *                     @OA\Property(property="assignment_note", type="string", nullable=true, maxLength=2000),
     *                     @OA\Property(property="distance_km", type="string", nullable=true),
     *                     @OA\Property(property="expected_start_time", type="string", nullable=true, description="HH:MM or HH:MM:SS"),
     *                     @OA\Property(property="expected_end_time", type="string", nullable=true, description="HH:MM or HH:MM:SS"),
     *                     @OA\Property(property="task_id", type="integer", nullable=true, description="Ignored; schedule is project-scoped")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=200, description="OK"),
     *     @OA\Response(response=400, description="Validation"),
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

        $roster = new TaskRosterForScheduleService();

        $normalized = [];
        $slotKeys = [];
        foreach ($entries as $i => $row) {
            if (!is_array($row)) {
                $this->error("entries[{$i}] must be an object", 400);
                return;
            }
            $wid = isset($row['user_id']) ? (int) $row['user_id'] : 0;
            // Timesheet rows are project + place + day only; task_id is ignored (legacy column).
            $tid = null;
            $wdate = $row['work_date'] ?? null;
            $dp = $row['day_part'] ?? null;
            if ($wid <= 0 || !$wdate || !is_string($wdate) || !$dp || !is_string($dp)) {
                $this->error($this->entryValidationMessage($i, $row, 'user_id, work_date, day_part required'), 400);
                return;
            }
            if (!$roster->userExistsActive($conn, $wid)) {
                $this->error($this->entryValidationMessage($i, $row, 'user not found or archived'), 400);
                return;
            }
            if (!$roster->isUserProjectParticipant($conn, $projectId, $wid)) {
                $this->error(
                    $this->entryValidationMessage($i, $row, 'user is not a member of this project'),
                    400
                );
                return;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $wdate)) {
                $this->error($this->entryValidationMessage($i, $row, 'work_date must be YYYY-MM-DD'), 400);
                return;
            }
            $dp = strtolower(trim($dp));
            if (!in_array($dp, self::DAY_PARTS, true)) {
                $this->error($this->entryValidationMessage($i, $row, 'day_part must be one of: am, pm, full'), 400);
                return;
            }
            if (!$this->workDateInScheduleWeek((string) $week['week_start'], $wdate)) {
                $this->error(
                    $this->entryValidationMessage(
                        $i,
                        $row,
                        'work_date must fall within the schedule week (Monday–Sunday of week_start)'
                    ),
                    400
                );
                return;
            }
            $noteRaw = $row['assignment_note'] ?? null;
            if ($noteRaw !== null && $noteRaw !== '' && !is_string($noteRaw)) {
                $this->error($this->entryValidationMessage($i, $row, 'assignment_note must be a string or null'), 400);
                return;
            }
            $assignmentNote = null;
            if (is_string($noteRaw)) {
                $assignmentNote = trim($noteRaw);
                if ($assignmentNote === '') {
                    $assignmentNote = null;
                } elseif ($this->stringCharLength($assignmentNote) > self::ASSIGNMENT_NOTE_MAX_LEN) {
                    $this->error(
                        $this->entryValidationMessage(
                            $i,
                            $row,
                            'assignment_note must not exceed ' . self::ASSIGNMENT_NOTE_MAX_LEN . ' characters'
                        ),
                        400
                    );
                    return;
                }
            }
            $distanceKm = $this->normalizeDistanceKmInput($row['distance_km'] ?? null);
            if ($distanceKm === false) {
                $this->error(
                    $this->entryValidationMessage(
                        $i,
                        $row,
                        'distance_km must be a string ≤ ' . self::DISTANCE_KM_MAX_LEN . ' characters or null'
                    ),
                    400
                );
                return;
            }
            $expectedStart = $this->normalizeExpectedTimeInput($row['expected_start_time'] ?? null);
            if ($expectedStart === false) {
                $this->error(
                    $this->entryValidationMessage($i, $row, 'expected_start_time must be HH:MM or HH:MM:SS or null'),
                    400
                );
                return;
            }
            $expectedEnd = $this->normalizeExpectedTimeInput($row['expected_end_time'] ?? null);
            if ($expectedEnd === false) {
                $this->error(
                    $this->entryValidationMessage($i, $row, 'expected_end_time must be HH:MM or HH:MM:SS or null'),
                    400
                );
                return;
            }
            $k = $wid . '|' . $wdate . '|' . $dp;
            if (isset($slotKeys[$k])) {
                $this->error(
                    "Duplicate slot in payload (user_id={$wid}, work_date={$wdate}, day_part={$dp}): "
                    . 'same user_id, work_date, day_part',
                    409
                );
                return;
            }
            $slotKeys[$k] = true;
            $normalized[] = [
                'user_id' => $wid,
                'task_id' => $tid,
                'work_date' => $wdate,
                'day_part' => $dp,
                'assignment_note' => $assignmentNote,
                'distance_km' => $distanceKm,
                'expected_start_time' => $expectedStart,
                'expected_end_time' => $expectedEnd,
            ];
        }

        try {
            $conn->beginTransaction();

            // Merge by slot key (user_id + work_date + day_part) so primary keys stay stable across saves.
            $existingRows = $conn->executeQuery(
                'SELECT id, user_id, task_id, work_date, day_part, assignment_note
                 FROM fw_worker_task_schedules
                 WHERE schedule_week_id = ?',
                [$weekId]
            )->fetchAllAssociative();

            $bySlotKey = [];
            foreach ($existingRows as $row) {
                $wd = $row['work_date'];
                if ($wd instanceof \DateTimeInterface) {
                    $wd = $wd->format('Y-m-d');
                } else {
                    $wd = (string) $wd;
                }
                $slotKey = (int) $row['user_id'] . '|' . $wd . '|' . (string) $row['day_part'];
                $bySlotKey[$slotKey] = $row;
            }

            $idsStillPresent = [];
            foreach ($normalized as $e) {
                $slotKey = $e['user_id'] . '|' . $e['work_date'] . '|' . $e['day_part'];
                if (isset($bySlotKey[$slotKey])) {
                    $existingId = (int) $bySlotKey[$slotKey]['id'];
                    $idsStillPresent[$existingId] = true;
                    $conn->executeStatement(
                        'UPDATE fw_worker_task_schedules
                         SET task_id = ?, assignment_note = ?, distance_km = ?,
                             expected_start_time = ?, expected_end_time = ?, updated_at = NOW(3)
                         WHERE id = ? AND schedule_week_id = ? AND project_id = ?',
                        [
                            $e['task_id'],
                            $e['assignment_note'],
                            $e['distance_km'],
                            $e['expected_start_time'],
                            $e['expected_end_time'],
                            $existingId,
                            $weekId,
                            $projectId,
                        ]
                    );
                } else {
                    $conn->insert('fw_worker_task_schedules', [
                        'schedule_week_id' => $weekId,
                        'project_id' => $projectId,
                        'user_id' => $e['user_id'],
                        'task_id' => $e['task_id'],
                        'work_date' => $e['work_date'],
                        'day_part' => $e['day_part'],
                        'assignment_note' => $e['assignment_note'],
                        'distance_km' => $e['distance_km'],
                        'expected_start_time' => $e['expected_start_time'],
                        'expected_end_time' => $e['expected_end_time'],
                    ]);
                }
            }

            $idsToRemove = [];
            foreach ($existingRows as $row) {
                $rowId = (int) $row['id'];
                if (!isset($idsStillPresent[$rowId])) {
                    $idsToRemove[] = $rowId;
                }
            }
            if ($idsToRemove !== []) {
                $placeholders = implode(',', array_fill(0, count($idsToRemove), '?'));
                $conn->executeStatement(
                    "DELETE FROM fw_worker_task_schedules WHERE schedule_week_id = ? AND id IN ($placeholders)",
                    array_merge([$weekId], $idsToRemove)
                );
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
        $roster = new TaskRosterForScheduleService();
        foreach ($rows as $row) {
            $ws = (string) $week['week_start'];
            $uid = (int) $row['user_id'];
            $wd = (string) $row['work_date'];
            if (!$this->workDateInScheduleWeek($ws, $wd)) {
                $this->error(
                    "Invalid entry (user_id={$uid}, work_date={$wd}): work_date outside week",
                    400
                );
                return;
            }
            if (!$roster->userExistsActive($conn, $uid)) {
                $this->error(
                    "Invalid entry (user_id={$uid}, work_date={$wd}): user not found or archived",
                    400
                );
                return;
            }
            if (!$roster->isUserProjectParticipant($conn, $projectId, $uid)) {
                $this->error(
                    "Invalid entry (user_id={$uid}, work_date={$wd}): user is not a member of this project",
                    400
                );
                return;
            }
            $tid = $row['task_id'] !== null ? (int) $row['task_id'] : 0;
            if ($tid > 0 && !$this->taskBelongsToProject($conn, $tid, $projectId)) {
                $this->error(
                    "Invalid entry (user_id={$uid}, task_id={$tid}, work_date={$wd}): task not in project",
                    400
                );
                return;
            }
        }

        try {
            $conn->beginTransaction();
            $this->replacePublishedSnapshot($conn, $weekId);
            $conn->executeStatement(
                'UPDATE fw_schedule_weeks SET status = ?, published_at = NOW(3), published_by = ?, updated_at = NOW(3) WHERE id = ?',
                ['published', $actorId, $weekId]
            );
            $conn->commit();
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->logger->error('publishWeek failed', ['error' => $e->getMessage(), 'week_id' => $weekId]);
            $this->error('Failed to publish schedule week', 500);
            return;
        }

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
     * POST /api/v1/projects/{projectId}/schedule-weeks/{weekId}/reopen-as-draft
     *
     * @OA\Post(
     *     path="/api/v1/projects/{project_id}/schedule-weeks/{week_id}/reopen-as-draft",
     *     tags={"Schedule"},
     *     summary="Reopen a published week as draft (same row, clear publish metadata)",
     *     description="Body may be empty. Live entries are unchanged. Workers still see the last published snapshot via GET /me/schedule until the week is published again.",
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(name="project_id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="week_id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK — same shape as GET week (schedule_week + entries)"),
     *     @OA\Response(response=400, description="Week is already draft"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Not found")
     * )
     */
    public function reopenAsDraft(int $projectId, int $weekId): void
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
            'SELECT id, project_id, week_start, status, published_at, published_by, created_at, updated_at
             FROM fw_schedule_weeks WHERE id = ? AND project_id = ?',
            [$weekId, $projectId]
        )->fetchAssociative();
        if (!$week) {
            $this->error('Schedule week not found', 404);
            return;
        }
        if (($week['status'] ?? '') !== 'published') {
            $this->error('Week is not published; only a published week can be reopened as draft', 400);
            return;
        }

        $conn->executeStatement(
            'UPDATE fw_schedule_weeks SET status = ?, published_at = NULL, published_by = NULL, updated_at = NOW(3) WHERE id = ?',
            ['draft', $weekId]
        );

        $weekFull = $conn->executeQuery(
            'SELECT id, project_id, week_start, status, published_at, published_by, created_at, updated_at
             FROM fw_schedule_weeks WHERE id = ?',
            [$weekId]
        )->fetchAssociative();

        $entries = $this->fetchEntryRowsForWeek($conn, $weekId);

        Flight::json([
            'error_code' => 0,
            'status' => 'success',
            'message' => 'Schedule week reopened as draft',
            'data' => [
                'schedule_week' => $this->formatWeekRow($weekFull ?: $week),
                'entries' => array_map(fn ($r) => $this->formatEntryRow($r), $entries),
            ],
        ]);
    }

    /** Replace rows in fw_worker_task_schedule_snapshots from current live slots for the week. */
    private function replacePublishedSnapshot(Connection $conn, int $weekId): void
    {
        $conn->executeStatement(
            'DELETE FROM fw_worker_task_schedule_snapshots WHERE schedule_week_id = ?',
            [$weekId]
        );
        $conn->executeStatement(
            'INSERT INTO fw_worker_task_schedule_snapshots
                (worker_task_schedule_id, schedule_week_id, project_id, user_id, task_id, work_date, day_part, assignment_note, snapshot_at)
             SELECT id, schedule_week_id, project_id, user_id, task_id, work_date, day_part, assignment_note, NOW(3)
             FROM fw_worker_task_schedules WHERE schedule_week_id = ?',
            [$weekId]
        );
    }

    /**
     * GET /api/v1/me/schedule?from=YYYY-MM-DD&to=YYYY-MM-DD
     *
     * @OA\Get(
     *     path="/api/v1/me/schedule",
     *     tags={"Schedule"},
     *     summary="Current user schedule (last published snapshot per week)",
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
     *     summary="User schedule across projects (last published snapshot per week)",
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

    /**
     * POST /api/v1/me/schedule-entries/{entryId}/check-in
     * Body: { "phase": "start"|"end", "lat": number, "lng": number }
     *
     * @OA\Post(
     *     path="/api/v1/me/schedule-entries/{entry_id}/check-in",
     *     tags={"Schedule"},
     *     summary="Worker geo check-in at start or end of scheduled day",
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(name="entry_id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"phase","lat","lng"},
     *             @OA\Property(property="phase", type="string", enum={"start","end"}),
     *             @OA\Property(property="lat", type="number"),
     *             @OA\Property(property="lng", type="number")
     *         )
     *     ),
     *     @OA\Response(response=200, description="OK"),
     *     @OA\Response(response=400, description="Validation error"),
     *     @OA\Response(response=403, description="Forbidden"),
     *     @OA\Response(response=404, description="Entry not found"),
     *     @OA\Response(response=409, description="Conflict")
     * )
     */
    public function checkInMyScheduleEntry(int $entryId): void
    {
        if ($entryId <= 0) {
            $this->error('Invalid entry id', 400);
            return;
        }
        $payload = json_decode(Flight::request()->getBody(), true) ?? [];
        $phase = isset($payload['phase']) && is_string($payload['phase'])
            ? strtolower(trim($payload['phase']))
            : '';
        if ($phase !== 'start' && $phase !== 'end') {
            $this->error('phase must be start or end', 400);
            return;
        }
        if (!isset($payload['lat'], $payload['lng']) || !is_numeric($payload['lat']) || !is_numeric($payload['lng'])) {
            $this->error('lat and lng are required numbers', 400);
            return;
        }
        $lat = (float) $payload['lat'];
        $lng = (float) $payload['lng'];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            $this->error('lat/lng out of range', 400);
            return;
        }

        $userId = (int) Flight::get('current_user')['id'];
        $conn = Database::getConnection();
        $row = $conn->executeQuery(
            'SELECT e.id, e.user_id, e.project_id, e.schedule_week_id, e.work_date, e.day_part,
                    e.assignment_note, e.distance_km,
                    e.expected_start_time, e.expected_end_time,
                    e.work_start_lat, e.work_start_lng, e.work_start_at,
                    e.work_end_lat, e.work_end_lng, e.work_end_at,
                    e.task_id,
                    p.latitude AS project_latitude, p.longitude AS project_longitude
             FROM fw_worker_task_schedules e
             INNER JOIN fw_projects p ON p.id = e.project_id
             WHERE e.id = ?',
            [$entryId]
        )->fetchAssociative();
        if (!$row) {
            $this->error('Schedule entry not found', 404);
            return;
        }
        if ((int) $row['user_id'] !== $userId) {
            $this->error('Forbidden', 403);
            return;
        }

        if ($phase === 'start') {
            if ($row['work_start_at'] !== null) {
                $this->error('Start already recorded for this day', 409);
                return;
            }
            $conn->executeStatement(
                'UPDATE fw_worker_task_schedules
                 SET work_start_lat = ?, work_start_lng = ?, work_start_at = NOW(3), updated_at = NOW(3)
                 WHERE id = ? AND user_id = ?',
                [$lat, $lng, $entryId, $userId]
            );
        } else {
            if ($row['work_start_at'] === null) {
                $this->error('Start work before ending', 409);
                return;
            }
            if ($row['work_end_at'] !== null) {
                $this->error('End already recorded for this day', 409);
                return;
            }
            $conn->executeStatement(
                'UPDATE fw_worker_task_schedules
                 SET work_end_lat = ?, work_end_lng = ?, work_end_at = NOW(3), updated_at = NOW(3)
                 WHERE id = ? AND user_id = ?',
                [$lat, $lng, $entryId, $userId]
            );
        }

        $saved = $conn->executeQuery(
            'SELECT e.id, e.user_id, e.task_id, e.work_date, e.day_part, e.schedule_week_id, e.project_id,
                    e.assignment_note, e.distance_km,
                    e.expected_start_time, e.expected_end_time,
                    e.work_start_lat, e.work_start_lng, e.work_start_at,
                    e.work_end_lat, e.work_end_lng, e.work_end_at,
                    p.latitude AS project_latitude, p.longitude AS project_longitude
             FROM fw_worker_task_schedules e
             INNER JOIN fw_projects p ON p.id = e.project_id
             WHERE e.id = ?',
            [$entryId]
        )->fetchAssociative();

        Flight::json([
            'error_code' => 0,
            'status' => 'success',
            'message' => $phase === 'start' ? 'Work start recorded' : 'Work end recorded',
            'data' => [
                'entry' => $saved ? $this->formatEntryRow($saved) : null,
            ],
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

    /**
     * Entries from `fw_worker_task_schedule_snapshots` (refreshed on publish) so workers keep seeing the last
     * published plan after the PM reopens the week as draft.
     *
     * @return list<array<string, mixed>> Same shape for /me/schedule and /users/{id}/schedule.
     *         `id` and `worker_task_schedule_id` match `fw_worker_task_schedules.id` when snapshot was built after
     *         `worker_task_schedule_id` column exists; otherwise `id` falls back to snapshot row PK until republish.
     */
    private function buildPublishedScheduleEntries(Connection $conn, int $userId, string $from, string $to): array
    {
        $sql = '
            SELECT s.id AS snapshot_row_id, s.worker_task_schedule_id, s.project_id, s.user_id, s.task_id, s.work_date, s.day_part, s.schedule_week_id,
                   s.assignment_note,
                   live.distance_km AS live_distance_km,
                   live.expected_start_time, live.expected_end_time,
                   live.work_start_lat, live.work_start_lng, live.work_start_at,
                   live.work_end_lat, live.work_end_lng, live.work_end_at,
                   t.name AS task_name, t.status AS task_status, t.project_id AS task_project_id,
                   t.address AS task_address,
                   p.prj_name AS project_name,
                   p.address AS project_address,
                   p.latitude AS project_latitude,
                   p.longitude AS project_longitude
            FROM fw_worker_task_schedule_snapshots s
            INNER JOIN fw_schedule_weeks w ON w.id = s.schedule_week_id
            INNER JOIN fw_projects p ON p.id = s.project_id
            LEFT JOIN fw_worker_task_schedules live ON live.id = s.worker_task_schedule_id
            LEFT JOIN fw_prj_tasks t ON t.id = s.task_id
            WHERE s.user_id = ?
              AND s.work_date >= ?
              AND s.work_date <= ?
            ORDER BY s.work_date, CASE s.day_part WHEN \'am\' THEN 1 WHEN \'pm\' THEN 2 WHEN \'full\' THEN 3 ELSE 4 END, s.id
        ';
        $rows = $conn->executeQuery($sql, [$userId, $from, $to])->fetchAllAssociative();

        $out = [];
        foreach ($rows as $r) {
            $snapshotRowId = (int) $r['snapshot_row_id'];
            $liveSlotId = isset($r['worker_task_schedule_id']) && $r['worker_task_schedule_id'] !== null
                ? (int) $r['worker_task_schedule_id']
                : null;
            $unifiedId = $liveSlotId ?? $snapshotRowId;
            $taskId = $r['task_id'] !== null ? (int) $r['task_id'] : null;
            $projectAddress = isset($r['project_address']) && $r['project_address'] !== null && trim((string) $r['project_address']) !== ''
                ? trim((string) $r['project_address'])
                : null;
            $trip = $this->formatTripFieldsFromRow($r);
            $entry = [
                'id' => $unifiedId,
                'worker_task_schedule_id' => $liveSlotId,
                'project_id' => (int) $r['project_id'],
                'user_id' => (int) $r['user_id'],
                'task_id' => $taskId,
                'work_date' => $r['work_date'],
                'day_part' => $r['day_part'],
                'schedule_week_id' => (int) $r['schedule_week_id'],
                'assignment_note' => isset($r['assignment_note']) && $r['assignment_note'] !== null && $r['assignment_note'] !== ''
                    ? (string) $r['assignment_note']
                    : null,
                'project_name' => $r['project_name'] !== null && $r['project_name'] !== '' ? (string) $r['project_name'] : null,
                'project_address' => $projectAddress,
                ...$trip,
            ];
            // Prefer live distance_km when present
            if (isset($r['live_distance_km']) && $r['live_distance_km'] !== null && (string) $r['live_distance_km'] !== '') {
                $entry['distance_km'] = (string) $r['live_distance_km'];
            }
            if ($taskId !== null && $taskId > 0 && $r['task_name'] !== null) {
                $entry['task'] = [
                    'id' => $taskId,
                    'name' => $r['task_name'],
                    'project_id' => (int) ($r['task_project_id'] ?? $r['project_id']),
                    'status' => $r['task_status'] ?? '',
                    'address' => $this->formatTaskAddressForSchedule(
                        isset($r['task_address']) && $r['task_address'] !== '' && $r['task_address'] !== null
                            ? (string) $r['task_address']
                            : null
                    ),
                ];
            } else {
                $entry['task'] = null;
            }
            $out[] = $entry;
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

    /**
     * @param array<string, mixed> $row
     */
    private function entryValidationMessage(int $index, array $row, string $detail): string
    {
        $wid = isset($row['user_id']) ? (int) $row['user_id'] : 0;
        $tid = isset($row['task_id']) ? (int) $row['task_id'] : 0;
        $wd = isset($row['work_date']) && is_string($row['work_date']) ? $row['work_date'] : '?';

        return "entries[{$index}] (user_id={$wid}, task_id={$tid}, work_date={$wd}): {$detail}";
    }

    private function stringCharLength(string $s): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($s);
        }

        return strlen($s);
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

    /** `id` / `worker_task_schedule_id` = `fw_worker_task_schedules.id` (no other column). */
    private function formatEntryRow(array $r): array
    {
        $slotPk = (int) $r['id'];
        $taskId = isset($r['task_id']) && $r['task_id'] !== null && (int) $r['task_id'] > 0
            ? (int) $r['task_id']
            : null;

        return [
            'id' => $slotPk,
            'worker_task_schedule_id' => $slotPk,
            'user_id' => (int) $r['user_id'],
            'task_id' => $taskId,
            'work_date' => $r['work_date'],
            'day_part' => $r['day_part'],
            'schedule_week_id' => (int) $r['schedule_week_id'],
            'project_id' => (int) $r['project_id'],
            'assignment_note' => isset($r['assignment_note']) && $r['assignment_note'] !== null && $r['assignment_note'] !== ''
                ? (string) $r['assignment_note']
                : null,
            ...$this->formatTripFieldsFromRow($r),
        ];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function formatTripFieldsFromRow(array $r): array
    {
        $distanceKm = null;
        if (isset($r['distance_km']) && $r['distance_km'] !== null && (string) $r['distance_km'] !== '') {
            $distanceKm = (string) $r['distance_km'];
        } elseif (isset($r['live_distance_km']) && $r['live_distance_km'] !== null && (string) $r['live_distance_km'] !== '') {
            $distanceKm = (string) $r['live_distance_km'];
        }

        $siteLat = $this->nullableFloat($r['project_latitude'] ?? null);
        $siteLng = $this->nullableFloat($r['project_longitude'] ?? null);
        $startLat = $this->nullableFloat($r['work_start_lat'] ?? null);
        $startLng = $this->nullableFloat($r['work_start_lng'] ?? null);
        $endLat = $this->nullableFloat($r['work_end_lat'] ?? null);
        $endLng = $this->nullableFloat($r['work_end_lng'] ?? null);

        return [
            'distance_km' => $distanceKm,
            'expected_start_time' => $this->formatExpectedTimeForResponse($r['expected_start_time'] ?? null),
            'expected_end_time' => $this->formatExpectedTimeForResponse($r['expected_end_time'] ?? null),
            'work_start_lat' => $startLat,
            'work_start_lng' => $startLng,
            'work_start_at' => $r['work_start_at'] ?? null,
            'work_end_lat' => $endLat,
            'work_end_lng' => $endLng,
            'work_end_at' => $r['work_end_at'] ?? null,
            'work_start_distance_km' => $this->haversineKm($startLat, $startLng, $siteLat, $siteLng),
            'work_end_distance_km' => $this->haversineKm($endLat, $endLng, $siteLat, $siteLng),
        ];
    }

    /**
     * @return string|null|false null = clear; string HH:MM:SS = value; false = invalid
     */
    private function normalizeExpectedTimeInput(mixed $raw): string|null|false
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw) && !is_numeric($raw)) {
            return false;
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $s, $m) !== 1) {
            return false;
        }
        $hh = (int) $m[1];
        $mm = (int) $m[2];
        $ss = isset($m[3]) ? (int) $m[3] : 0;
        if ($hh > 23 || $mm > 59 || $ss > 59) {
            return false;
        }

        return sprintf('%02d:%02d:%02d', $hh, $mm, $ss);
    }

    private function formatExpectedTimeForResponse(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if ($raw instanceof \DateTimeInterface) {
            return $raw->format('H:i');
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})/', $s, $m) === 1) {
            return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        }

        return null;
    }

    private function nullableFloat(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_numeric($v)) {
            return null;
        }
        $f = (float) $v;
        return is_finite($f) ? $f : null;
    }

    private function haversineKm(?float $lat1, ?float $lng1, ?float $lat2, ?float $lng2): ?float
    {
        if ($lat1 === null || $lng1 === null || $lat2 === null || $lng2 === null) {
            return null;
        }
        $earthKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return round($earthKm * $c, 2);
    }

    /**
     * @return string|null|false null = clear; string = value; false = invalid
     */
    private function normalizeDistanceKmInput(mixed $raw): string|null|false
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw) && !is_numeric($raw)) {
            return false;
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }
        if ($this->stringCharLength($s) > self::DISTANCE_KM_MAX_LEN) {
            return false;
        }
        return $s;
    }

    /**
     * Live slot rows for a week. {@see formatEntryRow} maps `id` → JSON `entries[].id` = PK `fw_worker_task_schedules.id` only.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchEntryRowsForWeek(Connection $conn, int $weekId): array
    {
        return $conn->executeQuery(
            'SELECT e.id, e.user_id, e.task_id, e.work_date, e.day_part, e.schedule_week_id, e.project_id,
                    e.assignment_note, e.distance_km,
                    e.expected_start_time, e.expected_end_time,
                    e.work_start_lat, e.work_start_lng, e.work_start_at,
                    e.work_end_lat, e.work_end_lng, e.work_end_at,
                    p.latitude AS project_latitude, p.longitude AS project_longitude
             FROM fw_worker_task_schedules e
             LEFT JOIN fw_projects p ON p.id = e.project_id
             WHERE e.schedule_week_id = ?
             ORDER BY e.work_date, CASE e.day_part WHEN \'am\' THEN 1 WHEN \'pm\' THEN 2 WHEN \'full\' THEN 3 ELSE 4 END, e.id',
            [$weekId]
        )->fetchAllAssociative();
    }
}
