<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Public API base URL and user media URLs (avatar, full photo).
 */
final class ApiUrl
{
    public static function base(): string
    {
        $url = trim((string) ($_ENV['APP_URL'] ?? ''));
        if ($url !== '') {
            return rtrim($url, '/');
        }

        return 'http://localhost:8000';
    }

    public static function avatar(?string $stored): ?string
    {
        return self::mediaUrl($stored, '/api/v1/avatar');
    }

    public static function fullImage(?string $stored): ?string
    {
        return self::mediaUrl($stored, '/api/v1/full-image');
    }

    private static function mediaUrl(?string $stored, string $endpoint): ?string
    {
        if ($stored === null) {
            return null;
        }

        $stored = trim($stored);
        if ($stored === '') {
            return null;
        }

        $filename = self::extractFilename($stored);
        if ($filename === null || $filename === '') {
            return null;
        }

        return self::base() . $endpoint . '?file=' . rawurlencode($filename);
    }

    private static function extractFilename(string $stored): ?string
    {
        if (str_contains($stored, 'file=')) {
            $query = parse_url($stored, PHP_URL_QUERY);
            if (is_string($query) && $query !== '') {
                parse_str($query, $params);
                if (!empty($params['file']) && is_string($params['file'])) {
                    return basename($params['file']);
                }
            }
        }

        $path = parse_url($stored, PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            return basename($path);
        }

        return basename($stored);
    }
}
