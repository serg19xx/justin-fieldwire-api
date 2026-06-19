<?php

declare(strict_types=1);

namespace App\Support;

/**
 * When enabled, redirect client outbound comms to fixed test destinations (dev/staging only).
 */
class ClientCommsTestRedirect
{
    public static function isEnabled(): bool
    {
        $flag = strtolower(trim((string) ($_ENV['CLIENTS_COMMS_TEST_MODE'] ?? '')));
        return in_array($flag, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return array{destination: string, original: string, test_mode: bool}
     */
    public static function phone(string $original): array
    {
        $original = trim($original);
        if (!self::isEnabled()) {
            return ['destination' => $original, 'original' => $original, 'test_mode' => false];
        }

        $test = trim((string) ($_ENV['CLIENTS_COMMS_TEST_PHONE'] ?? ''));
        if ($test === '') {
            return ['destination' => $original, 'original' => $original, 'test_mode' => false];
        }

        return ['destination' => $test, 'original' => $original, 'test_mode' => true];
    }

    /**
     * @return array{destination: string, original: string, test_mode: bool}
     */
    public static function email(string $original): array
    {
        $original = trim($original);
        if (!self::isEnabled()) {
            return ['destination' => $original, 'original' => $original, 'test_mode' => false];
        }

        $test = trim((string) ($_ENV['CLIENTS_COMMS_TEST_EMAIL'] ?? ''));
        if ($test === '') {
            return ['destination' => $original, 'original' => $original, 'test_mode' => false];
        }

        return ['destination' => $test, 'original' => $original, 'test_mode' => true];
    }

    /**
     * @return array{destination: string, original: string, test_mode: bool}
     */
    public static function fax(string $original): array
    {
        $original = trim($original);
        if (!self::isEnabled()) {
            return ['destination' => $original, 'original' => $original, 'test_mode' => false];
        }

        $test = trim((string) ($_ENV['CLIENTS_COMMS_TEST_FAX'] ?? ''));
        if ($test === '') {
            $test = trim((string) ($_ENV['CLIENTS_COMMS_TEST_PHONE'] ?? ''));
        }
        if ($test === '') {
            return ['destination' => $original, 'original' => $original, 'test_mode' => false];
        }

        return ['destination' => $test, 'original' => $original, 'test_mode' => true];
    }
}
