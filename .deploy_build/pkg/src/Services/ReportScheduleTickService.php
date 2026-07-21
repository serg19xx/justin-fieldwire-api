<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use Doctrine\DBAL\Connection;
use Monolog\Logger;
use Throwable;

/**
 * Evaluates event rules that have a crontab-like schedule + create_report action.
 * Idempotent via fw_report_schedule_fires (event_type + period_key).
 *
 * Periodicity comes from time_conditions.frequency (not from event_type name).
 */
class ReportScheduleTickService
{
    private Connection $connection;
    private EventLoggingService $eventLoggingService;
    private EventConditionsService $conditionsService;

    public function __construct(
        private readonly Logger $logger,
        ?EventLoggingService $eventLoggingService = null,
        ?EventConditionsService $conditionsService = null,
    ) {
        $this->connection = Database::getConnection();
        $this->eventLoggingService = $eventLoggingService ?? new EventLoggingService($logger);
        $this->conditionsService = $conditionsService
            ?? new EventConditionsService(new Database(), $logger);
    }

    /**
     * @return array{checked: int, fired: int, skipped: int, errors: int, details: list<array<string, mixed>>}
     */
    public function run(?\DateTimeImmutable $now = null): array
    {
        $now = $now ?? new \DateTimeImmutable('now');
        $stats = ['checked' => 0, 'fired' => 0, 'skipped' => 0, 'errors' => 0, 'details' => []];

        $this->ensureFiresTable();

        $rules = $this->loadScheduleRules();
        foreach ($rules as $rule) {
            $stats['checked']++;
            $eventType = (string) $rule['event_type'];
            try {
                $conditions = is_array($rule['conditions'] ?? null) ? $rule['conditions'] : null;
                $timeConditions = is_array($conditions['time_conditions'] ?? null)
                    ? $conditions['time_conditions']
                    : null;
                if (is_array($timeConditions) && isset($timeConditions['value']) && is_array($timeConditions['value'])) {
                    $timeConditions = $timeConditions['value'];
                }
                if (!is_array($timeConditions) || $timeConditions === []) {
                    $stats['skipped']++;
                    $stats['details'][] = [
                        'event_type' => $eventType,
                        'status' => 'skipped',
                        'reason' => 'no_schedule',
                    ];
                    continue;
                }

                $eval = $this->conditionsService->evaluateSchedule($timeConditions, $now);
                if ($eval !== 'match') {
                    $stats['skipped']++;
                    $stats['details'][] = [
                        'event_type' => $eventType,
                        'status' => 'skipped',
                        'reason' => 'schedule_filter',
                        'eval' => $eval,
                    ];
                    continue;
                }

                $schedule = $this->conditionsService->normalizeTimeConditions($timeConditions);
                $periodKey = $this->periodKeyFor($schedule, $now);
                if (!$this->claimFire($eventType, $periodKey)) {
                    $stats['skipped']++;
                    $stats['details'][] = [
                        'event_type' => $eventType,
                        'status' => 'skipped',
                        'reason' => 'already_fired',
                        'period_key' => $periodKey,
                    ];
                    continue;
                }

                $frequency = $schedule['frequency'];
                $reportDate = $this->reportDateFor($frequency, $now, $schedule['timezone']);
                $period = in_array($frequency, ['daily', 'weekly', 'monthly'], true)
                    ? $frequency
                    : 'daily';

                $eventLogId = $this->eventLoggingService->logEvent(
                    'report',
                    0,
                    $eventType,
                    [],
                    [
                        'report_date' => $reportDate,
                        'period' => $period,
                        'period_key' => $periodKey,
                        'triggered_by' => 'schedule_tick',
                    ],
                    [],
                    [
                        'actor_type' => 'system',
                        'actor_id' => null,
                        'correlation_id' => substr($eventType . ':' . $periodKey, 0, 64),
                        'comment' => 'Schedule tick fire for ' . $periodKey,
                    ]
                );

                if ($eventLogId === null) {
                    $stats['errors']++;
                    $stats['details'][] = [
                        'event_type' => $eventType,
                        'status' => 'error',
                        'reason' => 'log_event_failed',
                        'period_key' => $periodKey,
                    ];
                    continue;
                }

                $this->connection->executeStatement(
                    'UPDATE fw_report_schedule_fires SET event_log_id = ? WHERE event_type = ? AND period_key = ?',
                    [$eventLogId, $eventType, $periodKey]
                );

                $stats['fired']++;
                $stats['details'][] = [
                    'event_type' => $eventType,
                    'status' => 'fired',
                    'period_key' => $periodKey,
                    'report_date' => $reportDate,
                    'event_log_id' => $eventLogId,
                ];
            } catch (Throwable $e) {
                $stats['errors']++;
                $this->logger->error('Report schedule tick failed for rule', [
                    'event_type' => $eventType,
                    'error' => $e->getMessage(),
                ]);
                $stats['details'][] = [
                    'event_type' => $eventType,
                    'status' => 'error',
                    'reason' => $e->getMessage(),
                ];
            }
        }

        $this->logger->info('Report schedule tick completed', [
            'checked' => $stats['checked'],
            'fired' => $stats['fired'],
            'skipped' => $stats['skipped'],
            'errors' => $stats['errors'],
        ]);

        return $stats;
    }

