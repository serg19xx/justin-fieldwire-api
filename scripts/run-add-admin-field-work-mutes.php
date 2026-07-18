<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$conn = App\Database\Database::getConnection();

function columnExists(Doctrine\DBAL\Connection $conn, string $table, string $column): bool
{
    return (bool) $conn->executeQuery(
        'SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '` LIKE ' . $conn->quote($column)
    )->fetchOne();
}

function ensureColumn(
    Doctrine\DBAL\Connection $conn,
    string $table,
    string $column,
    string $definition
): void {
    if (columnExists($conn, $table, $column)) {
        echo "COLUMN {$table}.{$column} exists\n";
        return;
    }
    $conn->executeStatement(
        'ALTER TABLE `' . str_replace('`', '``', $table) . '` ADD COLUMN ' . $definition
    );
    echo "COLUMN {$table}.{$column} created\n";
}

ensureColumn(
    $conn,
    'fw_notification_preferences',
    'field_work_start_enabled',
    '`field_work_start_enabled` TINYINT(1) NOT NULL DEFAULT 1'
);
ensureColumn(
    $conn,
    'fw_notification_preferences',
    'field_work_end_enabled',
    '`field_work_end_enabled` TINYINT(1) NOT NULL DEFAULT 1'
);

echo "Done.\n";
