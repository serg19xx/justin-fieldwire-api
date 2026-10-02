<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use Flight;
use Monolog\Logger;

/**
 * CRUD for external contacts without login: contractors and inspectors.
 */
class ExternalContactController
{
    private Logger $logger;
    private Database $database;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->database = new Database();
    }

    private function checkAuth(): bool
    {
        $currentUser = Flight::get('current_user');
        if (!$currentUser) {
            Flight::json([
                'error_code' => 401,
                'status' => 'error',
                'message' => 'Unauthorized',
                'data' => null,
            ], 401);

            return false;
        }

        return true;
    }

    /**
     * @return array{table: string, trade_col: string, label: string}|null
     */
    private function resolveKind(string $kind): ?array
    {
        return match ($kind) {
            'contractors' => [
                'table' => 'fw_contractors',
                'trade_col' => 'trade',
                'label' => 'Contractor',
            ],
            'inspectors' => [
                'table' => 'fw_inspectors',
                'trade_col' => 'specialty',
                'label' => 'Inspector',
            ],
            default => null,
        };
    }

    public function list(string $kind): void
    {
        if (!$this->checkAuth()) {
            return;
        }

        $meta = $this->resolveKind($kind);
        if ($meta === null) {
            Flight::json(['error_code' => 404, 'status' => 'error', 'message' => 'Unknown contact type', 'data' => null], 404);

            return;
        }

        try {
            $connection = $this->database->getConnection();
            $q = Flight::request()->query;
            $search = trim((string) ($q['search'] ?? ''));
            $activeOnly = !isset($q['include_inactive']) || (string) $q['include_inactive'] === '0' || (string) $q['include_inactive'] === '';

            $hasNameParts = $kind === 'contractors';
            $selectExtra = $hasNameParts ? 'first_name, last_name, ' : '';
            $sql = 'SELECT id, ' . $selectExtra . 'name, company, phone, email, ' . $meta['trade_col'] . ' AS trade_or_specialty, notes, is_active, created_at, updated_at FROM '
                . $meta['table'] . ' WHERE 1=1';
            $params = [];

            if ($activeOnly) {
                $sql .= ' AND is_active = 1';
            }
            if ($search !== '') {
                if ($hasNameParts) {
                    $sql .= ' AND (name LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR company LIKE ? OR email LIKE ? OR phone LIKE ? OR ' . $meta['trade_col'] . ' LIKE ?)';
                    $term = '%' . $search . '%';
                    array_push($params, $term, $term, $term, $term, $term, $term, $term);
                } else {
                    $sql .= ' AND (name LIKE ? OR company LIKE ? OR email LIKE ? OR phone LIKE ? OR ' . $meta['trade_col'] . ' LIKE ?)';
                    $term = '%' . $search . '%';
                    array_push($params, $term, $term, $term, $term, $term);
                }
            }
            $sql .= ' ORDER BY name ASC';

            $rows = $connection->executeQuery($sql, $params)->fetchAllAssociative();
            $items = array_map(fn (array $row) => $this->formatRow($row, $meta['trade_col']), $rows);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => $meta['label'] . 's retrieved successfully',
                'data' => [
                    $kind => $items,
                    'total' => count($items),
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to list ' . $kind, ['error' => $e->getMessage()]);
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to list contacts: ' . $e->getMessage(),
                'data' => null,
            ], 500);
        }
    }

    public function getOne(string $kind, int $id): void
    {
        if (!$this->checkAuth()) {
            return;
        }

        $meta = $this->resolveKind($kind);
        if ($meta === null) {
            Flight::json(['error_code' => 404, 'status' => 'error', 'message' => 'Unknown contact type', 'data' => null], 404);

            return;
        }

        try {
            $connection = $this->database->getConnection();
            $row = $connection->executeQuery(
                $kind === 'contractors'
                    ? 'SELECT id, first_name, last_name, name, company, phone, email, ' . $meta['trade_col'] . ' AS trade_or_specialty, notes, is_active, created_at, updated_at FROM '
                        . $meta['table'] . ' WHERE id = ?'
                    : 'SELECT id, name, company, phone, email, ' . $meta['trade_col'] . ' AS trade_or_specialty, notes, is_active, created_at, updated_at FROM '
                        . $meta['table'] . ' WHERE id = ?',
                [$id]
            )->fetchAssociative();

            if (!$row) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => $meta['label'] . ' not found',
                    'data' => null,
                ], 404);

                return;
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => $meta['label'] . ' retrieved successfully',
                'data' => [$kind === 'contractors' ? 'contractor' : 'inspector' => $this->formatRow($row, $meta['trade_col'])],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get ' . $kind, ['id' => $id, 'error' => $e->getMessage()]);
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to get contact: ' . $e->getMessage(),
                'data' => null,
            ], 500);
        }
    }

    public function create(string $kind): void
    {
        if (!$this->checkAuth()) {
            return;
        }

        $meta = $this->resolveKind($kind);
        if ($meta === null) {
            Flight::json(['error_code' => 404, 'status' => 'error', 'message' => 'Unknown contact type', 'data' => null], 404);

            return;
        }

        try {
            $data = Flight::request()->data->getData();
            if (!is_array($data)) {
                $data = [];
            }

            $firstName = $kind === 'contractors' ? $this->nullableString($data['first_name'] ?? null) : null;
            $lastName = $kind === 'contractors' ? $this->nullableString($data['last_name'] ?? null) : null;
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '' && ($firstName !== null || $lastName !== null)) {
                $name = trim(($firstName ?? '') . ' ' . ($lastName ?? ''));
            }
            if ($name === '' && !empty($data['company'])) {
                $name = trim((string) $data['company']);
            }
            if ($name === '') {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Name is required (or first_name / company)',
                    'data' => null,
                ], 400);

                return;
            }

            $trade = $this->nullableString($data[$meta['trade_col']] ?? $data['trade'] ?? $data['specialty'] ?? null);
            $connection = $this->database->getConnection();
            if ($kind === 'contractors') {
                $connection->executeStatement(
                    'INSERT INTO fw_contractors (first_name, last_name, name, company, phone, email, trade, notes, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $firstName,
                        $lastName,
                        $name,
                        $this->nullableString($data['company'] ?? null),
                        $this->nullableString($data['phone'] ?? $data['cell'] ?? null),
                        $this->nullableString($data['email'] ?? null),
                        $trade,
                        $this->nullableString($data['notes'] ?? null),
                        isset($data['is_active']) ? ((int) (bool) $data['is_active']) : 1,
                    ]
                );
            } else {
                $connection->executeStatement(
                    'INSERT INTO ' . $meta['table'] . ' (name, company, phone, email, ' . $meta['trade_col'] . ', notes, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [
                        $name,
                        $this->nullableString($data['company'] ?? null),
                        $this->nullableString($data['phone'] ?? null),
                        $this->nullableString($data['email'] ?? null),
                        $trade,
                        $this->nullableString($data['notes'] ?? null),
                        isset($data['is_active']) ? ((int) (bool) $data['is_active']) : 1,
                    ]
                );
            }

            $id = (int) $connection->lastInsertId();
            $this->getOne($kind, $id);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create ' . $kind, ['error' => $e->getMessage()]);
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to create contact: ' . $e->getMessage(),
                'data' => null,
            ], 500);
        }
    }

    public function update(string $kind, int $id): void
    {
        if (!$this->checkAuth()) {
            return;
        }

        $meta = $this->resolveKind($kind);
        if ($meta === null) {
            Flight::json(['error_code' => 404, 'status' => 'error', 'message' => 'Unknown contact type', 'data' => null], 404);

            return;
        }

        try {
            $connection = $this->database->getConnection();
            $exists = $connection->executeQuery('SELECT id FROM ' . $meta['table'] . ' WHERE id = ?', [$id])->fetchOne();
            if (!$exists) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => $meta['label'] . ' not found',
                    'data' => null,
                ], 404);

                return;
            }

            $data = Flight::request()->data->getData();
            if (!is_array($data)) {
                $data = [];
            }

            $fields = [];
            $params = [];
            if ($kind === 'contractors') {
                if (array_key_exists('first_name', $data) || array_key_exists('last_name', $data) || array_key_exists('name', $data)) {
                    $firstName = array_key_exists('first_name', $data)
                        ? $this->nullableString($data['first_name'])
                        : null;
                    $lastName = array_key_exists('last_name', $data)
                        ? $this->nullableString($data['last_name'])
                        : null;
                    if (array_key_exists('first_name', $data)) {
                        $fields[] = 'first_name = ?';
                        $params[] = $firstName;
                    }
                    if (array_key_exists('last_name', $data)) {
                        $fields[] = 'last_name = ?';
                        $params[] = $lastName;
                    }
                    $name = array_key_exists('name', $data) ? trim((string) $data['name']) : '';
                    if ($name === '' && (array_key_exists('first_name', $data) || array_key_exists('last_name', $data))) {
                        $existing = $connection->executeQuery(
                            'SELECT first_name, last_name, name FROM fw_contractors WHERE id = ?',
                            [$id]
                        )->fetchAssociative() ?: [];
                        $fn = array_key_exists('first_name', $data) ? $firstName : ($existing['first_name'] ?? null);
                        $ln = array_key_exists('last_name', $data) ? $lastName : ($existing['last_name'] ?? null);
                        $name = trim(($fn ?? '') . ' ' . ($ln ?? ''));
                        if ($name === '') {
                            $name = trim((string) ($existing['name'] ?? ''));
                        }
                    }
                    if ($name !== '') {
                        $fields[] = 'name = ?';
                        $params[] = $name;
                    }
                }
            } elseif (array_key_exists('name', $data)) {
                $name = trim((string) $data['name']);
                if ($name === '') {
                    Flight::json([
                        'error_code' => 400,
                        'status' => 'error',
                        'message' => 'Name cannot be empty',
                        'data' => null,
                    ], 400);

                    return;
                }
                $fields[] = 'name = ?';
                $params[] = $name;
            }
            foreach (['company', 'phone', 'email', 'notes'] as $col) {
                if (array_key_exists($col, $data) || ($col === 'phone' && array_key_exists('cell', $data))) {
                    $fields[] = $col . ' = ?';
                    $params[] = $this->nullableString($data[$col] ?? $data['cell'] ?? null);
                }
            }
            if (array_key_exists($meta['trade_col'], $data) || array_key_exists('trade', $data) || array_key_exists('specialty', $data)) {
                $fields[] = $meta['trade_col'] . ' = ?';
                $params[] = $this->nullableString($data[$meta['trade_col']] ?? $data['trade'] ?? $data['specialty'] ?? null);
            }
            if (array_key_exists('is_active', $data)) {
                $fields[] = 'is_active = ?';
                $params[] = (int) (bool) $data['is_active'];
            }

            if ($fields === []) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'No fields to update',
                    'data' => null,
                ], 400);

                return;
            }

            $params[] = $id;
            $connection->executeStatement(
                'UPDATE ' . $meta['table'] . ' SET ' . implode(', ', $fields) . ' WHERE id = ?',
                $params
            );

            $this->getOne($kind, $id);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to update ' . $kind, ['id' => $id, 'error' => $e->getMessage()]);
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to update contact: ' . $e->getMessage(),
                'data' => null,
            ], 500);
        }
    }

    public function delete(string $kind, int $id): void
    {
        if (!$this->checkAuth()) {
            return;
        }

        $meta = $this->resolveKind($kind);
        if ($meta === null) {
            Flight::json(['error_code' => 404, 'status' => 'error', 'message' => 'Unknown contact type', 'data' => null], 404);

            return;
        }

        try {
            $connection = $this->database->getConnection();
            // Soft-delete to preserve task FKs
            $affected = $connection->executeStatement(
                'UPDATE ' . $meta['table'] . ' SET is_active = 0 WHERE id = ?',
                [$id]
            );

            if ($affected === 0) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => $meta['label'] . ' not found',
                    'data' => null,
                ], 404);

                return;
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => $meta['label'] . ' deactivated successfully',
                'data' => ['id' => $id],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to delete ' . $kind, ['id' => $id, 'error' => $e->getMessage()]);
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to deactivate contact: ' . $e->getMessage(),
                'data' => null,
            ], 500);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatRow(array $row, string $tradeCol): array
    {
        $tradeValue = $row['trade_or_specialty'] ?? $row[$tradeCol] ?? null;

        return [
            'id' => (int) $row['id'],
            'first_name' => isset($row['first_name']) && $row['first_name'] !== null ? (string) $row['first_name'] : null,
            'last_name' => isset($row['last_name']) && $row['last_name'] !== null ? (string) $row['last_name'] : null,
            'name' => (string) $row['name'],
            'company' => $row['company'] !== null ? (string) $row['company'] : null,
            'phone' => $row['phone'] !== null ? (string) $row['phone'] : null,
            'email' => $row['email'] !== null ? (string) $row['email'] : null,
            'trade' => $tradeCol === 'trade' ? ($tradeValue !== null ? (string) $tradeValue : null) : null,
            'specialty' => $tradeCol === 'specialty' ? ($tradeValue !== null ? (string) $tradeValue : null) : null,
            'notes' => $row['notes'] !== null ? (string) $row['notes'] : null,
            'is_active' => (bool) ($row['is_active'] ?? true),
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
}
