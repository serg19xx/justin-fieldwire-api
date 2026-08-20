<?php

declare(strict_types=1);

namespace App\Support;

use App\Database\Database;
use Doctrine\DBAL\Connection;

/**
 * Resolve client registry rows and contact fields for outbound communications.
 */
class ClientRegistryContacts
{
    private const ALLOWED_TYPES = ['pharma', 'physician', 'pharmacist', 'medical_clinic'];

    /** @var array<string, array{table: string, name_sql: string, select: string}> */
    private const TYPE_CONFIG = [
        'pharma' => [
            'table' => 'fw_pharma',
            'name_sql' => 'operName',
            'select' => 'id, operName, phone, cell, email, fax',
        ],
        'physician' => [
            'table' => 'fw_physician',
            'name_sql' => 'fullName',
            'select' => 'id, fullName, cellPhone, officePhone, email, faxNumber',
        ],
        'pharmacist' => [
            'table' => 'fw_pharmacist',
            'name_sql' => 'fullName',
            'select' => 'id, fullName, cell_phone, email',
        ],
        'medical_clinic' => [
            'table' => 'fw_medical_clinic',
            'name_sql' => 'clinicName',
            'select' => 'id, clinicName, contactName, phone, email, fax',
        ],
    ];

    public static function isAllowedType(string $type): bool
    {
        return in_array($type, self::ALLOWED_TYPES, true);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function fetchRow(string $type, int $id): ?array
    {
        if (!self::isAllowedType($type)) {
            return null;
        }

        $config = self::TYPE_CONFIG[$type];
        $conn = Database::getConnection();
        $sql = sprintf(
            'SELECT %s FROM %s WHERE id = ? LIMIT 1',
            $config['select'],
            $config['table'],
        );

        $row = $conn->executeQuery($sql, [$id])->fetchAssociative();
        return is_array($row) ? $row : null;
    }

    public static function displayName(string $type, array $row): string
    {
        $field = self::TYPE_CONFIG[$type]['name_sql'] ?? 'id';
        $name = trim((string) ($row[$field] ?? ''));
        return $name !== '' ? $name : 'Client #' . ($row['id'] ?? '');
    }

    public static function resolvePhone(string $type, array $row): ?string
    {
        $candidates = match ($type) {
            'pharma' => ['cell', 'phone'],
            'physician' => ['cellPhone', 'officePhone'],
            'pharmacist' => ['cell_phone'],
            'medical_clinic' => ['phone'],
            default => [],
        };

        foreach ($candidates as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    public static function resolveEmail(array $row): ?string
    {
        $value = trim((string) ($row['email'] ?? ''));
        return $value !== '' ? $value : null;
    }

    public static function resolveFax(string $type, array $row): ?string
    {
        $field = match ($type) {
            'physician' => 'faxNumber',
            default => 'fax',
        };

        $value = trim((string) ($row[$field] ?? ''));
        return $value !== '' ? $value : null;
    }
}
