<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use App\Services\TaskAuthorizationService;
use Doctrine\DBAL\Connection;
use Flight;
use Monolog\Logger;
use Throwable;

class TaskFieldPhotoController
{
    private const SLOT_BEFORE = 'before';
    private const SLOT_AFTER = 'after';
    private const MAX_FILE_SIZE_BYTES = 5242880; // 5MB
    private const MAX_PHOTOS_PER_SLOT = 15;

    public function __construct(
        private readonly Logger $logger
    ) {
    }

    public function index(int $projectId, int $taskId): void
    {
        $conn = Database::getConnection();
        $context = $this->resolveContext($conn, $projectId, $taskId);
        if ($context === null) {
            return;
        }
        [$project, $task] = $context;
        $actorId = (int) Flight::get('current_user')['id'];
        if (!$this->canViewPhotos($conn, $actorId, (int) $task['id'], (int) $project['id'])) {
            $this->error('Forbidden', 403);
            return;
        }

        $workDate = $this->normalizeWorkDate($_GET['work_date'] ?? null);
        if ($workDate === false) {
            $this->error('Invalid work_date. Use YYYY-MM-DD', 422);
            return;
        }
        if ($workDate === null) {
            $workDate = $this->resolveWorkDateForTask($conn, (int) $task['id'], (int) $project['id'], null);
        }

        $slotFilter = $this->normalizeSlot($_GET['slot'] ?? null);
        if (($_GET['slot'] ?? null) !== null && $slotFilter === null) {
            $this->error('Invalid slot. Allowed: before, after', 422);
            return;
        }

        $params = [$projectId, $taskId, $workDate];
        $sql = 'SELECT id, project_id, task_id, work_date, slot, file_name, original_name, mime_type, file_size, uploaded_by, uploaded_at
                FROM fw_task_field_photos
                WHERE project_id = ? AND task_id = ? AND work_date = ? AND deleted_at IS NULL';
        if ($slotFilter !== null) {
            $sql .= ' AND slot = ?';
            $params[] = $slotFilter;
        }
        $sql .= ' ORDER BY uploaded_at ASC, id ASC';

        $rows = $conn->executeQuery($sql, $params)->fetchAllAssociative();

        $out = [
            self::SLOT_BEFORE => [],
            self::SLOT_AFTER => [],
        ];
        foreach ($rows as $row) {
            $slot = (string) ($row['slot'] ?? '');
            if (!isset($out[$slot])) {
                continue;
            }
            $out[$slot][] = $this->formatPhotoRow($row);
        }

        Flight::json([
            'error_code' => 0,
            'status' => 'success',
            'message' => 'Task field photos retrieved',
            'data' => $out,
        ]);
    }

    public function upload(int $projectId, int $taskId): void
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

        $context = $this->resolveContext($conn, $projectId, $taskId);
        if ($context === null) {
            return;
        }
        [$project, $task] = $context;
        $actorId = (int) Flight::get('current_user')['id'];
        if (!$this->canUploadPhotos($conn, $actorId, (int) $task['id'], (int) $project['id'])) {
            $this->error('Forbidden', 403);
            return;
        }

        $slot = $this->normalizeSlot($_POST['slot'] ?? $_GET['slot'] ?? null);
        if ($slot === null) {
            $this->error('Invalid slot. Allowed: before, after', 422);
            return;
        }

        $workDate = $this->normalizeWorkDate($_POST['work_date'] ?? $_GET['work_date'] ?? null);
        if ($workDate === false) {
            $this->error('Invalid work_date. Use YYYY-MM-DD', 422);
            return;
        }
        if ($workDate === null) {
            $workDate = $this->resolveWorkDateForTask($conn, (int) $task['id'], (int) $project['id'], null);
        }

