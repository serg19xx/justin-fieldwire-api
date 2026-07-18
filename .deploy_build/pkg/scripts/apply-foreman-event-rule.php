<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$db = new App\Database\Database();
$conn = $db->getConnection();

$sql = file_get_contents(__DIR__ . '/add-task-foreman-submitted-event-rule.sql');
if ($sql === false) {
    fwrite(STDERR, "SQL file not found\n");
    exit(1);
}

$conn->executeStatement($sql);

$row = $conn->fetchAssociative(
    'SELECT event_type, enabled FROM fw_event_rules WHERE event_type = ?',
    ['TASK_FOREMAN_SUBMITTED']
);

echo json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
