<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use App\Services\PublishedScheduleService;
use App\Services\WorkClockService;
use App\Services\WorkerDayWorkService;
use Doctrine\DBAL\Connection;
use Flight;
use Monolog\Logger;

/**
 * Worker timesheet: paid hours from Start day / End day punches (each with an on-site photo),
 * weekly view of the published PM Schedule, and the legacy monthly view of tasks.
 */
class WorkerTimesheetController
{
    private const MAX_PHOTO_BYTES = 8 * 1024 * 1024;

    private const PHOTO_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
    ];

    public function __construct(
        private readonly Logger $logger
    ) {
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
     * GET /api/v1/me/work-timesheet?year=2026&month=8
     */
    public function myMonthlyTimesheet(): void
    {
        $userId = (int) (Flight::get('current_user')['id'] ?? 0);
        if ($userId <= 0) {
            $this->error('Unauthorized', 401);
            return;
        }

        $year = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
        $month = isset($_GET['month']) ? (int) $_GET['month'] : (int) date('n');
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            $this->error('year and month must be valid', 400);
            return;
        }

        $from = sprintf('%04d-%02d-01', $year, $month);
        $toDate = new \DateTimeImmutable($from);
        $to = $toDate->modify('last day of this month')->format('Y-m-d');

        try {
            $conn = Database::getConnection();

            // Tasks the user is involved in that overlap the month (Gantt plan).
            $taskSql = '
                SELECT DISTINCT t.id AS task_id, t.name AS task_name, t.project_id,
                       t.start_planned, t.end_planned, t.start_time, t.end_time,
                       t.address AS task_address,
                       p.prj_name AS project_name, p.address AS project_address
                FROM fw_prj_tasks t
                INNER JOIN fw_projects p ON p.id = t.project_id
                INNER JOIN fw_prj_team_members tm ON tm.task_id = t.id AND tm.user_id = ?
                WHERE t.start_planned IS NOT NULL
                  AND t.start_planned <= ?
                  AND COALESCE(t.end_planned, t.start_planned) >= ?
                ORDER BY t.start_planned, t.start_time, t.id
            ';
            $tasks = $conn->executeQuery($taskSql, [$userId, $to, $from])->fetchAllAssociative();

            /** @var array<string, list<array<string, mixed>>> $byDay */
            $byDay = [];
            $cursor = new \DateTimeImmutable($from);
            $end = new \DateTimeImmutable($to);
            while ($cursor <= $end) {
                $ymd = $cursor->format('Y-m-d');
                foreach ($tasks as $t) {
                    $start = substr((string) $t['start_planned'], 0, 10);
                    $endT = isset($t['end_planned']) && $t['end_planned'] !== null && $t['end_planned'] !== ''
                        ? substr((string) $t['end_planned'], 0, 10)
                        : $start;
                    if ($start <= $ymd && $ymd <= $endT) {
                        $scheduledStart = $this->formatTimeOfDay($t['start_time'] ?? null);
                        $scheduledEnd = $this->formatTimeOfDay($t['end_time'] ?? null);
                        $byDay[$ymd][] = [
                            'project_id' => (int) $t['project_id'],
                            'project_name' => $t['project_name'] !== null ? (string) $t['project_name'] : null,
                            'project_address' => $this->trimAddress($t['project_address'] ?? null),
                            'task_id' => (int) $t['task_id'],
                            'task_name' => $t['task_name'] !== null ? (string) $t['task_name'] : null,
                            'task_address' => $this->trimAddress($t['task_address'] ?? null),
                            'scheduled_start' => $scheduledStart,
                            'scheduled_end' => $scheduledEnd,
                            'scheduled_hours' => $this->scheduledHours($scheduledStart, $scheduledEnd),
                        ];
                    }
                }
                $cursor = $cursor->modify('+1 day');
            }

            $workerDay = new WorkerDayWorkService();
            /** @var array<string, array<string, mixed>> $dayWorkByDate */
            $dayWorkByDate = [];
            if ($workerDay->tableExists($conn)) {
                foreach ($workerDay->findRange($conn, $userId, $from, $to) as $row) {
                    $formatted = $workerDay->formatRow($row);
                    $dayWorkByDate[(string) $formatted['work_date']] = $formatted;
                }
            }

            $daysOut = [];
            $totalHours = 0.0;
            foreach (array_keys($dayWorkByDate) as $ymd) {
                $byDay[$ymd] ??= [];
            }
            ksort($byDay);
            foreach ($byDay as $ymd => $entries) {
                $dayWork = $dayWorkByDate[$ymd] ?? null;
                // Paid hours come only from the Start day / End day timesheet punch.
                $dayHours = $dayWork !== null && $dayWork['hours'] !== null ? (float) $dayWork['hours'] : 0.0;
                $totalHours += $dayHours;
                $daysOut[] = [
                    'work_date' => $ymd,
                    'day_work' => $dayWork,
                    'entries' => $entries,
                    'hours' => round($dayHours, 2),
                ];
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'OK',
                'data' => [
                    'year' => $year,
                    'month' => $month,
                    'from' => $from,
                    'to' => $to,
                    'days' => $daysOut,
                    'total_hours' => round($totalHours, 2),
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('myMonthlyTimesheet failed', ['error' => $e->getMessage()]);
            $this->error('Failed to load timesheet', 500);
        }
    }

    /**
     * GET /api/v1/me/work-day?work_date=YYYY-MM-DD (defaults to today)
     */
    public function myWorkDay(): void
    {
        $userId = (int) (Flight::get('current_user')['id'] ?? 0);
        if ($userId <= 0) {
            $this->error('Unauthorized', 401);
            return;
        }

        $clock = new WorkClockService();
        $workDate = isset($_GET['work_date']) ? trim((string) $_GET['work_date']) : $clock->todayYmd();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate)) {
            $this->error('work_date must be YYYY-MM-DD', 400);
            return;
        }

        try {
            $conn = Database::getConnection();
            $service = new WorkerDayWorkService($clock);
            $row = $service->tableExists($conn) ? $service->findDayRow($conn, $userId, $workDate) : null;

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'OK',
                'data' => [
                    'today' => $clock->todayYmd(),
                    'day_work' => $row !== null ? $service->formatRow($row) : null,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('myWorkDay failed', ['error' => $e->getMessage()]);
            $this->error('Failed to load work day', 500);
        }
    }

    /**
     * GET /api/v1/me/work-week?week_start=YYYY-MM-DD (any day of the week; defaults to this week)
     *
     * Published PM Schedule for Monday..Sunday with the Start day / End day punches and weekly totals:
     * hours scheduled (expected times minus the unpaid break), hours worked, and km from the PM.
     */
    public function myWeeklyTimesheet(): void
    {
        $userId = (int) (Flight::get('current_user')['id'] ?? 0);
        if ($userId <= 0) {
            $this->error('Unauthorized', 401);
            return;
        }

        $clock = new WorkClockService();
        $raw = isset($_GET['week_start']) ? trim((string) $_GET['week_start']) : $clock->todayYmd();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            $this->error('week_start must be YYYY-MM-DD', 400);
            return;
        }
        $monday = new \DateTimeImmutable($raw);
        $monday = $monday->modify('-' . ((int) $monday->format('N') - 1) . ' days');
        $from = $monday->format('Y-m-d');
        $to = $monday->modify('+6 days')->format('Y-m-d');

        try {
            $conn = Database::getConnection();
            $slotsByDay = [];
            foreach ((new PublishedScheduleService())->slotsForUser($conn, $userId, $from, $to) as $slot) {
                $slotsByDay[$slot['work_date']][] = $slot;
            }

            $workerDay = new WorkerDayWorkService($clock);
            $dayWorkByDate = [];
            if ($workerDay->tableExists($conn)) {
                foreach ($workerDay->findRange($conn, $userId, $from, $to) as $row) {
                    $formatted = $workerDay->formatRow($row);
                    $dayWorkByDate[(string) $formatted['work_date']] = $formatted;
                }
            }

            $days = [];
            $totals = ['scheduled_hours' => 0.0, 'worked_hours' => 0.0, 'km' => 0.0];
            for ($i = 0; $i < 7; $i++) {
                $ymd = $monday->modify("+{$i} days")->format('Y-m-d');
                $slots = $slotsByDay[$ymd] ?? [];
                $dayWork = $dayWorkByDate[$ymd] ?? null;
                $scheduled = $this->expectedDayHours($slots);
                $worked = $dayWork !== null && $dayWork['hours'] !== null ? (float) $dayWork['hours'] : 0.0;
                $km = 0.0;
                foreach ($slots as $slot) {
                    $km += PublishedScheduleService::parseKm($slot['distance_km']) ?? 0.0;
                }
                $totals['scheduled_hours'] += $scheduled;
                $totals['worked_hours'] += $worked;
                $totals['km'] += $km;
                $days[] = [
                    'work_date' => $ymd,
                    'slots' => $slots,
                    'day_work' => $dayWork,
                    'scheduled_hours' => round($scheduled, 2),
                    'worked_hours' => round($worked, 2),
                    'km' => round($km, 2),
                ];
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'OK',
                'data' => [
                    'week_start' => $from,
                    'week_end' => $to,
                    'today' => $clock->todayYmd(),
                    'break_hours' => WorkerDayWorkService::BREAK_HOURS,
                    'days' => $days,
                    'totals' => array_map(static fn (float $v): float => round($v, 2), $totals),
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('myWeeklyTimesheet failed', ['error' => $e->getMessage()]);
            $this->error('Failed to load timesheet', 500);
        }
    }

    /**
     * POST /api/v1/me/work-day/check-in (multipart/form-data)
     * Fields: work_date, phase (start|end), lat?, lng?, photo (required image taken on site).
     */
    public function checkInMyWorkDay(): void
    {
        $userId = (int) (Flight::get('current_user')['id'] ?? 0);
        if ($userId <= 0) {
            $this->error('Unauthorized', 401);
            return;
        }

        $body = $_POST;
        if ($body === []) {
            $decoded = json_decode(Flight::request()->getBody(), true);
            $body = is_array($decoded) ? $decoded : [];
        }
        $clock = new WorkClockService();
        $workDate = isset($body['work_date']) ? trim((string) $body['work_date']) : $clock->todayYmd();
        $phase = isset($body['phase']) ? (string) $body['phase'] : '';
        $lat = isset($body['lat']) && is_numeric($body['lat']) ? (float) $body['lat'] : null;
        $lng = isset($body['lng']) && is_numeric($body['lng']) ? (float) $body['lng'] : null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate) || ($phase !== 'start' && $phase !== 'end')) {
            $this->error('work_date (YYYY-MM-DD) and phase (start|end) are required', 400);
            return;
        }

        $photo = $_FILES['photo'] ?? null;
        if (!is_array($photo) || (int) ($photo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $this->error('A photo taken on site is required to ' . ($phase === 'start' ? 'start' : 'end') . ' the day', 422);
            return;
        }

        $storedPath = null;
        try {
            $conn = Database::getConnection();
            $service = new WorkerDayWorkService($clock);
            if (!$service->tableExists($conn) || !$service->photoColumnsPresent($conn)) {
                $this->error('Start day / End day is not available until database migration is applied', 503);
                return;
            }

            $stored = $this->storeDayPhoto($photo, $userId, $workDate, $phase);
            if (!$stored['ok']) {
                $this->error($stored['message'], $stored['status']);
                return;
            }
            $storedPath = $stored['path'];

            $result = $service->checkIn($conn, $userId, $workDate, $phase, $lat, $lng, $storedPath);
            if (!$result['ok']) {
                $this->removeStoredPhoto($storedPath);
                $this->error($result['message'], $result['status']);
                return;
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => $phase === 'start' ? 'Day started' : 'Day ended',
                'data' => ['day_work' => $result['row']],
            ]);
        } catch (\Throwable $e) {
            if ($storedPath !== null) {
                $this->removeStoredPhoto($storedPath);
            }
            $this->logger->error('checkInMyWorkDay failed', ['error' => $e->getMessage()]);
            $this->error('Failed to record work day', 500);
        }
    }

    /**
     * GET /api/v1/users/{userId}/work-day/{workDate}/photo/{phase} — Start day / End day photo.
     * The worker themself, admins and project managers may view it.
     */
    public function workDayPhoto(int $targetUserId, string $workDate, string $phase): void
    {
        $actorId = (int) (Flight::get('current_user')['id'] ?? 0);
        if ($actorId <= 0) {
            $this->error('Unauthorized', 401);
            return;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDate) || ($phase !== 'start' && $phase !== 'end')) {
            $this->error('Invalid work date or phase', 400);
            return;
        }

        try {
            $conn = Database::getConnection();
            if (!$this->canViewUserWorkDay($conn, $actorId, $targetUserId)) {
                $this->error('Forbidden', 403);
                return;
            }
            $path = (new WorkerDayWorkService())->photoPath($conn, $targetUserId, $workDate, $phase);
            $full = $path !== null ? $this->publicDir() . '/' . ltrim($path, '/') : null;
            if ($full === null || !is_file($full)) {
                $this->error('Photo not found', 404);
                return;
            }

            $mime = function_exists('mime_content_type') ? (mime_content_type($full) ?: 'image/jpeg') : 'image/jpeg';
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . (string) filesize($full));
            header('Content-Disposition: inline; filename="' . basename($full) . '"');
            header('Cache-Control: private, max-age=3600');
            if (ob_get_level()) {
                ob_end_clean();
            }
            readfile($full);
        } catch (\Throwable $e) {
            $this->logger->error('workDayPhoto failed', ['error' => $e->getMessage()]);
            $this->error('Failed to load photo', 500);
        }
    }

    private function canViewUserWorkDay(Connection $conn, int $actorId, int $targetUserId): bool
    {
        if ($actorId === $targetUserId) {
            return true;
        }
        $role = $conn->executeQuery(
            'SELECT role_code FROM fw_v_users WHERE id = ? AND archived_at IS NULL',
            [$actorId]
        )->fetchOne();

        return in_array($role, ['admin', 'project_manager'], true);
    }

    /**
     * @param array<string, mixed> $file Entry of $_FILES
     * @return array{ok: true, path: string}|array{ok: false, status: int, message: string}
     */
    private function storeDayPhoto(array $file, int $userId, string $workDate, string $phase): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return [
                'ok' => false,
                'status' => in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 422,
                'message' => 'Photo upload failed. Try again.',
            ];
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_PHOTO_BYTES) {
            return ['ok' => false, 'status' => 422, 'message' => 'Photo must be an image up to 8 MB'];
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $mime = $tmp !== '' && function_exists('mime_content_type') ? (string) mime_content_type($tmp) : '';
        $ext = self::PHOTO_EXTENSIONS[strtolower($mime)] ?? null;
        if ($ext === null) {
            return ['ok' => false, 'status' => 422, 'message' => 'Only image files are allowed (JPG, PNG, WebP, HEIC)'];
        }

        $relative = sprintf(
            'uploads/worker-day-photos/u-%d/d-%s/%s_%d_%s.%s',
            $userId,
            $workDate,
            $phase,
            time(),
            bin2hex(random_bytes(4)),
            $ext
        );
        $full = $this->publicDir() . '/' . $relative;
        $dir = dirname($full);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'status' => 500, 'message' => 'Storage error'];
        }
        if (!move_uploaded_file($tmp, $full)) {
            return ['ok' => false, 'status' => 500, 'message' => 'Storage error'];
        }

        return ['ok' => true, 'path' => $relative];
    }

    private function removeStoredPhoto(string $relative): void
    {
        @unlink($this->publicDir() . '/' . ltrim($relative, '/'));
    }

    private function publicDir(): string
    {
        return __DIR__ . '/../../public';
    }

    /**
     * Expected paid hours for a day: earliest expected start to latest expected end, minus the unpaid break.
     *
     * @param list<array<string, mixed>> $slots
     */
    private function expectedDayHours(array $slots): float
    {
        $starts = array_filter(array_column($slots, 'expected_start_time'));
        $ends = array_filter(array_column($slots, 'expected_end_time'));
        if ($starts === [] || $ends === []) {
            return 0.0;
        }
        $span = $this->scheduledHours(min($starts), max($ends));
        if ($span === null) {
            return 0.0;
        }

        return max(0.0, $span - WorkerDayWorkService::BREAK_HOURS);
    }

    private function trimAddress(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $s = trim((string) $raw);

        return $s === '' ? null : $s;
    }

    /** MySQL TIME ("08:00:00") to "08:00"; null when empty. */
    private function formatTimeOfDay(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $s = trim((string) $raw);
        if (!preg_match('/^(\d{1,2}):(\d{2})/', $s, $m)) {
            return null;
        }

        return sprintf('%02d:%s', (int) $m[1], $m[2]);
    }

    private function scheduledHours(?string $start, ?string $end): ?float
    {
        if ($start === null || $end === null) {
            return null;
        }
        [$sh, $sm] = array_map('intval', explode(':', $start));
        [$eh, $em] = array_map('intval', explode(':', $end));
        $minutes = ($eh * 60 + $em) - ($sh * 60 + $sm);

        return $minutes > 0 ? round($minutes / 60, 2) : null;
    }
}
