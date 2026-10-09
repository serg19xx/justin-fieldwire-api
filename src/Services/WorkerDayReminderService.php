<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\FrontendUrl;
use App\Support\PhoneNormalizer;
use Doctrine\DBAL\Connection;
use Monolog\Logger;

/**
 * SMS reminders for the worker Timesheet punch (Start day / End day).
 *
 * A worker's scheduled day is the earliest task start_time and the latest task end_time
 * among open tasks they are on that cover the date. Reminders go out GRACE_MINUTES after
 * the scheduled start (no Start day yet) and after the scheduled end (no End day yet).
 */
class WorkerDayReminderService
{
    public const GRACE_MINUTES = 5;
    public const KIND_CLOCK_IN = 'clock_in';
    public const KIND_CLOCK_OUT = 'clock_out';

    private const TABLE = 'fw_worker_day_reminders';
    private const CLOSED_TASK_STATUSES = ['completed', 'done'];

    private WorkClockService $clock;
    private WorkerDayWorkService $dayWork;
    private ?TwilioService $twilio;

    public function __construct(
        private readonly Logger $logger,
        ?TwilioService $twilio = null,
        ?WorkClockService $clock = null,
    ) {
        $this->clock = $clock ?? new WorkClockService();
        $this->dayWork = new WorkerDayWorkService($this->clock);
        $this->twilio = $twilio;
    }

    public function tableExists(Connection $conn): bool
    {
        return (bool) $conn->executeQuery(
            'SHOW TABLES LIKE ' . $conn->quote(self::TABLE)
        )->fetchOne();
    }

    /**
     * Field crew (foreman / worker) with a scheduled day on $ymd.
     *
     * @return list<array{user_id: int, first_name: string, phone: ?string, start_time: string, end_time: string}>
     */
    public function scheduledWorkers(Connection $conn, string $ymd): array
    {
        $placeholders = implode(', ', array_fill(0, count(self::CLOSED_TASK_STATUSES), '?'));
        $rows = $conn->executeQuery(
            "SELECT u.id AS user_id, u.first_name, u.phone,
                    MIN(t.start_time) AS start_time, MAX(t.end_time) AS end_time
             FROM fw_prj_team_members tm
             INNER JOIN fw_prj_tasks t ON t.id = tm.task_id
             INNER JOIN fw_users u ON u.id = tm.user_id
             INNER JOIN fw_glob_roles r ON r.id = u.role_id AND r.category = 'task'
             WHERE t.start_planned IS NOT NULL
               AND DATE(t.start_planned) <= ?
               AND DATE(COALESCE(t.end_planned, t.start_planned)) >= ?
               AND t.start_time IS NOT NULL
               AND t.end_time IS NOT NULL
               AND (t.status IS NULL OR t.status NOT IN ({$placeholders}))
               AND u.status = 1
               AND u.archived_at IS NULL
             GROUP BY u.id, u.first_name, u.phone
             ORDER BY u.id",
            array_merge([$ymd, $ymd], self::CLOSED_TASK_STATUSES)
        )->fetchAllAssociative();

        return array_map(static fn (array $r): array => [
            'user_id' => (int) $r['user_id'],
            'first_name' => trim((string) ($r['first_name'] ?? '')),
            'phone' => $r['phone'] !== null ? (string) $r['phone'] : null,
            'start_time' => substr((string) $r['start_time'], 0, 5),
            'end_time' => substr((string) $r['end_time'], 0, 5),
        ], $rows);
    }

    /**
     * Evaluate reminders for today and send them (or only report with $send = false).
     *
     * @return array{
     *   work_date: string,
     *   now: string,
     *   skipped_reason: ?string,
     *   checked: int,
     *   actions: list<array<string, mixed>>
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
        ];

        if ((int) $now->format('N') >= 6) {
            $result['skipped_reason'] = 'weekend';
            return $result;
        }
        if (!$this->dayWork->tableExists($conn) || !$this->tableExists($conn)) {
            $result['skipped_reason'] = 'missing tables';
            return $result;
        }

        foreach ($this->scheduledWorkers($conn, $ymd) as $worker) {
            $result['checked']++;
            $kind = $this->dueReminderKind($conn, $worker, $ymd, $now);
            if ($kind === null || $this->alreadyRecorded($conn, $worker['user_id'], $ymd, $kind)) {
                continue;
            }

            $phone = PhoneNormalizer::toE164($worker['phone'], false);
            $scheduledTime = $kind === self::KIND_CLOCK_IN ? $worker['start_time'] : $worker['end_time'];
            $action = [
                'user_id' => $worker['user_id'],
                'kind' => $kind,
                'scheduled_time' => $scheduledTime,
                'phone' => $phone,
                'body' => $this->messageBody($kind, $worker['first_name'], $scheduledTime),
            ];

            if (!$send) {
                $result['actions'][] = $action + ['status' => 'dry_run'];
                continue;
            }

            if (!$this->claim($conn, $worker['user_id'], $ymd, $kind, $scheduledTime, $phone)) {
                continue;
            }
            $status = $phone === null ? 'no_phone' : ($this->twilio()->sendSms($phone, $action['body']) ? 'sent' : 'failed');
            $this->updateStatus($conn, $worker['user_id'], $ymd, $kind, $status);
            if ($status !== 'sent') {
                $this->logger->warning('Work day reminder not delivered', [
                    'user_id' => $worker['user_id'],
                    'kind' => $kind,
                    'status' => $status,
                ]);
            }
            $result['actions'][] = $action + ['status' => $status];
        }

        return $result;
    }

    /**
     * @param array{user_id: int, start_time: string, end_time: string} $worker
     */
    private function dueReminderKind(Connection $conn, array $worker, string $ymd, \DateTimeImmutable $now): ?string
    {
        $tz = $this->clock->timezone();
        $start = new \DateTimeImmutable($ymd . ' ' . $worker['start_time'], $tz);
        $end = new \DateTimeImmutable($ymd . ' ' . $worker['end_time'], $tz);
        if ($end <= $start) {
            return null;
        }

        $grace = new \DateInterval('PT' . self::GRACE_MINUTES . 'M');
        $row = $this->dayWork->findDayRow($conn, $worker['user_id'], $ymd);
        $hasStart = !empty($row['work_start_at']);
        $hasEnd = !empty($row['work_end_at']);

        if (!$hasStart && $now >= $start->add($grace) && $now < $end) {
            return self::KIND_CLOCK_IN;
        }
        if ($hasStart && !$hasEnd && $now >= $end->add($grace)) {
            return self::KIND_CLOCK_OUT;
        }

        return null;
    }

    private function messageBody(string $kind, string $firstName, string $scheduledTime): string
    {
        $greeting = $firstName !== '' ? "Hi {$firstName}, " : 'Hi, ';
        $link = FrontendUrl::resolve() . '/tasks/timesheet';

        return $kind === self::KIND_CLOCK_IN
            ? "{$greeting}your work day was scheduled to start at {$scheduledTime}. Please clock in (Start day): {$link}"
            : "{$greeting}your work day was scheduled to end at {$scheduledTime}. Please clock out (End day): {$link}";
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
