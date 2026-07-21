<?php

declare(strict_types=1);

/**
 * Migrate fw_event_rules: conditions.notify_roles → action.recipients on notify/create_report.
 * Strips store_for_dashboard and legacy condition keys (strict_mode, notify_roles, project/task).
 *
 *   php scripts/run-migrate-event-rule-recipients.php
 *   php scripts/run-migrate-event-rule-recipients.php --dry-run
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use App\Database\Database;

$opts = getopt('', ['dry-run']);
$dryRun = isset($opts['dry-run']);
$connection = Database::getConnection();

$rows = $connection->fetchAllAssociative(
    'SELECT event_type, actions, conditions FROM fw_event_rules'
);

$updated = 0;
foreach ($rows as $row) {
    $eventType = (string) $row['event_type'];
    $actions = json_decode((string) ($row['actions'] ?? '[]'), true);
    $conditions = !empty($row['conditions'])
        ? json_decode((string) $row['conditions'], true)
        : [];

    if (!is_array($actions)) {
        $actions = [];
    }
    if (!is_array($conditions)) {
        $conditions = [];
    }

    $legacyRoles = [];
    $rawNotify = $conditions['notify_roles']['value'] ?? ($conditions['notify_roles'] ?? null);
    if (is_array($rawNotify)) {
        foreach ($rawNotify as $role) {
            if (is_string($role) && $role !== '') {
                $legacyRoles[] = $role;
            }
        }
    }

    $changed = false;
    foreach ($actions as $i => $action) {
        if (!is_array($action)) {
            continue;
        }
        if (array_key_exists('store_for_dashboard', $action)) {
            unset($actions[$i]['store_for_dashboard']);
            $changed = true;
        }
        $type = $action['type'] ?? null;
        if ($type === 'notify' || $type === 'create_report') {
            $recipients = $action['recipients'] ?? [];
            if (!is_array($recipients) || $recipients === []) {
                if ($legacyRoles !== []) {
                    $actions[$i]['recipients'] = array_values(array_unique($legacyRoles));
                    $changed = true;
                } elseif ($type === 'notify') {
                    $actions[$i]['recipients'] = ['admin', 'project_manager'];
                    $changed = true;
                } elseif ($type === 'create_report') {
                    $actions[$i]['recipients'] = ['admin', 'project_manager'];
                    $changed = true;
                }
            }
        }
    }

    $newConditions = [];
    if (isset($conditions['time_conditions']) && is_array($conditions['time_conditions'])) {
        $tc = $conditions['time_conditions'];
        $newConditions['time_conditions'] =
            isset($tc['value']) && is_array($tc['value']) ? $tc['value'] : $tc;
    }
    if (json_encode($newConditions) !== json_encode(array_intersect_key($conditions, ['time_conditions' => true]))) {
        // Also strip if leftover keys exist
        $changed = true;
    }
    if (isset($conditions['notify_roles']) || isset($conditions['strict_mode'])
        || isset($conditions['project_conditions']) || isset($conditions['task_conditions'])) {
        $changed = true;
    }

    if (!$changed) {
        continue;
    }

    $updated++;
    echo "  {$eventType}\n";

    if ($dryRun) {
        continue;
    }

    $connection->executeStatement(
        'UPDATE fw_event_rules SET actions = ?, conditions = ? WHERE event_type = ?',
        [
            json_encode(array_values($actions), JSON_UNESCAPED_UNICODE),
            $newConditions === [] ? null : json_encode($newConditions, JSON_UNESCAPED_UNICODE),
            $eventType,
        ]
    );
}

echo ($dryRun ? '[dry-run] ' : '') . "Updated {$updated} rule(s).\n";
