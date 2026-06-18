<?php

namespace App\Support;

class ClientListSort
{
    /**
     * Build a safe ORDER BY clause from whitelisted column expressions.
     *
     * @param array<string, string> $allowed Maps API sortBy key => SQL expression
     */
    public static function resolveOrderBy(
        ?string $sortBy,
        ?string $sortDir,
        array $allowed,
        string $defaultKey,
        string $defaultDir = 'ASC',
    ): string {
        $key = $sortBy ?? $defaultKey;
        if (!isset($allowed[$key])) {
            $key = $defaultKey;
        }

        $expression = $allowed[$key];
        $dir = strtoupper((string)($sortDir ?? $defaultDir)) === 'DESC' ? 'DESC' : 'ASC';

        return "$expression $dir";
    }
}