        $count = (int) $conn->executeQuery(
            'SELECT COUNT(*) FROM fw_task_field_photos
             WHERE project_id = ? AND task_id = ? AND work_date = ? AND slot = ? AND deleted_at IS NULL',
            [$projectId, $taskId, $workDate, $slot]
        )->fetchOne();
        if ($count >= self::MAX_PHOTOS_PER_SLOT) {
            $this->error('Maximum ' . self::MAX_PHOTOS_PER_SLOT . ' photos per section', 422);
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
            $this->error('File is too large. Max size is 5MB', 422);
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
        if (!$this->isAllowedImageMime($mime, $ext)) {
            $this->error('Only image files are allowed (JPG, PNG, WebP, GIF)', 422);
            return;
        }

        $safeOriginal = $this->sanitizeOriginalName($originalName);
        $storedFileName = $this->buildStoredFileName($safeOriginal);
        $relativePath = $this->buildRelativeStoragePath(
            (int) $project['id'],
            (int) $task['id'],
            $workDate,
            $slot,
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
            $conn->insert('fw_task_field_photos', [
                'project_id' => (int) $project['id'],
                'task_id' => (int) $task['id'],
                'work_date' => $workDate,
                'slot' => $slot,
                'file_name' => ltrim($relativePath, '/'),
                'original_name' => $safeOriginal,
                'mime_type' => $mime,
                'file_size' => $size,
                'uploaded_by' => $actorId,
                'uploaded_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
            ]);
            $id = (int) $conn->lastInsertId();
            $row = $conn->executeQuery(
                'SELECT id, project_id, task_id, work_date, slot, file_name, original_name, mime_type, file_size, uploaded_by, uploaded_at
                 FROM fw_task_field_photos
                 WHERE id = ?',
                [$id]
            )->fetchAssociative();
            if (!$row) {
                throw new \RuntimeException('Failed to load created photo');
            }
        } catch (Throwable $e) {
            @unlink($fullPath);
            $this->logger->error('Failed to persist task field photo', [
                'error' => $e->getMessage(),
                'project_id' => $projectId,
                'task_id' => $taskId,
                'slot' => $slot,
                'work_date' => $workDate,
            ]);
            $this->error('Failed to save photo', 500);
            return;
        }

        Flight::json([
            'error_code' => 0,
            'status' => 'success',
            'message' => 'Photo uploaded',
            'data' => $this->formatPhotoRow($row),
        ], 201);
    }

    public function download(int $projectId, int $taskId, int $photoId): void
    {
        $conn = Database::getConnection();
        $context = $this->resolveContext($conn, $projectId, $taskId);
        if ($context === null) {
            return;
        }
        [$project, $task] = $context;
        $actorId = (int) Flight::get('current_user')['id'];
        if (!$this->canViewPhotos($conn, $actorId, (int) $task['id'], (int) $project['id'])) {
            $this->error('Forbidden', 403);
            return;
        }

        $photo = $conn->executeQuery(
            'SELECT id, project_id, task_id, work_date, slot, file_name, original_name, mime_type, file_size
             FROM fw_task_field_photos
             WHERE id = ? AND deleted_at IS NULL',
            [$photoId]
        )->fetchAssociative();
        if (!$photo) {
            $this->error('Photo not found', 404);
            return;
        }
        if ((int) $photo['project_id'] !== $projectId || (int) $photo['task_id'] !== $taskId) {
            $this->error('photoId does not belong to this project/task', 422);
            return;
        }

        $filePath = __DIR__ . '/../../public/' . ltrim((string) $photo['file_name'], '/');
        if (!is_file($filePath)) {
            $this->error('File not found on disk', 404);
            return;
        }

        $action = $_GET['action'] ?? 'download';
        $isPreview = $action === 'preview';
        $downloadName = (string) $photo['original_name'];

        header('Content-Type: ' . (string) $photo['mime_type']);
        header('Content-Length: ' . (string) filesize($filePath));
        if ($isPreview) {
            header('Content-Disposition: inline; filename="' . $this->escapeHeaderFilename($downloadName) . '"');
            header('Cache-Control: public, max-age=3600');
        } else {
            header('Content-Disposition: attachment; filename="' . $this->escapeHeaderFilename($downloadName) . '"');
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: no-cache');
        }
        if (ob_get_level()) {
            ob_end_clean();
        }
        readfile($filePath);
    }

