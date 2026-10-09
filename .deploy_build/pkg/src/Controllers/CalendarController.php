<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use Flight;
use Monolog\Logger;

/**
 * User calendar events (personal + per-project). Not linked to fw_prj_tasks.
 */
class CalendarController
{
    public function __construct(private readonly Logger $logger) {}

    /** GET /api/v1/calendar/events — all events for current user (global view). */
    public function listGlobal(): void
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return;
        }

        [$from, $to] = $this->parseDateRange();
        $events = $this->fetchEvents(
            'e.user_id = ?' . $this->dateRangeSql('e', $from, $to),
            $this->bindWithRange([$userId], $from, $to),
            'global',
        );

        $this->jsonSuccess('Calendar events retrieved', ['events' => $events]);
    }

    /** GET /api/v1/projects/{id}/calendar/events — global (read-only) + this project. */
    public function listForProject(int $projectId): void
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return;
        }

        if (!$this->projectExists($projectId)) {
            $this->jsonError('Project not found', 404);
            return;
        }

        [$from, $to] = $this->parseDateRange();
        $events = $this->fetchEvents(
            'e.user_id = ? AND (e.project_id IS NULL OR e.project_id = ?)' . $this->dateRangeSql('e', $from, $to),
            $this->bindWithRange([$userId, $projectId], $from, $to),
            'project',
            $projectId,
        );

        $this->jsonSuccess('Project calendar events retrieved', ['events' => $events]);
    }

    /**
     * GET /api/v1/calendar/availability — presence conflicts for a proposed time range.
     * Query: start_at, end_at (optional), exclude_event_id (optional), requires_presence (optional, default 1).
     */
    public function checkAvailability(): void
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return;
        }

        $query = Flight::request()->query;
        $startAt = isset($query['start_at']) ? trim((string) $query['start_at']) : '';
        $endAt = isset($query['end_at']) && $query['end_at'] !== ''
            ? trim((string) $query['end_at'])
            : null;
        $allDay = !empty($query['all_day']);
        $requiresPresence = !isset($query['requires_presence']) || filter_var(
            $query['requires_presence'],
            FILTER_VALIDATE_BOOLEAN,
        );

        if (!$requiresPresence) {
            $this->jsonSuccess('No availability check needed', ['conflicts' => []]);
            return;
        }

        if ($startAt === '') {
            $this->jsonError('start_at is required', 400);
            return;
        }

        $startNormalized = $this->normalizeDateTimeInput($startAt, $allDay, false);
        if ($startNormalized === null) {
            $this->jsonError('Invalid start_at', 400);
            return;
        }

        $endNormalized = null;
        if ($endAt !== null) {
            $endNormalized = $this->normalizeDateTimeInput($endAt, $allDay, true);
            if ($endNormalized === null) {
                $this->jsonError('Invalid end_at', 400);
                return;
            }
        }

        $excludeId = isset($query['exclude_event_id']) && $query['exclude_event_id'] !== ''
            ? (int) $query['exclude_event_id']
            : null;

        $conflicts = $this->findPresenceConflicts(
            $userId,
            $startNormalized,
            $this->effectiveEndAt($startNormalized, $endNormalized, $allDay),
            $excludeId,
        );

        $this->jsonSuccess('Availability checked', ['conflicts' => $conflicts]);
    }

    /** POST /api/v1/calendar/events — create personal (global) event. */
    public function createGlobal(): void
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return;
        }

        $payload = $this->parseEventPayload();
        if ($payload === null) {
            return;
        }

        if (!$this->assertNoPresenceConflicts($userId, $payload, null)) {
            return;
        }

        try {
            $conn = Database::getConnection();
            $conn->executeStatement(
                'INSERT INTO fw_calendar_events
                 (user_id, project_id, title, description, location, start_at, end_at, all_day, requires_presence)
                 VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $userId,
                    $payload['title'],
                    $payload['description'],
                    $payload['location'],
                    $payload['start_at'],
                    $payload['end_at'],
                    $payload['all_day'],
                    $payload['requires_presence'],
                ],
            );

            $id = (int) $conn->lastInsertId();
            $event = $this->fetchEventById($id, $userId, 'global');
            $this->jsonSuccess('Event created', ['event' => $event], 201);
        } catch (\Throwable $e) {
            $this->logAndFail('createGlobal', $e);
        }
    }

    /** POST /api/v1/projects/{id}/calendar/events — create project-scoped event. */
    public function createForProject(int $projectId): void
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return;
        }

        if (!$this->projectExists($projectId)) {
            $this->jsonError('Project not found', 404);
            return;
        }

        $payload = $this->parseEventPayload();
        if ($payload === null) {
            return;
        }

        if (!$this->assertNoPresenceConflicts($userId, $payload, null)) {
            return;
        }

        try {
            $conn = Database::getConnection();
            $conn->executeStatement(
                'INSERT INTO fw_calendar_events
                 (user_id, project_id, title, description, location, start_at, end_at, all_day, requires_presence)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $userId,
                    $projectId,
                    $payload['title'],
                    $payload['description'],
                    $payload['location'],
                    $payload['start_at'],
                    $payload['end_at'],
                    $payload['all_day'],
                    $payload['requires_presence'],
                ],
            );

            $id = (int) $conn->lastInsertId();
            $event = $this->fetchEventById($id, $userId, 'project', $projectId);
            $this->jsonSuccess('Event created', ['event' => $event], 201);
        } catch (\Throwable $e) {
            $this->logAndFail('createForProject', $e);
        }
    }

    /** PUT /api/v1/calendar/events/{id} — update personal event only. */
    public function updateGlobal(int $eventId): void
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return;
        }

        $existing = $this->getOwnedEvent($eventId, $userId);
        if ($existing === null) {
            $this->jsonError('Event not found', 404);
            return;
        }

        if ($existing['project_id'] !== null) {
            $this->jsonError('Project events can only be edited inside the project calendar', 403);
            return;
        }

        $this->applyUpdate($eventId, $userId, 'global', null);
    }

    /** PUT /api/v1/projects/{projectId}/calendar/events/{id} */
    public function updateForProject(int $projectId, int $eventId): void
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return;
        }

        $existing = $this->getOwnedEvent($eventId, $userId);
        if ($existing === null || (int) ($existing['project_id'] ?? 0) !== $projectId) {
            $this->jsonError('Event not found', 404);
            return;
        }

        $this->applyUpdate($eventId, $userId, 'project', $projectId);
    }

    /** DELETE /api/v1/calendar/events/{id} */
    public function deleteGlobal(int $eventId): void
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return;
        }

        $existing = $this->getOwnedEvent($eventId, $userId);
        if ($existing === null) {
            $this->jsonError('Event not found', 404);
            return;
        }

        if ($existing['project_id'] !== null) {
            $this->jsonError('Project events can only be deleted inside the project calendar', 403);
            return;
        }

        $this->applyDelete($eventId, $userId);
    }

    /** DELETE /api/v1/projects/{projectId}/calendar/events/{id} */
    public function deleteForProject(int $projectId, int $eventId): void
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            return;
        }

        $existing = $this->getOwnedEvent($eventId, $userId);
        if ($existing === null || (int) ($existing['project_id'] ?? 0) !== $projectId) {
            $this->jsonError('Event not found', 404);
            return;
        }

        $this->applyDelete($eventId, $userId);
    }

    private function applyUpdate(int $eventId, int $userId, string $viewMode, ?int $projectId = null): void
    {
        $payload = $this->parseEventPayload(true);
        if ($payload === null) {
            return;
        }

        if (!$this->assertNoPresenceConflicts($userId, $payload, $eventId)) {
            return;
        }

        try {
            $conn = Database::getConnection();
            $conn->executeStatement(
                'UPDATE fw_calendar_events
                 SET title = ?, description = ?, location = ?, start_at = ?, end_at = ?, all_day = ?, requires_presence = ?
                 WHERE id = ? AND user_id = ?',
                [
                    $payload['title'],
                    $payload['description'],
                    $payload['location'],
                    $payload['start_at'],
                    $payload['end_at'],
                    $payload['all_day'],
                    $payload['requires_presence'],
                    $eventId,
                    $userId,
                ],
            );

            $event = $this->fetchEventById($eventId, $userId, $viewMode, $projectId);
            $this->jsonSuccess('Event updated', ['event' => $event]);
        } catch (\Throwable $e) {
            $this->logAndFail('applyUpdate', $e);
        }
    }

    private function applyDelete(int $eventId, int $userId): void
    {
        try {
            $conn = Database::getConnection();
            $conn->executeStatement(
                'DELETE FROM fw_calendar_events WHERE id = ? AND user_id = ?',
                [$eventId, $userId],
            );
            $this->jsonSuccess('Event deleted', ['id' => $eventId]);
        } catch (\Throwable $e) {
            $this->logAndFail('applyDelete', $e);
        }
    }

    /**
     * @param array<int, mixed> $bind
     * @return list<array<string, mixed>>
     */
    private function fetchEvents(string $where, array $bind, string $viewMode, ?int $projectContextId = null): array
    {
        $conn = Database::getConnection();
        $sql = 'SELECT e.id, e.user_id, e.project_id, e.title, e.description, e.location,
                       e.start_at, e.end_at, e.all_day, e.requires_presence, e.created_at, e.updated_at,
                       p.prj_name AS project_name, p.address AS project_address
                FROM fw_calendar_events e
                LEFT JOIN fw_projects p ON p.id = e.project_id
                WHERE ' . $where . '
                ORDER BY e.start_at ASC';

        $rows = $conn->executeQuery($sql, $bind)->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->formatEventRow($row, $viewMode, $projectContextId);
        }
        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchEventById(int $eventId, int $userId, string $viewMode, ?int $projectContextId = null): ?array
    {
        $conn = Database::getConnection();
        $row = $conn->executeQuery(
            'SELECT e.id, e.user_id, e.project_id, e.title, e.description, e.location,
                    e.start_at, e.end_at, e.all_day, e.requires_presence, e.created_at, e.updated_at,
                    p.prj_name AS project_name, p.address AS project_address
             FROM fw_calendar_events e
             LEFT JOIN fw_projects p ON p.id = e.project_id
             WHERE e.id = ? AND e.user_id = ?',
            [$eventId, $userId],
        )->fetchAssociative();

        if (!$row) {
            return null;
        }

        return $this->formatEventRow($row, $viewMode, $projectContextId);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatEventRow(array $row, string $viewMode, ?int $projectContextId = null): array
    {
        $projectId = $row['project_id'] !== null ? (int) $row['project_id'] : null;
        $scope = $projectId === null ? 'global' : 'project';

        $editable = false;
        if ($viewMode === 'global') {
            $editable = $projectId === null;
        } elseif ($viewMode === 'project' && $projectContextId !== null) {
            $editable = $projectId === $projectContextId;
        }

        $projectAddress = null;
        if (isset($row['project_address']) && $row['project_address'] !== null && trim((string) $row['project_address']) !== '') {
            $projectAddress = trim((string) $row['project_address']);
        }

        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'project_id' => $projectId,
            'project_name' => $row['project_name'] ?? null,
            'project_address' => $projectAddress,
            'title' => (string) $row['title'],
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'location' => $row['location'] !== null ? (string) $row['location'] : null,
            'start_at' => $this->formatDateTime($row['start_at']),
            'end_at' => $row['end_at'] !== null ? $this->formatDateTime($row['end_at']) : null,
            'all_day' => (bool) (int) ($row['all_day'] ?? 0),
            'requires_presence' => (bool) (int) ($row['requires_presence'] ?? 0),
            'scope' => $scope,
            'editable' => $editable,
            'created_at' => $this->formatDateTime($row['created_at']),
            'updated_at' => $this->formatDateTime($row['updated_at']),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getOwnedEvent(int $eventId, int $userId): ?array
    {
        $conn = Database::getConnection();
        $row = $conn->executeQuery(
            'SELECT id, user_id, project_id FROM fw_calendar_events WHERE id = ? AND user_id = ?',
            [$eventId, $userId],
        )->fetchAssociative();

        return $row ?: null;
    }

    private function projectExists(int $projectId): bool
    {
        $conn = Database::getConnection();
        $id = $conn->executeQuery(
            'SELECT id FROM fw_projects WHERE id = ? LIMIT 1',
            [$projectId],
        )->fetchOne();

        return $id !== false && $id !== null;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function parseDateRange(): array
    {
        $from = isset(Flight::request()->query['from'])
            ? trim((string) Flight::request()->query['from'])
            : null;
        $to = isset(Flight::request()->query['to'])
            ? trim((string) Flight::request()->query['to'])
            : null;

        if ($from === '') {
            $from = null;
        }
        if ($to === '') {
            $to = null;
        }

        return [$from, $to];
    }

    private function dateRangeSql(string $alias, ?string $from, ?string $to): string
    {
        if ($from === null && $to === null) {
            return '';
        }

        $parts = [];
        if ($from !== null) {
            $parts[] = " AND ({$alias}.end_at IS NULL OR {$alias}.end_at >= ?)";
        }
        if ($to !== null) {
            $parts[] = " AND {$alias}.start_at <= ?";
        }

        return implode('', $parts);
    }

    /**
     * @param array<int, mixed> $bind
     * @return array<int, mixed>
     */
    private function bindWithRange(array $bind, ?string $from, ?string $to): array
    {
        if ($from !== null) {
            $bind[] = $from . ' 00:00:00';
        }
        if ($to !== null) {
            $bind[] = $to . ' 23:59:59';
        }
        return $bind;
    }

    /**
     * @return array{title: string, description: ?string, location: ?string, start_at: string, end_at: ?string, all_day: int, requires_presence: int, force: bool}|null
     */
    private function parseEventPayload(bool $partial = false): ?array
    {
        $data = json_decode(Flight::request()->getBody(), true);
        if (!is_array($data)) {
            $this->jsonError('Invalid JSON body', 400);
            return null;
        }

        $force = !empty($data['force']);

        $title = isset($data['title']) ? trim((string) $data['title']) : '';
        if (!$partial && $title === '') {
            $this->jsonError('title is required', 400);
            return null;
        }

        $startAt = isset($data['start_at']) ? trim((string) $data['start_at']) : '';
        if (!$partial && $startAt === '') {
            $this->jsonError('start_at is required', 400);
            return null;
        }

        $allDay = !empty($data['all_day']);
        $endAt = isset($data['end_at']) && $data['end_at'] !== '' && $data['end_at'] !== null
            ? trim((string) $data['end_at'])
            : null;

        $startNormalized = $this->normalizeDateTimeInput($startAt, $allDay, false);
        if ($startNormalized === null) {
            $this->jsonError('Invalid start_at', 400);
            return null;
        }

        $endNormalized = null;
        if ($endAt !== null) {
            $endNormalized = $this->normalizeDateTimeInput($endAt, $allDay, true);
            if ($endNormalized === null) {
                $this->jsonError('Invalid end_at', 400);
                return null;
            }
        }

        return [
            'title' => $title !== '' ? $title : 'Untitled',
            'description' => isset($data['description']) && $data['description'] !== ''
                ? trim((string) $data['description'])
                : null,
            'location' => isset($data['location']) && $data['location'] !== ''
                ? trim((string) $data['location'])
                : null,
            'start_at' => $startNormalized,
            'end_at' => $endNormalized,
            'all_day' => $allDay ? 1 : 0,
            'requires_presence' => !empty($data['requires_presence']) ? 1 : 0,
            'force' => $force,
        ];
    }

    /**
     * @param array{start_at: string, end_at: ?string, all_day: int, requires_presence: int, force: bool} $payload
     */
    private function assertNoPresenceConflicts(int $userId, array $payload, ?int $excludeEventId): bool
    {
        if (empty($payload['requires_presence']) || !empty($payload['force'])) {
            return true;
        }

        $allDay = (bool) $payload['all_day'];
        $endAt = $this->effectiveEndAt(
            $payload['start_at'],
            $payload['end_at'],
            $allDay,
        );
        $conflicts = $this->findPresenceConflicts(
            $userId,
            $payload['start_at'],
            $endAt,
            $excludeEventId,
        );

        if ($conflicts === []) {
            return true;
        }

        $this->jsonConflict($conflicts);
        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findPresenceConflicts(
        int $userId,
        string $startAt,
        string $endAt,
        ?int $excludeEventId,
    ): array {
        $conn = Database::getConnection();
        $sql = 'SELECT e.id, e.title, e.location, e.start_at, e.end_at, e.all_day,
                       e.project_id, p.prj_name AS project_name
                FROM fw_calendar_events e
                LEFT JOIN fw_projects p ON p.id = e.project_id
                WHERE e.user_id = ?
                  AND e.requires_presence = 1
                  AND e.start_at < ?
                  AND COALESCE(
                        e.end_at,
                        IF(e.all_day = 1, CONCAT(DATE(e.start_at), \' 23:59:59\'), DATE_ADD(e.start_at, INTERVAL 1 HOUR))
                      ) > ?';

        $bind = [$userId, $endAt, $startAt];
        if ($excludeEventId !== null && $excludeEventId > 0) {
            $sql .= ' AND e.id <> ?';
            $bind[] = $excludeEventId;
        }

        $sql .= ' ORDER BY e.start_at ASC';

        $rows = $conn->executeQuery($sql, $bind)->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $projectId = $row['project_id'] !== null ? (int) $row['project_id'] : null;
            $out[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'location' => $row['location'] !== null ? (string) $row['location'] : null,
                'start_at' => $this->formatDateTime($row['start_at']),
                'end_at' => $row['end_at'] !== null ? $this->formatDateTime($row['end_at']) : null,
                'all_day' => (bool) (int) ($row['all_day'] ?? 0),
                'project_id' => $projectId,
                'project_name' => $row['project_name'] ?? null,
                'scope' => $projectId === null ? 'global' : 'project',
            ];
        }

        return $out;
    }

    private function effectiveEndAt(string $startAt, ?string $endAt, bool $allDay): string
    {
        if ($endAt !== null && $endAt !== '') {
            return $endAt;
        }

        if ($allDay) {
            return substr($startAt, 0, 10) . ' 23:59:59';
        }

        try {
            $dt = new \DateTimeImmutable($startAt);
            return $dt->modify('+1 hour')->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return $startAt;
        }
    }

    /**
     * @param list<array<string, mixed>> $conflicts
     */
    private function jsonConflict(array $conflicts): void
    {
        Flight::json([
            'error_code' => 409,
            'status' => 'error',
            'message' => 'Time conflict with another event that requires your presence',
            'data' => ['conflicts' => $conflicts],
        ], 409);
    }

    private function normalizeDateTimeInput(string $value, bool $allDay, bool $isEnd): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $allDay
                ? ($isEnd ? $value . ' 23:59:59' : $value . ' 00:00:00')
                : $value . ' 00:00:00';
        }

        try {
            $dt = new \DateTimeImmutable($value);
            return $dt->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    private function formatDateTime(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s');
        }

        $str = (string) $value;
        try {
            $dt = new \DateTimeImmutable($str);
            return $dt->format('Y-m-d\TH:i:s');
        } catch (\Exception) {
            return $str;
        }
    }

    private function currentUserId(): ?int
    {
        $user = Flight::get('current_user');
        if (!is_array($user) || empty($user['id'])) {
            $this->jsonError('Unauthorized', 401);
            return null;
        }

        return (int) $user['id'];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonSuccess(string $message, array $data = [], int $status = 200): void
    {
        Flight::json([
            'error_code' => 0,
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    private function jsonError(string $message, int $status): void
    {
        Flight::json([
            'error_code' => $status,
            'status' => 'error',
            'message' => $message,
        ], $status);
    }

    private function logAndFail(string $action, \Throwable $e): void
    {
        $this->logger->error("CalendarController::{$action} failed", ['error' => $e->getMessage()]);
        $this->jsonError('Calendar operation failed', 500);
    }
}
