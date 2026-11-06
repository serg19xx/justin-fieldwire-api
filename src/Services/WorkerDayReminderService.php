<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\FrontendUrl;
use App\Support\PhoneNormalizer;
use Doctrine\DBAL\Connection;
use Monolog\Logger;

/**
 * SMS reminders for the worker Timesheet punch (Start day / End day) and site transfers.
 *
 * The scheduled day comes from AttendanceService::shiftsOn (published PM Schedule first, then the
 * task plan; weekends included). Up to STEPS reminders go out every STEP_MINUTES after the scheduled
 * start while there is no Start day, and after the end of the working window (scheduled end or the
 * worker's "Working late" time) while there is no End day. A second site of the day gets one
 * "arrived?" reminder. After the reminders, days still open are auto-closed by AttendanceService.
 */
class WorkerDayReminderService
{
    public const STEP_MINUTES = 15;
    public const STEPS = 3;
    public const KIND_CLOCK_IN = 'clock_in';
    public const KIND_CLOCK_OUT = 'clock_out';

    private const TABLE = 'fw_worker_day_reminders';

    private WorkClockService $clock;
    private WorkerDayWorkService $dayWork;
    private AttendanceService $attendance;
    private WorkerActionTokenService $tokens;
    private ?TwilioService $twilio;

    public function __construct(
        private readonly Logger $logger,
        ?TwilioService $twilio = null,
        ?WorkClockService $clock = null,
    ) {
        $this->clock = $clock ?? new WorkClockService();
        $this->dayWork = new WorkerDayWorkService($this->clock);
        $this->attendance = new AttendanceService($this->clock, $this->logger);
        $this->tokens = new WorkerActionTokenService($this->clock);
        $this->twilio = $twilio;
    }

    public function tableExists(Connection $conn): bool
    {
        return (bool) $conn->executeQuery(
            'SHOW TABLES LIKE ' . $conn->quote(self::TABLE)
        )->fetchOne();
    }

    /**
     * Evaluate reminders for today and send them (or only report with $send = false), then auto-close.
     *
     * @return array{
     *   work_date: string,
     *   now: string,
     *   skipped_reason: ?string,
     *   checked: int,
     *   actions: list<array<string, mixed>>,
     *   auto_closed: list<array<string, mixed>>
     * }
     */
    public function run(Connection $conn, bool $send, ?\DateTimeImmutable $now = null): array
    {
        $now = ($now ?? $this->clock->now())->setTimezone($this->clock->timezone());
        $ymd = $this->clock->todayYmd($now);
        $result = [
            'work_date' => $ymd,
            'now' => $now->format('Y-m-d H:i'),
            'skipped_reason' => null,
            'checked' => 0,
            'actions' => [],
            'auto_closed' => [],
        ];

        if (!$this->dayWork->tableExists($conn) || !$this->tableExists($conn)) {
            $result['skipped_reason'] = 'missing tables';
            return $result;
        }

        $hasAttendance = $this->attendance->isAvailable($conn);
        foreach ($this->attendance->shiftsOn($conn, $ymd) as $worker) {
            $result['checked']++;
            $row = $this->dayWork->findDayRow($conn, $worker['user_id'], $ymd);
            if ($row !== null && !empty($row['locked_at'])) {
                continue;
            }

            foreach ($this->dueReminders($conn, $worker, $row, $ymd, $now, $hasAttendance) as $due) {
                if ($this->alreadyRecorded($conn, $worker['user_id'], $ymd, $due['kind'])) {
                    continue;
                }
                $phone = PhoneNormalizer::toE164($worker['phone'], false);
                $action = [
                    'user_id' => $worker['user_id'],
                    'kind' => $due['kind'],
                    'scheduled_time' => $due['scheduled_time'],
                    'phone' => $phone,
                ];

                if (!$send) {
                    $result['actions'][] = $action + [
                        'body' => $this->messageBody($conn, $worker, $ymd, $due, false),
                        'status' => 'dry_run',
                    ];
                    continue;
                }

                if (!$this->claim($conn, $worker['user_id'], $ymd, $due['kind'], $due['scheduled_time'], $phone)) {
                    continue;
                }
                $body = $this->messageBody($conn, $worker, $ymd, $due, $phone !== null);
                $status = $phone === null ? 'no_phone' : ($this->twilio()->sendSms($phone, $body) ? 'sent' : 'failed');
                $this->updateStatus($conn, $worker['user_id'], $ymd, $due['kind'], $status);
                if ($status !== 'sent') {
                    $this->logger->warning('Work day reminder not delivered', [
                        'user_id' => $worker['user_id'],
                        'kind' => $due['kind'],
                        'status' => $status,
                    ]);
                }
                $result['actions'][] = $action + ['body' => $body, 'status' => $status];
            }
        }

        if ($hasAttendance) {
            $result['auto_closed'] = $this->attendance->autoClose($conn, $now, $send);
        }

        return $result;
    }

