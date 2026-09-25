<?php

declare(strict_types=1);

/**
 * Expand fw_prj_tasks.status from legacy ENUM to VARCHAR(50)
 * so rich UI statuses persist.
 *
 * Usage (from API project root):
 *   php scripts/run-expand-task-status-column.php
 */

$root = dirname(__DIR__);
$envFile = $root . '/.env';
if (!is_file($envFile)) {
    fwrite(STDERR, "Missing .env at {$envFile}\n");
    exit(1);
}

$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    if (!str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim($v, " \t\"'");
}

$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? '3306';
$db = $env['DB_NAME'] ?? '';
$user = $env['DB_USERNAME'] ?? '';
$pass = $env['DB_PASSWORD'] ?? '';

if ($db === '') {
    fwrite(STDERR, "DB_NAME is empty in .env\n");
    exit(1);
}

$statements = [
    "ALTER TABLE `fw_prj_tasks`
      MODIFY COLUMN `status` VARCHAR(50) NOT NULL DEFAULT 'planned'
      COMMENT 'Task status: planned, scheduled, scheduled_accepted, in_progress, partially_completed, delayed_due_to_issue, ready_for_inspection, completed (legacy: done, blocked, delayed)'",
    "UPDATE `fw_prj_tasks`
      SET `status` = 'planned'
      WHERE `status` IS NULL OR `status` = ''",
];

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    $before = $pdo->query("SHOW COLUMNS FROM fw_prj_tasks LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    echo 'Before: ' . ($before['Type'] ?? '?') . "\n";

    foreach ($statements as $statement) {
        $affected = $pdo->exec($statement);
        echo 'OK (affected=' . (int) $affected . '): ' . substr(preg_replace('/\s+/', ' ', $statement), 0, 72) . "...\n";
    }

    $after = $pdo->query("SHOW COLUMNS FROM fw_prj_tasks LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    echo 'After: ' . ($after['Type'] ?? '?') . "\n";
    echo "Done.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
