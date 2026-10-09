<?php

namespace App\Support;

class ClientListContactFilter
{
    private const ALLOWED = ['name', 'phone', 'fax', 'email'];

    /**
     * @param array<int, string> $whereConditions
     */
    public static function apply(
        string $entity,
        array &$whereConditions,
        ?string $nonEmpty,
        ?string $empty,
        mixed $missingContacts,
    ): void {
        if (self::isTruthy($missingContacts)) {
            $sql = self::missingContactsSql($entity);
            if ($sql !== null) {
                $whereConditions[] = $sql;
            }
            return;
        }

        if ($empty !== null && $empty !== '' && in_array($empty, self::ALLOWED, true)) {
            $sql = self::emptySql($entity, $empty);
            if ($sql !== null) {
                $whereConditions[] = $sql;
            }
            return;
        }

        if ($nonEmpty === null || $nonEmpty === '') {
            return;
        }

        if (!in_array($nonEmpty, self::ALLOWED, true)) {
            return;
        }

        $sql = self::nonEmptySql($entity, $nonEmpty);
        if ($sql !== null) {
            $whereConditions[] = $sql;
        }
    }

    private static function isTruthy(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === false) {
            return false;
        }

        return in_array((string)$value, ['1', 'true', 'yes'], true) || $value === true || $value === 1;
    }

    private static function nonEmptySql(string $entity, string $field): ?string
    {
        return match ($entity) {
            'pharma' => match ($field) {
                'name' => "TRIM(COALESCE(operName, '')) != ''",
                'phone' => "(TRIM(COALESCE(phone, '')) != '' OR TRIM(COALESCE(cell, '')) != '')",
                'fax' => "TRIM(COALESCE(fax, '')) != ''",
                'email' => "TRIM(COALESCE(email, '')) != ''",
                default => null,
            },
            'physician' => match ($field) {
                'name' => "TRIM(COALESCE(fullName, '')) != ''",
                'phone' => "(TRIM(COALESCE(cellPhone, '')) != '' OR TRIM(COALESCE(officePhone, '')) != '')",
                'fax' => "TRIM(COALESCE(faxNumber, '')) != ''",
                'email' => "TRIM(COALESCE(email, '')) != ''",
                default => null,
            },
            'pharmacist' => match ($field) {
                'name' => "TRIM(COALESCE(pp.fullName, '')) != ''",
                'phone' => "TRIM(COALESCE(pp.cell_phone, '')) != ''",
                'fax' => null,
                'email' => "TRIM(COALESCE(pp.email, '')) != ''",
                default => null,
            },
            'medical_clinic' => match ($field) {
                'name' => "TRIM(COALESCE(clinicName, '')) != ''",
                'phone' => "TRIM(COALESCE(phone, '')) != ''",
                'fax' => "TRIM(COALESCE(fax, '')) != ''",
                'email' => "TRIM(COALESCE(email, '')) != ''",
                default => null,
            },
            default => null,
        };
    }

    private static function emptySql(string $entity, string $field): ?string
    {
        return match ($entity) {
            'pharma' => match ($field) {
                'name' => "TRIM(COALESCE(operName, '')) = ''",
                'phone' => "(TRIM(COALESCE(phone, '')) = '' AND TRIM(COALESCE(cell, '')) = '')",
                'fax' => "TRIM(COALESCE(fax, '')) = ''",
                'email' => "TRIM(COALESCE(email, '')) = ''",
                default => null,
            },
            'physician' => match ($field) {
                'name' => "TRIM(COALESCE(fullName, '')) = ''",
                'phone' => "(TRIM(COALESCE(cellPhone, '')) = '' AND TRIM(COALESCE(officePhone, '')) = '')",
                'fax' => "TRIM(COALESCE(faxNumber, '')) = ''",
                'email' => "TRIM(COALESCE(email, '')) = ''",
                default => null,
            },
            'pharmacist' => match ($field) {
                'name' => "TRIM(COALESCE(pp.fullName, '')) = ''",
                'phone' => "TRIM(COALESCE(pp.cell_phone, '')) = ''",
                'fax' => null,
                'email' => "TRIM(COALESCE(pp.email, '')) = ''",
                default => null,
            },
            'medical_clinic' => match ($field) {
                'name' => "TRIM(COALESCE(clinicName, '')) = ''",
                'phone' => "TRIM(COALESCE(phone, '')) = ''",
                'fax' => "TRIM(COALESCE(fax, '')) = ''",
                'email' => "TRIM(COALESCE(email, '')) = ''",
                default => null,
            },
            default => null,
        };
    }

    private static function missingContactsSql(string $entity): ?string
    {
        return match ($entity) {
            'pharma' => "(TRIM(COALESCE(phone, '')) = '' AND TRIM(COALESCE(cell, '')) = '' AND TRIM(COALESCE(fax, '')) = '' AND TRIM(COALESCE(email, '')) = '')",
            'physician' => "(TRIM(COALESCE(cellPhone, '')) = '' AND TRIM(COALESCE(officePhone, '')) = '' AND TRIM(COALESCE(faxNumber, '')) = '' AND TRIM(COALESCE(email, '')) = '')",
            'pharmacist' => "(TRIM(COALESCE(pp.cell_phone, '')) = '' AND TRIM(COALESCE(pp.email, '')) = '')",
            'medical_clinic' => "(TRIM(COALESCE(phone, '')) = '' AND TRIM(COALESCE(fax, '')) = '' AND TRIM(COALESCE(email, '')) = '')",
            default => null,
        };
    }
}