    /**
     * @param array{user_id: int, start_time: string, end_time: string} $worker
     * @param array<string, mixed>|null $row
     * @return list<array<string, mixed>>
     */
    private function dueReminders(
        Connection $conn,
        array $worker,
        ?array $row,
        string $ymd,
        \DateTimeImmutable $now,
        bool $hasAttendance,
    ): array {
        $tz = $this->clock->timezone();
        $start = new \DateTimeImmutable($ymd . ' ' . $worker['start_time'], $tz);
        $end = new \DateTimeImmutable($ymd . ' ' . $worker['end_time'], $tz);
        if ($end <= $start) {
            return [];
        }

        $hasStart = !empty($row['work_start_at']);
        $hasEnd = !empty($row['work_end_at']);
        $out = [];

        if (!$hasStart && empty($row['adjusted_start_time']) && $now < $end) {
            $step = $this->stepAt($start, $now);
            if ($step > 0) {
                $out[] = [
                    'template' => 'clock_in',
                    'step' => $step,
                    'kind' => $step === 1 ? self::KIND_CLOCK_IN : self::KIND_CLOCK_IN . '_' . $step,
                    'scheduled_time' => $worker['start_time'],
                ];
            }
        }

        if (
            $hasStart
            && !$hasEnd
            && empty($row['adjusted_end_time'])
            && empty($row['extended_open'])
            && ($row['end_source'] ?? null) !== WorkerDayWorkService::SOURCE_PM
        ) {
            $base = $hasAttendance ? $this->attendance->clockOutBase($ymd, $worker, $row) : $end;
            $step = $this->stepAt($base, $now);
            if ($step > 0) {
                $extended = $base > $end;
                $out[] = [
                    'template' => 'clock_out',
                    'step' => $step,
                    'kind' => $extended
                        ? 'out' . $base->format('Hi') . '_' . $step
                        : ($step === 1 ? self::KIND_CLOCK_OUT : self::KIND_CLOCK_OUT . '_' . $step),
                    'scheduled_time' => $base->format('H:i'),
                    'extended' => $extended,
                    'end_time' => $worker['end_time'],
                    'close_at' => $base->modify('+' . AttendanceService::AUTO_CLOSE_MINUTES . ' minutes')->format('H:i'),
                    'with_link' => $hasAttendance,
                ];
            }
        }

        if ($hasAttendance && $hasStart && !$hasEnd && $this->attendance->siteEventsAvailable($conn)) {
            foreach ($this->attendance->daySites($conn, $worker['user_id'], $ymd) as $site) {
                if ($site['is_first'] || $site['arrived_at'] !== null || $site['expected_start_time'] === null) {
                    continue;
                }
                $siteStart = new \DateTimeImmutable($ymd . ' ' . $site['expected_start_time'], $tz);
                if ($now < $siteStart->modify('+' . AttendanceService::SITE_GRACE_MINUTES . ' minutes')) {
                    continue;
                }
                if ($site['expected_end_time'] !== null && $now >= new \DateTimeImmutable($ymd . ' ' . $site['expected_end_time'], $tz)) {
                    continue;
                }
                $out[] = [
                    'template' => 'site',
                    'step' => 1,
                    'kind' => 'site_' . $site['project_id'],
                    'scheduled_time' => $site['expected_start_time'],
                    'project_id' => $site['project_id'],
                    'project_name' => $site['project_name'] ?? ('Project #' . $site['project_id']),
                ];
            }
        }

        return $out;
    }