    /**
     * All enabled rules that have a schedule filter and at least one create_report action.
     *
     * @return list<array<string, mixed>>
     */
    private function loadScheduleRules(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT event_type, enabled, actions, severity, conditions
             FROM fw_event_rules
             WHERE enabled = 1
               AND conditions IS NOT NULL
               AND conditions != ''
               AND conditions != 'null'
               AND JSON_EXTRACT(conditions, '$.time_conditions') IS NOT NULL"
        );

        $result = [];
        foreach ($rows as $row) {
            $actions = json_decode((string) ($row['actions'] ?? '[]'), true) ?: [];
            if (!$this->hasCreateReportAction($actions)) {
                continue;
            }
            $row['actions'] = $actions;
            $row['conditions'] = !empty($row['conditions'])
                ? json_decode((string) $row['conditions'], true)
                : null;
            $result[] = $row;
        }

        return $result;
    }

    /** @param mixed $actions */
    private function hasCreateReportAction(mixed $actions): bool
    {
        if (!is_array($actions)) {
            return false;
        }
        foreach ($actions as $action) {
            if (is_array($action) && strtolower((string) ($action['type'] ?? '')) === 'create_report') {
                return true;
            }
            if (is_string($action) && str_contains(strtolower($action), 'report')) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array{frequency: string, timezone: string, days_of_week: list<int>, day_of_month: int} $schedule
     */
    private function periodKeyFor(array $schedule, \DateTimeImmutable $now): string
    {
        try {
            $local = $now->setTimezone(new \DateTimeZone($schedule['timezone']));
        } catch (Throwable) {
            $local = $now;
        }

        return match ($schedule['frequency']) {
            'weekly' => $local->format('o-\WW'),
            'monthly' => $local->format('Y-m'),
            default => $local->format('Y-m-d'),
        };
    }

    private function reportDateFor(string $frequency, \DateTimeImmutable $now, string $timezone): string
    {
        try {
            $local = $now->setTimezone(new \DateTimeZone($timezone));
        } catch (Throwable) {
            $local = $now;
        }

        return match ($frequency) {
            'weekly' => $local->modify('monday this week')->modify('+6 days')->format('Y-m-d'),
            'monthly' => $local->modify('last day of this month')->format('Y-m-d'),
            default => $local->modify('-1 day')->format('Y-m-d'),
        };
    }

    private function claimFire(string $eventType, string $periodKey): bool
    {
        try {
            $this->connection->executeStatement(
                'INSERT INTO fw_report_schedule_fires (event_type, period_key, fired_at)
                 VALUES (?, ?, NOW())',
                [$eventType, $periodKey]
            );
            return true;
        } catch (Throwable $e) {
            // Duplicate key → already fired
            if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), '1062')) {
                return false;
            }
            throw $e;
        }
    }

    private function ensureFiresTable(): void
    {
        $this->connection->executeStatement(
            "CREATE TABLE IF NOT EXISTS fw_report_schedule_fires (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_type VARCHAR(64) NOT NULL,
                period_key VARCHAR(32) NOT NULL,
                fired_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                event_log_id BIGINT UNSIGNED NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_report_schedule_fire (event_type, period_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}
