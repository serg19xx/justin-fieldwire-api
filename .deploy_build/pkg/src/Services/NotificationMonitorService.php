<?php

declare(strict_types=1);

namespace App\Services;

use Monolog\Logger;
use Throwable;

/**
 * Fan-out copies of outbound email/SMS to monitor addresses (when enabled in .env).
 */
class NotificationMonitorService
{
    private static bool $suppress = false;

    public static function isSuppressed(): bool
    {
        return self::$suppress;
    }

    public static function afterEmailSent(
        EmailService $emailService,
        string $originalTo,
        string $subject,
        string $htmlOrTextBody,
        string $originalToName = '',
    ): void {
        if (!NotificationMonitorConfig::isEnabled() || self::$suppress) {
            return;
        }

        $monitorEmails = NotificationMonitorConfig::extraEmails();
        if ($monitorEmails === []) {
            return;
        }

        $originalToNorm = strtolower(trim($originalTo));
        $monitorSubject = '[Monitor → ' . $originalTo . '] ' . $subject;
        $header = '<p style="margin:0 0 12px;font:12px Arial,sans-serif;color:#64748b;">'
            . 'Copy of outbound email to <strong>' . htmlspecialchars($originalTo, ENT_QUOTES, 'UTF-8') . '</strong>'
            . ($originalToName !== '' ? ' (' . htmlspecialchars($originalToName, ENT_QUOTES, 'UTF-8') . ')' : '')
            . '</p><hr style="border:none;border-top:1px solid #e2e8f0;margin:0 0 12px;">';
        $monitorBody = $header . $htmlOrTextBody;

        self::$suppress = true;
        try {
            foreach ($monitorEmails as $email) {
                if (strtolower(trim($email)) === $originalToNorm) {
                    continue;
                }
                try {
                    $emailService->sendEmail($email, $monitorSubject, $monitorBody, 'FieldWire Monitor');
                } catch (Throwable $e) {
                    self::logWarning('Monitor email copy failed', [
                        'monitor_email' => $email,
                        'original_to' => $originalTo,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            self::$suppress = false;
        }
    }

    public static function afterSmsSent(
        TwilioService $twilioService,
        string $originalTo,
        string $body,
    ): void {
        if (!NotificationMonitorConfig::isEnabled() || self::$suppress) {
            return;
        }

        $monitorPhones = NotificationMonitorConfig::extraPhones();
        if ($monitorPhones === [] || trim($body) === '') {
            return;
        }

        $originalNorm = NotificationMonitorConfig::normalizePhones([$originalTo])[0] ?? '';
        $monitorBody = '[Monitor → ' . $originalTo . '] ' . $body;
        if (strlen($monitorBody) > 1500) {
            $monitorBody = substr($monitorBody, 0, 1497) . '...';
        }

        self::$suppress = true;
        try {
            foreach ($monitorPhones as $phone) {
                if ($originalNorm !== '' && $phone === $originalNorm) {
                    continue;
                }
                try {
                    $twilioService->sendSms($phone, $monitorBody);
                } catch (Throwable $e) {
                    self::logWarning('Monitor SMS copy failed', [
                        'monitor_phone' => $phone,
                        'original_to' => $originalTo,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } finally {
            self::$suppress = false;
        }
    }

    /** @param array<string, mixed> $context */
    private static function logWarning(string $message, array $context): void
    {
        try {
            $logger = new Logger('notification-monitor');
            $logger->warning($message, $context);
        } catch (Throwable) {
            // ignore
        }
    }
}
