<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Public SPA base URL for links in emails (invites, password reset, etc.).
 * APP_URL points at the API — never use it for user-facing login links.
 */
final class FrontendUrl
{
    public static function resolve(): string
    {
        $configured = trim((string) ($_ENV['FRONTEND_URL'] ?? ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        foreach (explode(',', (string) ($_ENV['CORS_ALLOWED_ORIGINS'] ?? '')) as $origin) {
            $origin = trim($origin);
            if ($origin === '' || str_contains($origin, 'localhost') || str_contains($origin, '127.0.0.1')) {
                continue;
            }

            return rtrim($origin, '/');
        }

        if (($_ENV['APP_ENV'] ?? 'development') === 'production') {
            return 'https://fieldwire.medicalcontractor.ca';
        }

        return 'http://localhost:5174';
    }
}
