<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use Doctrine\DBAL\Connection;
use Flight;
use Monolog\Logger;
use Throwable;

class ScheduleEntryDocumentController
{
    private const BUCKET_SETUP = 'setup';
    private const BUCKET_COMPLETED = 'completed';
    private const MAX_FILE_SIZE_BYTES = 20971520; // 20MB
    private const DISPLAY_NAME_MAX_LEN = 160;

    public function __construct(
        private readonly Logger $logger
    ) {
    }

    public function index(int $projectId, int $scheduleEntryId): void
    {
        $conn = Database::getConnection();
        $context = $this->resolveContext($conn, $projectId, $scheduleEntryId);
        if ($context === null) {
            return;
        }

        [$project, $entry] = $context;
        $actorId = (int) Flight::get('current_user')['id'];
        if (!$this->canViewEntryDocuments($conn, $actorId, $entry, (int) $project['id'])) {
            $this->error('Forbidden', 403);
            return;
        }

        $rows = $conn->executeQuery(
            'SELECT id, project_id, schedule_entry_id, bucket, file_name, original_name, display_name, mime_type, file_size, uploaded_by, uploaded_at
             FROM fw_schedule_slot_documents
             WHERE project_id = ? AND schedule_entry_id = ?
               AND deleted_at IS NULL
             ORDER BY uploaded_at DESC, id DESC',
            [$projectId, $scheduleEntryId]
        )->fetchAllAssociative();

        $out = [
            self::BUCKET_SETUP => [],
            self::BUCKET_COMPLETED => [],
        ];
        foreach ($rows as $row) {
            $bucket = (string) ($row['bucket'] ?? '');
            if (!isset($out[$bucket])) {
                continue;
            }
            $out[$bucket][] = $this->formatDocumentRow($row);
        }

        Flight::json([
            'error_code' => 0,
            'status' => 'success',
            'message' => 'Schedule entry documents retrieved',
            'data' => $out,
        ]);
    }

    public function upload(int $projectId, int $scheduleEntryId): void
    {
        $conn = Database::getConnection();

        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($contentType, 'multipart/form-data')) {
            $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
            if ($contentLength > 0 && $_POST === [] && $_FILES === []) {
                $this->error(
                    'Upload failed: empty form data. The request may exceed server limits (post_max_size / upload_max_filesize).',
                    413
                );
                return;
            }
        }

        $context = $this->resolveContext($conn, $projectId, $scheduleEntryId);
        if ($context === null) {
            return;
        }
        [$project, $entry] = $context;
        $actorId = (int) Flight::get('current_user')['id'];

        $bucketRaw = $_POST['bucket'] ?? $_GET['bucket'] ?? null;
        $bucket = $this->normalizeBucket($bucketRaw);
        if ($bucket === null) {
            $this->error('Invalid bucket. Allowed: setup, completed', 422);
            return;
        }

        if (!$this->canUploadToBucket($conn, $actorId, $entry, (int) $project['id'], $bucket)) {
            $this->error('Forbidden', 403);
            return;
        }