    public function delete(int $projectId, int $taskId, int $photoId): void
    {
        $conn = Database::getConnection();
        $context = $this->resolveContext($conn, $projectId, $taskId);
        if ($context === null) {
            return;
        }
        [$project, $task] = $context;
        $actorId = (int) Flight::get('current_user')['id'];

        $photo = $conn->executeQuery(
            'SELECT id, project_id, task_id, work_date, slot, file_name, original_name, mime_type, file_size, uploaded_by, uploaded_at
             FROM fw_task_field_photos
             WHERE id = ? AND deleted_at IS NULL',
            [$photoId]
        )->fetchAssociative();
        if (!$photo) {
            $this->error('Photo not found', 404);
            return;
        }
        if ((int) $photo['project_id'] !== $projectId || (int) $photo['task_id'] !== $taskId) {
            $this->error('photoId does not belong to this project/task', 422);
            return;
        }
        if (!$this->canDeletePhoto($conn, $actorId, (int) $task['id'], (int) $project['id'], (int) $photo['uploaded_by'])) {
            $this->error('Forbidden', 403);
            return;
        }

        $filePath = __DIR__ . '/../../public/' . ltrim((string) $photo['file_name'], '/');
        try {
            if (is_file($filePath) && !@unlink($filePath)) {
                $this->error('Failed to delete file from storage', 500);
                return;
            }
            $affected = $conn->executeStatement(
                'UPDATE fw_task_field_photos
                 SET deleted_at = NOW(6)
                 WHERE id = ? AND project_id = ? AND task_id = ? AND deleted_at IS NULL',
                [$photoId, $projectId, $taskId]
            );
            if ($affected < 1) {
                $this->error('Photo not found', 404);
                return;
            }
        } catch (Throwable $e) {
            $this->logger->error('Failed to delete task field photo', [
                'error' => $e->getMessage(),
                'photo_id' => $photoId,
                'project_id' => $projectId,
                'task_id' => $taskId,
            ]);
            $this->error('Failed to delete photo', 500);
            return;
        }

        Flight::json(['message' => 'Photo deleted successfully.']);
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>}|null */
    private function resolveContext(Connection $conn, int $projectId, int $taskId): ?array
    {
        $project = $conn->executeQuery(
            'SELECT id, prj_manager FROM fw_projects WHERE id = ?',
            [$projectId]
        )->fetchAssociative();
        if (!$project) {
            $this->error('Project not found', 404);
            return null;
        }

        $task = $conn->executeQuery(
            'SELECT id, project_id, name, field_work_started_at, field_submitted_at
             FROM fw_prj_tasks
             WHERE id = ? AND project_id = ?',
            [$taskId, $projectId]
        )->fetchAssociative();
        if (!$task) {
            $this->error('Task not found', 404);
            return null;
        }

        return [$project, $task];
    }

    private function canViewPhotos(Connection $conn, int $userId, int $taskId, int $projectId): bool
    {
        $auth = new TaskAuthorizationService();
        if ($auth->isProjectTaskManager($conn, $userId, $projectId)) {
            return true;
        }
        if ($auth->isAssignedToTask($conn, $taskId, $userId)) {
            return true;
        }

        return false;
    }

    private function canUploadPhotos(Connection $conn, int $userId, int $taskId, int $projectId): bool
    {
        $auth = new TaskAuthorizationService();
        if ($auth->isProjectTaskManager($conn, $userId, $projectId)) {
            return true;
        }

        return $auth->canSubmitFieldWork($conn, $taskId, $userId);
    }

