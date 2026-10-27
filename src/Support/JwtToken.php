<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal HS256 JWT encode/decode shared by staff login, registration and contractor access keys.
 */
final class JwtToken
{
    /** Lifetime of a staff access token; active sessions are renewed by AuthMiddleware. */
    public const ACCESS_TTL_SECONDS = 7 * 24 * 3600;

    /** AuthMiddleware re-issues the access token once it is older than this. */
    public const RENEW_AFTER_SECONDS = 24 * 3600;

    public const RENEWED_TOKEN_HEADER = 'X-Renewed-Token';

    public static function secret(): string
    {
        return (string) ($_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?: 'your-secret-key-change-in-production');
    }

    public static function encode(array $payload, ?string $secret = null): string
    {
        $secret = $secret ?? self::secret();
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256'], JSON_THROW_ON_ERROR);
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $base64Header = self::b64($header);
        $base64Payload = self::b64($body);
        $signature = hash_hmac('sha256', $base64Header . '.' . $base64Payload, $secret, true);

        return $base64Header . '.' . $base64Payload . '.' . self::b64($signature);
    }

    /**
     * @param array{id: int|string, email?: string|null, first_name?: string|null, last_name?: string|null} $user
     */
    public static function issueAccessToken(array $user): string
    {
        $now = time();

        return self::encode([
            'user_id' => (int) $user['id'],
            'email' => $user['email'] ?? null,
            'name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
            'iat' => $now,
            'exp' => $now + self::ACCESS_TTL_SECONDS,
        ]);
    }

    /**
     * Returns the payload only when the signature is valid and the token has not expired.
     */
    public static function decode(string $token, ?string $secret = null): ?array
    {
        $secret = $secret ?? self::secret();
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerEncoded, $payloadEncoded, $signature] = $parts;
        $expected = self::b64(hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, $secret, true));
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $headerJson = self::unb64($headerEncoded);
        $header = $headerJson !== null ? json_decode($headerJson, true) : null;
        if (!is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
            return null;
        }

        $payloadJson = self::unb64($payloadEncoded);
        $payload = $payloadJson !== null ? json_decode($payloadJson, true) : null;
        if (!is_array($payload)) {
            return null;
        }

        if (!isset($payload['exp']) || (int) $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $encoded): ?string
    {
        $raw = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);

        return $raw === false ? null : $raw;
    }
}