        $resolved = $this->resolveUploadedFile();
        if (!$resolved['ok']) {
            $this->error($resolved['message'], $resolved['http']);
            return;
        }
        $file = $resolved['file'];

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            $this->error('File is required', 422);
            return;
        }
        if ($size > self::MAX_FILE_SIZE_BYTES) {
            $this->error('File is too large. Max size is 20MB', 422);
            return;
        }

        $displayName = $this->normalizeDisplayName($_POST['display_name'] ?? null);
        if ($displayName === false) {
            $this->error('Invalid display_name length. Max length is 160', 422);
            return;
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        $originalName = (string) ($file['name'] ?? '');
        if ($tmpPath === '' || $originalName === '') {
            $this->error('File is required', 422);
            return;
        }

        $mime = $this->detectMimeType($tmpPath, (string) ($file['type'] ?? ''));
        $ext = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if (!$this->isAllowedMime($mime, $ext)) {
            $this->error(
                'Only images, PDF, and common office documents (e.g. Word, Excel, PowerPoint, CSV, TXT) are allowed',
                422
            );
            return;
        }

        $safeOriginal = $this->sanitizeOriginalName($originalName);
        $storedFileName = $this->buildStoredFileName($safeOriginal);
        $relativePath = $this->buildRelativeStoragePath(
            (int) $project['id'],
            (int) $entry['task_id'],
            (string) $entry['work_date'],
            $bucket,
            $storedFileName
        );
        $fullPath = __DIR__ . '/../../public' . $relativePath;
        $dirPath = dirname($fullPath);
        if (!is_dir($dirPath) && !mkdir($dirPath, 0755, true) && !is_dir($dirPath)) {
            $this->error('Storage error', 500);
            return;
        }
        if (!move_uploaded_file($tmpPath, $fullPath)) {
            $this->error('Storage error', 500);
            return;
        }

        try {
            $conn->insert('fw_schedule_slot_documents', [
                'project_id' => (int) $project['id'],
                'schedule_entry_id' => (int) $entry['id'],
                'bucket' => $bucket,
                'file_name' => ltrim($relativePath, '/'),
                'original_name' => $safeOriginal,
                'display_name' => $displayName,
                'mime_type' => $mime,
                'file_size' => $size,
                'uploaded_by' => $actorId,
                'uploaded_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
            ]);
            $id = (int) $conn->lastInsertId();
            $row = $conn->executeQuery(
                'SELECT id, project_id, schedule_entry_id, bucket, file_name, original_name, display_name, mime_type, file_size, uploaded_by, uploaded_at
                 FROM fw_schedule_slot_documents
                 WHERE id = ?',
                [$id]
            )->fetchAssociative();
            if (!$row) {
                throw new \RuntimeException('Failed to load created document');
            }
        } catch (Throwable $e) {
            @unlink($fullPath);
            $this->logger->error('Failed to persist schedule entry document', [
                'error' => $e->getMessage(),
                'project_id' => $projectId,
                'schedule_entry_id' => $scheduleEntryId,
                'bucket' => $bucket,
            ]);
            $this->error('Failed to save document', 500);
            return;
        }

        Flight::json([
            'error_code' => 0,
            'status' => 'success',
            'message' => 'Document uploaded',
            'data' => $this->formatDocumentRow($row),
        ], 201);
    }

    public function download(int $projectId, int $scheduleEntryId, int $documentId): void
    {
        $conn = Database::getConnection();
        $context = $this->resolveContext($conn, $projectId, $scheduleEntryId);
        if ($context === null) {
            return;
        }
        [$project, $entry] = $context;
        $actorId = (int) Flight::get('current_user')['id'];
        if (!$this->canViewEntryDocuments($conn, $actorId, $entry, (int) $project['id'])) {
            $this->error('Forbidden', 403);
            return;
        }

        $doc = $conn->executeQuery(
            'SELECT id, project_id, schedule_entry_id, bucket, file_name, original_name, display_name, mime_type, file_size
             FROM fw_schedule_slot_documents
             WHERE id = ?
               AND deleted_at IS NULL',
            [$documentId]
        )->fetchAssociative();
        if (!$doc) {
            $this->error('Document not found', 404);
            return;
        }
        if ((int) $doc['project_id'] !== $projectId || (int) $doc['schedule_entry_id'] !== $scheduleEntryId) {
            $this->error('documentId does not belong to this project/schedule entry', 422);
            return;
        }

        $filePath = __DIR__ . '/../../public/' . ltrim((string) $doc['file_name'], '/');
        if (!is_file($filePath)) {
            $this->error('File not found on disk', 404);
            return;
        }

        header('Content-Type: ' . (string) $doc['mime_type']);
        header('Content-Length: ' . (string) filesize($filePath));
        header('Content-Disposition: attachment; filename="' . $this->escapeHeaderFilename((string) $doc['original_name']) . '"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        if (ob_get_level()) {
            ob_end_clean();
        }
        readfile($filePath);
    }

    public function delete(int $projectId, int $scheduleEntryId, int $documentId): void
    {
        $conn = Database::getConnection();
        $context = $this->resolveContext($conn, $projectId, $scheduleEntryId);
        if ($context === null) {
            return;
        }
        [$project, $entry] = $context;
        $actorId = (int) Flight::get('current_user')['id'];
        if (!$this->canViewEntryDocuments($conn, $actorId, $entry, (int) $project['id'])) {
            $this->error('Forbidden', 403);
            return;
        }

        $doc = $conn->executeQuery(
            'SELECT id, project_id, schedule_entry_id, bucket, file_name, original_name, display_name, mime_type, file_size, uploaded_by, uploaded_at
             FROM fw_schedule_slot_documents
             WHERE id = ?
               AND deleted_at IS NULL',
            [$documentId]
        )->fetchAssociative();
        if (!$doc) {
            $this->error('Document not found', 404);
            return;
        }
        if ((int) $doc['project_id'] !== $projectId || (int) $doc['schedule_entry_id'] !== $scheduleEntryId) {
            $this->error('documentId does not belong to this project/schedule entry', 422);
            return;
        }

        $bucket = (string) $doc['bucket'];
        if (!$this->canDeleteFromBucket($conn, $actorId, $entry, (int) $project['id'], $bucket, (int) $doc['uploaded_by'])) {
            $this->error('Forbidden', 403);
            return;
        }

        $filePath = __DIR__ . '/../../public/' . ltrim((string) $doc['file_name'], '/');
        try {
            if (is_file($filePath) && !@unlink($filePath)) {
                $this->error('Failed to delete file from storage', 500);
                return;
            }
            $affected = $conn->executeStatement(
                'UPDATE fw_schedule_slot_documents
                 SET deleted_at = NOW(6)
                 WHERE id = ?
                   AND project_id = ?
                   AND schedule_entry_id = ?
                   AND deleted_at IS NULL',
                [$documentId, $projectId, $scheduleEntryId]
            );
            if ($affected < 1) {
                $this->error('Document not found', 404);
                return;
            }
        } catch (Throwable $e) {
            $this->logger->error('Failed to delete schedule entry document', [
                'error' => $e->getMessage(),
                'document_id' => $documentId,
                'project_id' => $projectId,
                'schedule_entry_id' => $scheduleEntryId,
            ]);
            $this->error('Failed to delete document', 500);
            return;
        }

        Flight::json(['message' => 'Document deleted successfully.']);
    }

    public function updateDisplayName(int $projectId, int $scheduleEntryId, int $documentId): void
    {
        $conn = Database::getConnection();
        $context = $this->resolveContext($conn, $projectId, $scheduleEntryId);
        if ($context === null) {
            return;
        }
        [$project, $entry] = $context;
        $actorId = (int) Flight::get('current_user')['id'];

        $doc = $conn->executeQuery(
            'SELECT id, project_id, schedule_entry_id, bucket, file_name, original_name, display_name, mime_type, file_size, uploaded_by, uploaded_at
             FROM fw_schedule_slot_documents
             WHERE id = ?
               AND deleted_at IS NULL',
            [$documentId]
        )->fetchAssociative();
        if (!$doc) {
            $this->error('Document not found', 404);
            return;
        }
        if ((int) $doc['project_id'] !== $projectId || (int) $doc['schedule_entry_id'] !== $scheduleEntryId) {
            $this->error('documentId does not belong to this project/schedule entry', 422);
            return;
        }
        if (!$this->canUpdateDisplayName($conn, $actorId, $entry, (int) $project['id'], (string) $doc['bucket'], (int) $doc['uploaded_by'])) {
            $this->error('Forbidden', 403);
            return;
        }

        $payload = json_decode(Flight::request()->getBody(), true);
        if (!is_array($payload) || !array_key_exists('display_name', $payload)) {
            $this->error('display_name is required', 422);
            return;
        }
        $displayName = $this->normalizeDisplayName($payload['display_name']);
        if ($displayName === false) {
            $this->error('Invalid display_name length. Max length is 160', 422);
            return;
        }

        try {
            $conn->executeStatement(
                'UPDATE fw_schedule_slot_documents
                 SET display_name = ?
                 WHERE id = ? AND project_id = ? AND schedule_entry_id = ? AND deleted_at IS NULL',
                [$displayName, $documentId, $projectId, $scheduleEntryId]
            );
            $updated = $conn->executeQuery(
                'SELECT id, project_id, schedule_entry_id, bucket, file_name, original_name, display_name, mime_type, file_size, uploaded_by, uploaded_at
                 FROM fw_schedule_slot_documents
                 WHERE id = ? AND deleted_at IS NULL',
                [$documentId]
            )->fetchAssociative();
            if (!$updated) {
                $this->error('Document not found', 404);
                return;
            }
        } catch (Throwable $e) {
            $this->logger->error('Failed to update display_name for schedule entry document', [
                'error' => $e->getMessage(),
                'document_id' => $documentId,
                'project_id' => $projectId,
                'schedule_entry_id' => $scheduleEntryId,
            ]);
            $this->error('Failed to update display_name', 500);
            return;
        }

        Flight::json([
            'message' => 'Document display name updated successfully.',
            'data' => $this->formatDocumentRow($updated),
        ]);
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>}|null */
    private function resolveContext(Connection $conn, int $projectId, int $scheduleEntryId): ?array
    {
        $project = $conn->executeQuery(
            'SELECT id, prj_manager FROM fw_projects WHERE id = ?',
            [$projectId]
        )->fetchAssociative();
        if (!$project) {
            $this->error('Project not found', 404);
            return null;
        }

        $entry = $conn->executeQuery(
            'SELECT id, project_id, user_id, task_id, work_date, day_part
             FROM fw_worker_task_schedules
             WHERE id = ?',
            [$scheduleEntryId]
        )->fetchAssociative();
        if (!$entry) {
            $this->error('Schedule entry not found', 404);
            return null;
        }
        if ((int) $entry['project_id'] !== $projectId) {
            $this->error('scheduleEntryId does not belong to projectId', 422);
            return null;
        }

        return [$project, $entry];
    }

    private function normalizeBucket(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $value = strtolower(trim($raw));
        if ($value === self::BUCKET_SETUP || $value === self::BUCKET_COMPLETED) {
            return $value;
        }

        return null;
    }

    /**
     * @return array{ok: true, file: array<string, mixed>}|array{ok: false, message: string, http: int}
     */
    private function resolveUploadedFile(): array
    {
        foreach (['file', 'attachment', 'document'] as $key) {
            if (!isset($_FILES[$key]) || !is_array($_FILES[$key])) {
                continue;
            }
            $file = $_FILES[$key];
            $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($uploadError === UPLOAD_ERR_OK) {
                return ['ok' => true, 'file' => $file];
            }
            if ($uploadError !== UPLOAD_ERR_NO_FILE) {
                return [
                    'ok' => false,
                    'message' => $this->mapPhpUploadError($uploadError),
                    'http' => $this->httpStatusForUploadError($uploadError),
                ];
            }
        }

        return ['ok' => false, 'message' => 'File is required', 'http' => 422];
    }

    private function mapPhpUploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize (PHP server limit)',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE from the form',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded; retry the upload',
            UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary folder for uploads',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk on the server',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload',
            default => 'File upload failed',
        };
    }

    private function httpStatusForUploadError(int $code): int
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 413,
            UPLOAD_ERR_PARTIAL => 422,
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 500,
            default => 422,
        };
    }

    private function canUploadToBucket(Connection $conn, int $actorId, array $entry, int $projectId, string $bucket): bool
    {
        if ($bucket === self::BUCKET_SETUP) {
            return $this->canManageSchedule($conn, $actorId, $projectId);
        }

        if ((int) $entry['user_id'] === $actorId) {
            return true;
        }

        return $this->canViewProjectSchedule($conn, $actorId, $projectId);
    }

    private function canDeleteFromBucket(
        Connection $conn,
        int $actorId,
        array $entry,
        int $projectId,
        string $bucket,
        int $uploadedBy
    ): bool {
        if ($this->canManageSchedule($conn, $actorId, $projectId)) {
            return true;
        }
        if ($bucket === self::BUCKET_COMPLETED && ($actorId === (int) $entry['user_id'] || $actorId === $uploadedBy)) {
            return true;
        }

        return false;
    }

    private function canUpdateDisplayName(
        Connection $conn,
        int $actorId,
        array $entry,
        int $projectId,
        string $bucket,
        int $uploadedBy
    ): bool {
        return $this->canDeleteFromBucket($conn, $actorId, $entry, $projectId, $bucket, $uploadedBy);
    }

    private function canViewEntryDocuments(Connection $conn, int $actorId, array $entry, int $projectId): bool
    {
        if ((int) $entry['user_id'] === $actorId) {
            return true;
        }
        if ($this->isTaskLeadSupervisorOrManagerOnTask($conn, $actorId, (int) $entry['task_id'], $projectId)) {
            return true;
        }

        return $this->canViewProjectSchedule($conn, $actorId, $projectId);
    }

    private function isTaskLeadSupervisorOrManagerOnTask(Connection $conn, int $userId, int $taskId, int $projectId): bool
    {
        $rows = $conn->executeQuery(
            'SELECT role_in_project FROM fw_prj_team_members
             WHERE project_id = ? AND task_id = ? AND user_id = ?',
            [$projectId, $taskId, $userId]
        )->fetchAllAssociative();
        foreach ($rows as $ro) {
            $role = $ro['role_in_project'] ?? null;
            if (!is_string($role) || $role === '') {
                continue;
            }
            $r = strtolower($role);
            if (str_contains($r, 'lead')
                || str_contains($r, 'supervisor')
                || str_contains($r, 'manager')) {
                return true;
            }
        }

        return false;
    }

    private function canManageSchedule(Connection $conn, int $userId, int $projectId): bool
    {
        $role = $this->getRoleCode($conn, $userId);
        if (in_array($role, ['admin', 'project_manager'], true)) {
            return true;
        }
        $row = $conn->executeQuery(
            'SELECT prj_manager FROM fw_projects WHERE id = ?',
            [$projectId]
        )->fetchAssociative();
        if ($row && isset($row['prj_manager']) && $row['prj_manager'] !== null && (int) $row['prj_manager'] === $userId) {
            return true;
        }

        return false;
    }

    private function canViewProjectSchedule(Connection $conn, int $userId, int $projectId): bool
    {
        if ($this->canManageSchedule($conn, $userId, $projectId)) {
            return true;
        }
        $one = $conn->executeQuery(
            'SELECT 1 FROM fw_prj_team_members WHERE project_id = ? AND user_id = ? LIMIT 1',
            [$projectId, $userId]
        )->fetchOne();

        return (bool) $one;
    }

    private function getRoleCode(Connection $conn, int $userId): ?string
    {
        $r = $conn->executeQuery(
            'SELECT role_code FROM fw_v_users WHERE id = ? AND archived_at IS NULL',
            [$userId]
        )->fetchAssociative();

        return $r['role_code'] ?? null;
    }

    private function detectMimeType(string $tmpPath, string $fallback): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $tmpPath);
                finfo_close($finfo);
                if (is_string($detected) && $detected !== '') {
                    return $detected;
                }
            }
        }

        return $fallback !== '' ? $fallback : 'application/octet-stream';
    }

    private function isAllowedMime(string $mime, string $ext): bool
    {
        if (str_starts_with($mime, 'image/')) {
            return true;
        }

        $mimeLower = strtolower($mime);
        $officeAndDocs = [
            'application/pdf',
            'application/msword',
            'application/vnd.ms-excel',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/csv',
            'application/csv',
            'text/plain',
        ];
        if (in_array($mimeLower, $officeAndDocs, true)) {
            if ($mimeLower === 'text/plain' && $ext !== 'txt') {
                return false;
            }

            return true;
        }

        $extOk = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt'];

        return in_array($ext, $extOk, true);
    }

    private function sanitizeOriginalName(string $originalName): string
    {
        $trimmed = trim($originalName);
        if ($trimmed === '') {
            return 'file';
        }

        return str_replace(["\r", "\n"], '', basename($trimmed));
    }

    /** @return false|null|string */
    private function normalizeDisplayName(mixed $raw): false|null|string
    {
        if ($raw === null) {
            return null;
        }
        if (!is_string($raw)) {
            return false;
        }
        $value = trim($raw);
        if ($value === '') {
            return null;
        }
        if ($this->stringCharLength($value) > self::DISPLAY_NAME_MAX_LEN) {
            return false;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return false;
        }

        return $value;
    }

    private function buildStoredFileName(string $safeOriginalName): string
    {
        return time() . '_' . bin2hex(random_bytes(4)) . '_' . $safeOriginalName;
    }

    private function buildRelativeStoragePath(
        int $projectId,
        int $taskId,
        string $workDate,
        string $bucket,
        string $storedFileName
    ): string {
        return '/uploads/schedule-slot-documents'
            . '/p-' . $projectId
            . '/t-' . $taskId
            . '/s-' . $workDate
            . '/' . $bucket
            . '/' . $storedFileName;
    }

    private function escapeHeaderFilename(string $name): string
    {
        return str_replace('"', '', $name);
    }

    /** @param array<string, mixed> $row */
    private function formatDocumentRow(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'schedule_entry_id' => (int) $row['schedule_entry_id'],
            'project_id' => (int) $row['project_id'],
            'bucket' => (string) $row['bucket'],
            'file_name' => (string) $row['file_name'],
            'original_name' => (string) $row['original_name'],
            'display_name' => isset($row['display_name']) && $row['display_name'] !== ''
                ? (string) $row['display_name']
                : null,
            'mime_type' => (string) $row['mime_type'],
            'file_size' => (int) $row['file_size'],
            'uploaded_by' => (int) $row['uploaded_by'],
            'uploaded_at' => $this->formatInstantUtc($row['uploaded_at'] ?? null),
        ];
    }

    private function formatInstantUtc(mixed $db): ?string
    {
        if ($db === null || $db === '' || !is_string($db)) {
            return null;
        }
        try {
            $dt = (new \DateTimeImmutable($db))->setTimezone(new \DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
        $micro = $dt->format('u');
        $ms = strlen($micro) >= 3 ? substr($micro, 0, 3) : str_pad($micro, 3, '0');

        return $dt->format('Y-m-d\TH:i:s') . '.' . $ms . 'Z';
    }

    private function error(string $message, int $http): void
    {
        Flight::json(['message' => $message], $http);
    }

    private function stringCharLength(string $s): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($s);
        }

        return strlen($s);
    }
}