    /** Reminder step 1..STEPS due at $now for a window that started at $base; 0 when none is due. */
    private function stepAt(\DateTimeImmutable $base, \DateTimeImmutable $now): int
    {
        $minutes = intdiv($now->getTimestamp() - $base->getTimestamp(), 60);
        if ($minutes < self::STEP_MINUTES || $minutes >= self::STEP_MINUTES * (self::STEPS + 1)) {
            return 0;
        }

        return min(self::STEPS, intdiv($minutes, self::STEP_MINUTES));
    }

    /**
     * @param array{user_id: int, first_name: string} $worker
     * @param array<string, mixed> $due
     */
    private function messageBody(Connection $conn, array $worker, string $ymd, array $due, bool $createLinks): string
    {
        $name = $worker['first_name'] !== '' ? "Hi {$worker['first_name']}, " : 'Hi, ';
        $timesheet = FrontendUrl::resolve() . '/tasks/timesheet';
        $step = (int) $due['step'];
        $prefix = $step >= self::STEPS ? 'Last reminder: ' : ($step > 1 ? "Reminder {$step} of " . self::STEPS . ': ' : '');

        if ($due['template'] === 'clock_in') {
            return $step === 1
                ? "{$name}your shift started at {$due['scheduled_time']}. Please tap Start day: {$timesheet}"
                : "{$prefix}{$name}you have not started your day (shift started at {$due['scheduled_time']}). Tap Start day: {$timesheet}";
        }

        if ($due['template'] === 'site') {
            $link = $this->actionLink($conn, $worker['user_id'], $ymd, WorkerActionTokenService::ACTION_ARRIVE, (int) $due['project_id'], $createLinks)
                ?? $timesheet;

            return "{$name}you are scheduled at {$due['project_name']} from {$due['scheduled_time']}. "
                . "When you get there, tap to confirm arrival: {$link}";
        }

        $what = !empty($due['extended']) ? 'your extended time' : 'your shift';
        $text = "{$prefix}{$name}{$what} ended at {$due['scheduled_time']}. Please tap End day: {$timesheet}";
        if (!empty($due['with_link'])) {
            $link = $this->actionLink($conn, $worker['user_id'], $ymd, WorkerActionTokenService::ACTION_EXTEND, null, $createLinks);
            if ($link !== null) {
                $text .= " Still working? Tap: {$link}";
            }
            $text .= " If you do not clock out by {$due['close_at']}, the day will be closed at {$due['end_time']}.";
        }

        return $text;
    }

    private function actionLink(Connection $conn, int $userId, string $ymd, string $action, ?int $projectId, bool $create): ?string
    {
        if (!$this->tokens->tableExists($conn)) {
            return null;
        }
        if (!$create) {
            return '[one-tap link]';
        }

        return WorkerActionTokenService::url($this->tokens->create($conn, $userId, $ymd, $action, $projectId));
    }

    private function alreadyRecorded(Connection $conn, int $userId, string $ymd, string $kind): bool
    {
        return (bool) $conn->executeQuery(
            'SELECT 1 FROM ' . self::TABLE . ' WHERE user_id = ? AND work_date = ? AND kind = ?',
            [$userId, $ymd, $kind]
        )->fetchOne();
    }

    /** Reserve the reminder before sending so overlapping cron runs cannot text twice. */
    private function claim(
        Connection $conn,
        int $userId,
        string $ymd,
        string $kind,
        string $scheduledTime,
        ?string $phone,
    ): bool {
        return $conn->executeStatement(
            'INSERT IGNORE INTO ' . self::TABLE . ' (user_id, work_date, kind, scheduled_time, phone, status)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $ymd, $kind, $scheduledTime . ':00', $phone, 'pending']
        ) > 0;
    }

    private function updateStatus(Connection $conn, int $userId, string $ymd, string $kind, string $status): void
    {
        $conn->executeStatement(
            'UPDATE ' . self::TABLE . ' SET status = ? WHERE user_id = ? AND work_date = ? AND kind = ?',
            [$status, $userId, $ymd, $kind]
        );
    }

    private function twilio(): TwilioService
    {
        return $this->twilio ??= new TwilioService($this->logger);
    }
}
