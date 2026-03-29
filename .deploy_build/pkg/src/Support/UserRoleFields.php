<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Normalizes role fields from fw_v_users (or equivalent row) for API responses.
 * Used by login and profile so role_category / role_code stay consistent.
 */
final class UserRoleFields
{
    /**
     * @param array<string, mixed> $user
     * @return array{role_id: int|null, role_code: string|null, role_name: string|null, role_category: string|null, role_description: string|null}
     */
    public static function fromUserRow(array $user): array
    {
        $roleId = $user['role_id'] ?? null;
        $roleIdInt = null;
        if ($roleId !== null && $roleId !== '' && is_numeric($roleId)) {
            $roleIdInt = (int) $roleId;
        }

        return [
            'role_id' => $roleIdInt,
            'role_code' => isset($user['role_code']) && $user['role_code'] !== ''
                ? (string) $user['role_code']
                : null,
            'role_name' => isset($user['role_name']) && $user['role_name'] !== ''
                ? (string) $user['role_name']
                : null,
            'role_category' => isset($user['role_category']) && $user['role_category'] !== ''
                ? (string) $user['role_category']
                : null,
            'role_description' => isset($user['role_description']) && $user['role_description'] !== ''
                ? (string) $user['role_description']
                : null,
        ];
    }
}
