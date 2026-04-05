<?php

declare(strict_types=1);

/**
 * DB integration checks for weekly schedule tables and happy-path CRUD.
 *
 * Full run requires env:
 *   SCHEDULE_TEST_PROJECT_ID
 *   SCHEDULE_TEST_USER_ID
 *   SCHEDULE_TEST_TASK_ID
 * and a row in fw_prj_team_members linking user + task + project.
 *
 * Loads .env from project root when present (same as public/index.php).
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database\Database;
use DateTimeImmutable;

$root = dirname(__DIR__);
try {
    Dotenv\Dotenv::createImmutable($root)->load();
} catch (\Throwable) {
    // optional .env
}

$conn = Database::getConnection();

foreach (['fw_schedule_weeks', 'fw_worker_task_schedules', 'fw_worker_task_schedule_snapshots'] as $table) {
    $exists = $conn->executeQuery(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
        [$table]
    )->fetchOne();
    if (!$exists) {
        fwrite(STDERR, "Skip or apply migration: missing table {$table}\n");
        exit(0);
    }
}

$projectId = getenv('SCHEDULE_TEST_PROJECT_ID');
$userId = getenv('SCHEDULE_TEST_USER_ID');
$taskId = getenv('SCHEDULE_TEST_TASK_ID');

if ($projectId === false || $projectId === '' || $userId === false || $userId === ''
    || $taskId === false || $taskId === '') {
    echo "Tables OK. Set SCHEDULE_TEST_PROJECT_ID, SCHEDULE_TEST_USER_ID, SCHEDULE_TEST_TASK_ID for full E2E.\n";
    exit(0);
}

$projectId = (int) $projectId;
$userId = (int) $userId;
$taskId = (int) $taskId;

$assigneeOk = (bool) $conn->executeQuery(
    'SELECT 1 FROM fw_prj_team_members WHERE project_id = ? AND task_id = ? AND user_id = ? LIMIT 1',
    [$projectId, $taskId, $userId]
)->fetchOne();
if (!$assigneeOk) {
    fwrite(STDERR, "fw_prj_team_members has no row for project={$projectId} task={$taskId} user={$userId}\n");
    exit(1);
}

$taskOk = (bool) $conn->executeQuery(
    'SELECT 1 FROM fw_prj_tasks WHERE id = ? AND project_id = ?',
    [$taskId, $projectId]
)->fetchOne();
if (!$taskOk) {
    fwrite(STDERR, "task {$taskId} not in project {$projectId}\n");
    exit(1);
}

$monday = (new DateTimeImmutable('monday this week'))->format('Y-m-d');
$workDate = (new DateTimeImmutable($monday))->modify('+1 day')->format('Y-m-d');
$weekId = null;

try {
    $conn->beginTransaction();

    $conn->executeStatement(
        'DELETE FROM fw_schedule_weeks WHERE project_id = ? AND week_start = ?',
        [$projectId, $monday]
    );

    $conn->insert('fw_schedule_weeks', [
        'project_id' => $projectId,
        'week_start' => $monday,
        'status' => 'draft',
        'published_at' => null,
        'published_by' => null,
    ]);
    $weekId = (int) $conn->lastInsertId();

    $conn->insert('fw_worker_task_schedules', [
        'schedule_week_id' => $weekId,
        'project_id' => $projectId,
        'user_id' => $userId,
        'task_id' => $taskId,
        'work_date' => $workDate,
        'day_part' => 'am',
        'assignment_note' => 'integration test note',
    ]);

    $countDraft = (int) $conn->executeQuery(
        'SELECT COUNT(*) FROM fw_worker_task_schedules s
         INNER JOIN fw_schedule_weeks w ON w.id = s.schedule_week_id
         WHERE s.schedule_week_id = ? AND s.user_id = ? AND w.status = ? AND s.work_date = ?',
        [$weekId, $userId, 'draft', $workDate]
    )->fetchOne();
    if ($countDraft !== 1) {
        throw new RuntimeException('Expected one draft row before publish');
    }

    $conn->executeStatement(
        'UPDATE fw_schedule_weeks SET status = ?, published_at = NOW(3), published_by = ?, updated_at = NOW(3) WHERE id = ?',
        ['published', $userId, $weekId]
    );

    $conn->executeStatement(
        'DELETE FROM fw_worker_task_schedule_snapshots WHERE schedule_week_id = ?',
        [$weekId]
    );
    $conn->executeStatement(
        'INSERT INTO fw_worker_task_schedule_snapshots
            (worker_task_schedule_id, schedule_week_id, project_id, user_id, task_id, work_date, day_part, assignment_note, snapshot_at)
         SELECT id, schedule_week_id, project_id, user_id, task_id, work_date, day_part, assignment_note, NOW(3)
         FROM fw_worker_task_schedules WHERE schedule_week_id = ?',
        [$weekId]
    );

    $snapCount = (int) $conn->executeQuery(
        'SELECT COUNT(*) FROM fw_worker_task_schedule_snapshots WHERE schedule_week_id = ?',
        [$weekId]
    )->fetchOne();
    if ($snapCount !== 1) {
        throw new RuntimeException('Expected one snapshot row after publish, got ' . $snapCount);
    }

    $countPublished = (int) $conn->executeQuery(
        'SELECT COUNT(*) FROM fw_worker_task_schedules s
         INNER JOIN fw_schedule_weeks w ON w.id = s.schedule_week_id
         WHERE s.schedule_week_id = ? AND s.user_id = ? AND w.status = ?',
        [$weekId, $userId, 'published']
    )->fetchOne();
    if ($countPublished < 1) {
        throw new RuntimeException('Expected published schedule visible in range');
    }

    $conn->commit();
    echo "OK: created draft, inserted entry, published, verified published row for user {$userId}.\n";
} catch (Throwable $e) {
    if ($conn->isTransactionActive()) {
        $conn->rollBack();
    }
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    if ($weekId !== null) {
        $conn->executeStatement('DELETE FROM fw_schedule_weeks WHERE id = ?', [$weekId]);
    }
}
