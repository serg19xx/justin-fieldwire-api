<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Normalize North American phone numbers to E.164 (+1XXXXXXXXXX).
 * Matches TwilioService / NotificationMonitorConfig conventions.
 */
final class PhoneNormalizer
{
    /**
     * @return string|null Normalized E.164, empty input → null, unparseable → trimmed original or null
     */
    public static function toE164(?string $phone, bool $keepUnparseable = true): ?string
    {
        if ($phone === null) {
            return null;
        }
        $raw = trim($phone);
        if ($raw === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+' . $digits;
        }

        if (!$keepUnparseable) {
            return null;
        }

        return $raw;
    }

    /**
     * Apply to known phone keys on a mutable row/payload.
     *
     * @param array<string, mixed> $data
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    public static function normalizeKeys(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if ($value === null) {
                $data[$key] = null;
                continue;
            }
            if (!is_string($value) && !is_numeric($value)) {
                continue;
            }
            $data[$key] = self::toE164((string) $value);
        }

        return $data;
    }
}
