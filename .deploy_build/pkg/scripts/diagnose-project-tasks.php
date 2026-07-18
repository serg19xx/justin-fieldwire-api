<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$projectId = isset($argv[1]) ? (int) $argv[1] : 0;

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
    $_ENV['DB_HOST'],
    $_ENV['DB_PORT'],
    $_ENV['DB_NAME'],
    $_ENV['DB_CHARSET'],
);
$pdo = new PDO($dsn, $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Task counts by project (top 15):\n";
$rows = $pdo->query(
    "SELECT project_id,
            COUNT(*) AS total,
            SUM(CASE WHEN start_planned IS NULL OR start_planned = '' OR start_planned = '0000-00-00' THEN 1 ELSE 0 END) AS no_date
     FROM fw_prj_tasks
     GROUP BY project_id
     ORDER BY total DESC
     LIMIT 15",
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    echo sprintf(
        "  project_id=%s total=%s no_date=%s with_date=%s\n",
        $row['project_id'],
        $row['total'],
        $row['no_date'],
        (int) $row['total'] - (int) $row['no_date'],
    );
}

if ($projectId > 0) {
    echo "\nDetails for project_id={$projectId}:\n";
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM fw_prj_tasks WHERE project_id = ?');
    $stmt->execute([$projectId]);
    echo '  total=' . $stmt->fetchColumn() . "\n";

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM fw_prj_tasks
         WHERE project_id = ?
           AND (start_planned IS NULL OR start_planned = '' OR start_planned = '0000-00-00')",
    );
    $stmt->execute([$projectId]);
    echo '  no_start_planned=' . $stmt->fetchColumn() . "\n";

    $stmt = $pdo->prepare(
        'SELECT MIN(task_order) AS min_order, MAX(task_order) AS max_order FROM fw_prj_tasks WHERE project_id = ?',
    );
    $stmt->execute([$projectId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    echo '  task_order range=' . ($order['min_order'] ?? 'null') . '..' . ($order['max_order'] ?? 'null') . "\n";
}
