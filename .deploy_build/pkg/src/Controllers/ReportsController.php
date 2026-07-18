<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use App\Services\DailyOperationalReportService;
use Doctrine\DBAL\Connection;
use Flight;
use Monolog\Logger;
use Throwable;

/**
 * Read API for the report archive (immutable snapshots).
 * List / metadata (JSON) and rendered_html snapshot view (text/html).
 */
class ReportsController
{
    private Connection $connection;
    private DailyOperationalReportService $service;

    public function __construct(private readonly Logger $logger)
    {
        $this->connection = Database::getConnection();
        $this->service = new DailyOperationalReportService($logger);
    }

    /**
     * GET /api/v1/reports
     * Query: type, project_id, from, to, limit
     */
    public function list(): void
    {
        $request = Flight::request();
        $user = Flight::get('current_user');
        $allowed = $this->allowedProjectIds($user);

        $filters = $this->readFilters($this->queryToArray($request->query));
        $this->respondList($filters, $allowed);
    }

    /**
     * GET /api/v1/projects/@project_id/reports
     */
    public function listForProject(int $projectId): void
    {
        $request = Flight::request();
        $user = Flight::get('current_user');
        $allowed = $this->allowedProjectIds($user);

        if ($allowed !== null && !in_array($projectId, $allowed, true)) {
            $this->json(403, 'error', 'Forbidden', null);
            return;
        }

        $filters = $this->readFilters($this->queryToArray($request->query));
        $filters['project_id'] = $projectId;
        $this->respondList($filters, $allowed);
    }

    /**
     * GET /api/v1/reports/@id
     * Metadata + payload_json (for Vue rendering).
     */
    public function get(int $id): void
    {
        $user = Flight::get('current_user');
        $row = $this->service->getReportRow($id);
        if ($row === null) {
            $this->json(404, 'error', 'Report not found', null);
            return;
        }
        if (!$this->canAccessReport($user, $row)) {
            $this->json(403, 'error', 'Forbidden', null);
            return;
        }

        $payload = $this->decode($row['payload_json'] ?? null);
        $this->json(0, 'success', 'Report retrieved', [
            'id' => (int) $row['id'],
            'report_date' => $row['report_date'],
            'project_id' => (int) $row['project_id'],
            'report_type' => $row['report_type'] ?? 'daily',
            'scope' => $row['scope'] ?? 'project',
            'title' => $row['title'] ?? null,
            'status' => $row['status'],
            'generated_at' => $row['generated_at'],
            'sent_at' => $row['sent_at'],
            'payload' => $payload,
        ]);
    }

    /**
     * GET /api/v1/reports/@id/view
     * Immutable HTML snapshot for a browser tab / print.
     */
    public function view(int $id): void
    {
        $user = Flight::get('current_user');
        $row = $this->service->getReportRow($id);
        if ($row === null) {
            $this->htmlError(404, 'Report not found');
            return;
        }
        if (!$this->canAccessReport($user, $row)) {
            $this->htmlError(403, 'Forbidden');
            return;
        }

        $html = $this->service->getReportHtml($id) ?? '<p>Report is empty.</p>';

        Flight::response()
            ->status(200)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->write($html)
            ->send();
    }

