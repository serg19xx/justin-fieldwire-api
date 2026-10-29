<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use App\Services\WorkClockService;
use App\Services\WorkerDayWorkService;
use Doctrine\DBAL\Connection;
use Flight;
use Monolog\Logger;

/**
 * PM payroll review of worker days on the Schedule page: corrected start/end, payroll note and a
 * manual per worker-day lock. Days never lock by themselves; only a PM (or admin) locks them.
 */
class WorkerPayrollDayController
{
    private const MAX_RANGE_DAYS = 62;

    private const NOTE_MAX_LEN = 2000;

    public function __construct(
        private readonly Logger $logger
    ) {
    }

    /**
     * GET /api/v1/users/{userId}/payroll-days?from=YYYY-MM-DD&to=YYYY-MM-DD
     * Only days that have punches, a PM correction, a note or a lock are returned.
     */
    public function listDays(int $userId): void
    {
        $from = Flight::request()->query['from'] ?? null;
        $to = Flight::request()->query['to'] ?? null;
        if (!is_string($from) || !is_string($to) || !$this->isYmd($from) || !$this->isYmd($to) || $from > $to) {
            $this->error('from and to (YYYY-MM-DD, from <= to) are required', 400);
            return;
        }
        $days = (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days + 1;
        if ($days > self::MAX_RANGE_DAYS) {
            $this->error('Range is limited to ' . self::MAX_RANGE_DAYS . ' days', 400);
            return;
        }

        try {
            $conn = Database::getConnection();
            if (!$this->guard($conn, $userId)) {
                return;
            }
            $service = new WorkerDayWorkService();
            $rows = $service->tableExists($conn) ? $service->findRange($conn, $userId, $from, $to) : [];

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'OK',
                'data' => [
                    'payroll_available' => $service->tableExists($conn) && $service->payrollColumnsPresent($conn),
                    'days' => array_map(static fn (array $r): array => $service->formatPayrollRow($r), $rows),
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('listPayrollDays failed', ['error' => $e->getMessage()]);
            $this->error('Failed to load payroll days', 500);
        }
    }

    /**
     * PUT /api/v1/users/{userId}/payroll-days/{workDate}
     * Body: { "adjusted_start_time": "HH:MM"|null, "adjusted_end_time": "HH:MM"|null, "payroll_note": string|null }
     */
    public function saveDay(int $userId, string $workDate): void
    {
        if (!$this->isYmd($workDate)) {
            $this->error('Invalid work date', 400);
            return;
        }
        $payload = json_decode(Flight::request()->getBody(), true) ?? [];
        if (!is_array($payload)) {
            $this->error('Invalid body', 400);
            return;
        }
        $start = $this->normalizeTime($payload['adjusted_start_time'] ?? null);
        $end = $this->normalizeTime($payload['adjusted_end_time'] ?? null);
        if ($start === false || $end === false) {
            $this->error('adjusted_start_time and adjusted_end_time must be HH:MM or null', 400);
            return;
        }
        $noteRaw = $payload['payroll_note'] ?? null;
        if ($noteRaw !== null && !is_string($noteRaw)) {
            $this->error('payroll_note must be a string or null', 400);
            return;
        }
        $note = is_string($noteRaw) ? trim($noteRaw) : '';
        if (mb_strlen($note) > self::NOTE_MAX_LEN) {
            $this->error('payroll_note must not exceed ' . self::NOTE_MAX_LEN . ' characters', 400);
            return;
        }

        try {
            $conn = Database::getConnection();
            if (!$this->guard($conn, $userId)) {
                return;
            }
            $actorId = (int) Flight::get('current_user')['id'];
            $result = (new WorkerDayWorkService())->savePayroll(
                $conn,
                $userId,
                $workDate,
                $start,
                $end,
                $note === '' ? null : $note,
                $actorId
            );
            $this->respond($result, 'Day saved');
        } catch (\Throwable $e) {
            $this->logger->error('savePayrollDay failed', ['error' => $e->getMessage()]);
            $this->error('Failed to save day', 500);
        }
    }

    /**
     * POST /api/v1/users/{userId}/payroll-days/{workDate}/lock | /unlock
     */
    public function setLocked(int $userId, string $workDate, bool $locked): void
    {
        if (!$this->isYmd($workDate)) {
            $this->error('Invalid work date', 400);
            return;
        }
        try {
            $conn = Database::getConnection();
            if (!$this->guard($conn, $userId)) {
                return;
            }
            if ($locked && $workDate > (new WorkClockService())->todayYmd()) {
                $this->error('Future days cannot be locked', 400);
                return;
            }
            $actorId = (int) Flight::get('current_user')['id'];
            $result = (new WorkerDayWorkService())->setLocked($conn, $userId, $workDate, $locked, $actorId);
            $this->respond($result, $locked ? 'Day locked' : 'Day unlocked');
        } catch (\Throwable $e) {
            $this->logger->error('setPayrollDayLocked failed', ['error' => $e->getMessage()]);
            $this->error('Failed to update lock', 500);
        }
    }

    /**
     * Office roles only; target must be an active user. Sends the error response on failure.
     */
    private function guard(Connection $conn, int $userId): bool
    {
        $actorId = (int) (Flight::get('current_user')['id'] ?? 0);
        $role = $actorId > 0
            ? $conn->executeQuery(
                'SELECT role_code FROM fw_v_users WHERE id = ? AND archived_at IS NULL',
                [$actorId]
            )->fetchOne()
            : null;
        if (!in_array($role, ['admin', 'project_manager'], true)) {
            $this->error('Forbidden', 403);
            return false;
        }
        $exists = $userId > 0 && $conn->executeQuery(
            'SELECT id FROM fw_v_users WHERE id = ? AND archived_at IS NULL',
            [$userId]
        )->fetchOne();
        if (!$exists) {
            $this->error('User not found', 404);
            return false;
        }

        return true;
    }

    /**
     * @param array{ok: true, row: array<string, mixed>}|array{ok: false, status: int, message: string} $result
     */
    private function respond(array $result, string $message): void
    {
        if (!$result['ok']) {
            $this->error($result['message'], $result['status']);
            return;
        }
        Flight::json([
            'error_code' => 0,
            'status' => 'success',
            'message' => $message,
            'data' => ['day' => $result['row']],
        ]);
    }

    /** @return string|null|false null = clear; "HH:MM:SS" = value; false = invalid */
    private function normalizeTime(mixed $raw): string|null|false
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw) || preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($raw), $m) !== 1) {
            return false;
        }
        if ((int) $m[1] > 23 || (int) $m[2] > 59) {
            return false;
        }

        return sprintf('%02d:%02d:00', (int) $m[1], (int) $m[2]);
    }

    private function isYmd(string $s): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) !== 1) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $s));

        return checkdate($m, $d, $y);
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
