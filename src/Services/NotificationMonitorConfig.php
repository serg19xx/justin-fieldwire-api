<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Ops / developer copies of all outbound notifications.
 *
 * NOTIFICATION_MONITOR_ENABLED=1|0
 * NOTIFICATION_MONITOR_EMAILS=comma-separated
 * NOTIFICATION_MONITOR_PHONES=comma-separated (e.g. 6477012491 or +16477012491)
 */
class NotificationMonitorConfig
{
    public static function isEnabled(): bool
    {
        $raw = $_ENV['NOTIFICATION_MONITOR_ENABLED'] ?? getenv('NOTIFICATION_MONITOR_ENABLED');
        if ($raw === null || $raw === '') {
            return false;
        }

        return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return list<string> */
    public static function extraEmails(): array
    {
        if (!self::isEnabled()) {
            return [];
        }

        return self::parseList($_ENV['NOTIFICATION_MONITOR_EMAILS'] ?? getenv('NOTIFICATION_MONITOR_EMAILS') ?: '');
    }

    /** @return list<string> */
    public static function extraPhones(): array
    {
        if (!self::isEnabled()) {
            return [];
        }

        return self::normalizePhones(
            self::parseList($_ENV['NOTIFICATION_MONITOR_PHONES'] ?? getenv('NOTIFICATION_MONITOR_PHONES') ?: '')
        );
    }

    /** @return list<string> */
    private static function parseList(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/[,;]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $value = trim((string) $part);
            if ($value !== '') {
                $out[] = $value;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param list<string> $phones
     * @return list<string>
     */
    public static function normalizePhones(array $phones): array
    {
        $out = [];
        foreach ($phones as $phone) {
            $digits = preg_replace('/\D+/', '', $phone) ?? '';
            if ($digits === '') {
                continue;
            }
            if (strlen($digits) === 10) {
                $out[] = '+1' . $digits;
            } elseif (str_starts_with($digits, '1') && strlen($digits) === 11) {
                $out[] = '+' . $digits;
            } else {
                $out[] = '+' . ltrim($digits, '+');
            }
        }

        return array_values(array_unique($out));
    }
}
