<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Wall-clock for worker Start/End punches (Ontario construction default).
 */
class WorkClockService
{
    public const DEFAULT_TIMEZONE = 'America/Toronto';

    public function timezoneName(): string
    {
        $raw = $_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: null;
        if (is_string($raw) && trim($raw) !== '') {
            try {
                new \DateTimeZone(trim($raw));

                return trim($raw);
            } catch (\Throwable) {
                // fall through
            }
        }

        return self::DEFAULT_TIMEZONE;
    }

    public function timezone(): \DateTimeZone
    {
        return new \DateTimeZone($this->timezoneName());
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $this->timezone());
    }

    /** Calendar day YYYY-MM-DD in app timezone. */
    public function todayYmd(?\DateTimeImmutable $now = null): string
    {
        return ($now ?? $this->now())->format('Y-m-d');
    }

    /** DATETIME(3) string in app timezone for MySQL storage. */
    public function nowSql(?\DateTimeImmutable $now = null): string
    {
        return ($now ?? $this->now())->format('Y-m-d H:i:s.v');
    }

    /**
     * Interpret a DB DATETIME (no TZ) as app-local wall clock and return ISO-8601 with offset.
     */
    public function toApiIso(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if ($raw instanceof \DateTimeInterface) {
            $dt = \DateTimeImmutable::createFromInterface($raw)->setTimezone($this->timezone());

            return $dt->format('c');
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }
        // Already has offset / Z
        if (preg_match('/[zZ]|[+-]\d{2}:?\d{2}$/', $s) === 1) {
            try {
                return (new \DateTimeImmutable($s))->setTimezone($this->timezone())->format('c');
            } catch (\Throwable) {
                return null;
            }
        }
        // MySQL "Y-m-d H:i:s(.u)" → treat as app timezone wall clock
        $normalized = str_replace(' ', 'T', $s);
        try {
            $dt = new \DateTimeImmutable($normalized, $this->timezone());

            return $dt->format('c');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{ok: true}|array{ok: false, message: string}
     */
    public function assertWorkDateIsToday(string $workDateYmd, ?\DateTimeImmutable $now = null): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workDateYmd)) {
            return ['ok' => false, 'message' => 'work_date must be YYYY-MM-DD'];
        }
        $today = $this->todayYmd($now);
        if ($workDateYmd !== $today) {
            return [
                'ok' => false,
                'message' => sprintf(
                    'You can only start or end work for today (%s). Selected day is %s.',
                    $today,
                    $workDateYmd
                ),
            ];
        }

        return ['ok' => true];
    }
}