    private function canDeletePhoto(Connection $conn, int $userId, int $taskId, int $projectId, int $uploadedBy): bool
    {
        $auth = new TaskAuthorizationService();
        if ($auth->isProjectTaskManager($conn, $userId, $projectId)) {
            return true;
        }
        if ($auth->canSubmitFieldWork($conn, $taskId, $userId)) {
            return true;
        }

        return $uploadedBy === $userId;
    }

    private function resolveWorkDateForTask(Connection $conn, int $taskId, int $projectId, ?string $raw): string
    {
        $normalized = $this->normalizeWorkDate($raw);
        if (is_string($normalized)) {
            return $normalized;
        }

        $task = $conn->executeQuery(
            'SELECT field_work_started_at FROM fw_prj_tasks WHERE id = ? AND project_id = ?',
            [$taskId, $projectId]
        )->fetchAssociative();
        if ($task && isset($task['field_work_started_at']) && $task['field_work_started_at'] !== null) {
            $value = $task['field_work_started_at'];
            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d');
            }
            $s = (string) $value;
            if (strlen($s) >= 10 && preg_match('/^\d{4}-\d{2}-\d{2}/', $s) === 1) {
                return substr($s, 0, 10);
            }
        }

        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
    }

    /** @return null|string|false */
    private function normalizeWorkDate(mixed $raw): null|string|false
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw)) {
            return false;
        }
        $value = trim($raw);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        return $value;
    }

    private function normalizeSlot(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $slot = strtolower(trim($raw));
        if ($slot === self::SLOT_BEFORE || $slot === self::SLOT_AFTER) {
            return $slot;
        }

        return null;
    }

    /** @return array{ok:bool,file?:array<string,mixed>,message?:string,http?:int} */
    private function resolveUploadedFile(): array
    {
        foreach (['file', 'attachment', 'photo'] as $key) {
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
            default => 500,
        };
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

    private function isAllowedImageMime(string $mime, string $ext): bool
    {
        if (str_starts_with(strtolower($mime), 'image/')) {
            return true;
        }

        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'heic', 'heif'], true);
    }

    private function sanitizeOriginalName(string $originalName): string
    {
        $trimmed = trim($originalName);
        if ($trimmed === '') {
            return 'photo.jpg';
        }

        return str_replace(["\r", "\n"], '', basename($trimmed));
    }

    private function buildStoredFileName(string $safeOriginalName): string
    {
        return time() . '_' . bin2hex(random_bytes(4)) . '_' . $safeOriginalName;
    }

    private function buildRelativeStoragePath(
        int $projectId,
        int $taskId,
        string $workDate,
        string $slot,
        string $storedFileName
    ): string {
        return '/uploads/task-field-photos'
            . '/p-' . $projectId
            . '/t-' . $taskId
            . '/d-' . $workDate
            . '/' . $slot
            . '/' . $storedFileName;
    }

    private function escapeHeaderFilename(string $name): string
    {
        return str_replace('"', '', $name);
    }

    /** @param array<string, mixed> $row */
    private function formatPhotoRow(array $row): array
    {
        $workDate = $row['work_date'] ?? '';
        if ($workDate instanceof \DateTimeInterface) {
            $workDate = $workDate->format('Y-m-d');
        } else {
            $workDate = (string) $workDate;
        }

        return [
            'id' => (int) $row['id'],
            'project_id' => (int) $row['project_id'],
            'task_id' => (int) $row['task_id'],
            'work_date' => $workDate,
            'slot' => (string) $row['slot'],
            'file_name' => (string) $row['file_name'],
            'original_name' => (string) $row['original_name'],
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
            $dt = new \DateTimeImmutable($db, new \DateTimeZone('UTC'));

            return $dt->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable) {
            return $db;
        }
    }

    private function error(string $message, int $http): void
    {
        Flight::json([
            'error_code' => $http,
            'status' => 'error',
            'message' => $message,
            'data' => null,
        ], $http);
    }
}