    /**
     * Flight exposes query as Collection; normalize to a plain array.
     *
     * @return array<string, mixed>
     */
    private function queryToArray(mixed $query): array
    {
        if (is_array($query)) {
            return $query;
        }
        if (is_object($query) && method_exists($query, 'getData')) {
            $data = $query->getData();
            return is_array($data) ? $data : [];
        }
        if ($query instanceof \Traversable) {
            return iterator_to_array($query);
        }
        return [];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function readFilters(array $query): array
    {
        $filters = [];
        if (!empty($query['type'])) {
            $filters['report_type'] = (string) $query['type'];
        }
        if (!empty($query['scope']) && in_array($query['scope'], ['project', 'global'], true)) {
            $filters['scope'] = (string) $query['scope'];
        }
        if (!empty($query['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $query['from'])) {
            $filters['from'] = (string) $query['from'];
        }
        if (!empty($query['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $query['to'])) {
            $filters['to'] = (string) $query['to'];
        }
        if (!empty($query['limit'])) {
            $filters['limit'] = (int) $query['limit'];
        }
        return $filters;
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<int>|null $allowed
     */
    private function respondList(array $filters, ?array $allowed): void
    {
        try {
            $rows = $this->service->listReportsMeta($filters, $allowed);
        } catch (Throwable $e) {
            $this->logger->error('Failed to list reports', ['error' => $e->getMessage()]);
            $this->json(500, 'error', 'Failed to list reports', null);
            return;
        }

        $items = array_map(static function (array $r): array {
            return [
                'id' => (int) $r['id'],
                'report_date' => $r['report_date'],
                'project_id' => (int) $r['project_id'],
                'project_name' => $r['project_name'] ?? null,
                'report_type' => $r['report_type'] ?? 'daily',
                'scope' => $r['scope'] ?? 'project',
                'title' => $r['title'] ?? null,
                'status' => $r['status'],
                'generated_at' => $r['generated_at'],
                'sent_at' => $r['sent_at'],
            ];
        }, $rows);

        $this->json(0, 'success', 'Reports retrieved', [
            'items' => $items,
            'total' => count($items),
        ]);
    }

    /**
     * Allowed project ids for this user; null means unrestricted (admin).
     *
     * @param array<string, mixed>|null $user
     * @return list<int>|null
     */
    private function allowedProjectIds(?array $user): ?array
    {
        if ($this->isAdmin($user)) {
            return null;
        }
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return [];
        }

        $hasForeman = $this->projectForemanColumnPresent();
        $sql = 'SELECT id FROM fw_projects WHERE prj_manager = ?';
        $params = [$userId];
        if ($hasForeman) {
            $sql .= ' OR project_foreman_id = ?';
            $params[] = $userId;
        }

        try {
            $ids = $this->connection->fetchFirstColumn($sql, $params);
            return array_map(static fn ($v): int => (int) $v, $ids);
        } catch (Throwable $e) {
            $this->logger->error('Failed to resolve allowed projects', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * @param array<string, mixed>|null $user
     * @param array<string, mixed> $row
     */
    private function canAccessReport(?array $user, array $row): bool
    {
        $scope = (string) ($row['scope'] ?? '');
        $projectId = (int) ($row['project_id'] ?? 0);
        if ($scope === 'global' || $projectId === 0) {
            $role = $user['role_code'] ?? null;
            return $role === 'admin' || $role === 'project_manager';
        }
        return $this->canAccessProject($user, $projectId);
    }

    /**
     * @param array<string, mixed>|null $user
     */
    private function canAccessProject(?array $user, int $projectId): bool
    {
        $allowed = $this->allowedProjectIds($user);
        if ($allowed === null) {
            return true;
        }
        return in_array($projectId, $allowed, true);
    }

    /**
     * @param array<string, mixed>|null $user
     */
    private function isAdmin(?array $user): bool
    {
        return ($user['role_code'] ?? null) === 'admin';
    }

    private function projectForemanColumnPresent(): bool
    {
        try {
            return (bool) $this->connection->fetchOne(
                "SHOW COLUMNS FROM fw_projects LIKE 'project_foreman_id'"
            );
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string, mixed> */
    private function decode(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function json(int $errorCode, string $status, string $message, mixed $data, int $httpStatus = 200): void
    {
        $http = $status === 'success' ? 200 : ($errorCode >= 400 ? $errorCode : $httpStatus);
        Flight::json([
            'error_code' => $errorCode,
            'status' => $status,
            'message' => $message,
            'data' => $data,
        ], $http);
    }

    private function htmlError(int $code, string $message): void
    {
        Flight::response()
            ->status($code)
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->write('<!DOCTYPE html><html><body style="font:14px Arial,sans-serif;padding:24px;color:#334155;">'
                . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</body></html>')
            ->send();
    }
}
