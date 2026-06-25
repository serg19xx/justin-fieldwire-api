<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

use App\Database\Database;

$projectId = (int) ($argv[1] ?? 63);
$limit = isset($argv[2]) ? (int) $argv[2] : 0;

$connection = Database::getConnection();

$whereSql = ' WHERE project_id = ?';
$params = [$projectId];

$countResult = $connection->executeQuery('SELECT COUNT(*) FROM fw_prj_tasks' . $whereSql, $params);
$totalTasks = (int) $countResult->fetchOne();

$sql = 'SELECT id, task_order, project_id, address, name, start_planned, end_planned, start_time, end_time, milestone, status, progress_pct, notes, resources, baseline_start, baseline_end, actual_start, actual_end, slack_days, created_at, updated_at FROM fw_prj_tasks'
    . $whereSql
    . ' ORDER BY task_order ASC, start_planned ASC';

if ($limit > 0) {
    $sql .= ' LIMIT ' . $limit;
}

$result = $connection->executeQuery($sql, $params);
$tasks = $result->fetchAllAssociative();

$taskIds = array_column($tasks, 'id');
$assigneesCount = 0;
if (!empty($taskIds)) {
    foreach (array_chunk($taskIds, 200) as $chunk) {
        $placeholders = str_repeat('?,', count($chunk) - 1) . '?';
        $assigneesSql = "SELECT task_id, user_id, role_in_project, invited_people FROM fw_prj_team_members WHERE task_id IN ($placeholders)";
        $assigneesResult = $connection->executeQuery($assigneesSql, $chunk);
        $assigneesCount += count($assigneesResult->fetchAllAssociative());
    }
}

$payload = [
    'error_code' => 0,
    'status' => 'success',
    'data' => [
        'tasks' => $tasks,
        'dependencies' => [],
        'pagination' => [
            'current_page' => 1,
            'per_page' => $limit > 0 ? $limit : $totalTasks,
            'total' => $totalTasks,
            'last_page' => 1,
        ],
    ],
];

$json = json_encode($payload);
echo 'total_in_db=' . $totalTasks . PHP_EOL;
echo 'tasks_fetched=' . count($tasks) . PHP_EOL;
echo 'assignee_rows=' . $assigneesCount . PHP_EOL;
echo 'json_bytes=' . ($json === false ? 0 : strlen($json)) . PHP_EOL;
echo 'json_error=' . (json_last_error_msg()) . PHP_EOL;
