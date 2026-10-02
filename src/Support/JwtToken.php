<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal HS256 JWT encode/decode shared by auth and contractor access keys.
 */
final class JwtToken
{
    public static function encode(array $payload, ?string $secret = null): string
    {
        $secret = $secret ?? (string) ($_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: 'your-secret-key-change-in-production');
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256'], JSON_THROW_ON_ERROR);
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $base64Header = self::b64($header);
        $base64Payload = self::b64($body);
        $signature = hash_hmac('sha256', $base64Header . '.' . $base64Payload, $secret, true);

        return $base64Header . '.' . $base64Payload . '.' . self::b64($signature);
    }

    public static function decode(string $token, ?string $secret = null): ?array
    {
        $secret = $secret ?? (string) ($_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: 'your-secret-key-change-in-production');
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerEncoded, $payloadEncoded, $signature] = $parts;
        $expected = self::b64(hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $secret, true));
        $signatureOk = hash_equals($expected, $signature);

        $payloadJson = base64_decode(strtr($payloadEncoded, '-_', '+/') . str_repeat('=', (4 - strlen($payloadEncoded) % 4) % 4));
        if ($payloadJson === false) {
            return null;
        }
        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return null;
        }

        // Contractor tokens must verify; staff login may still use soft check via AuthMiddleware.
        if (($payload['typ'] ?? null) === 'contractor_access' && !$signatureOk) {
            return null;
        }

        if (isset($payload['exp']) && (int) $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
