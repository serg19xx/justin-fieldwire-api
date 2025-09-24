<?php

namespace App\Controllers;

use App\Database\Database;
use Doctrine\DBAL\Exception;
use Flight;
use Monolog\Logger;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Plans",
 *     description="Folders and files management for plans"
 * )
 */
class PlanController
{
    private Logger $logger;
    private Database $database;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        try {
            $this->database = new Database();
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize PlanController', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Получить дерево папок (левое меню)
     * GET /api/v1/plan/folders/tree?project_id=10
     *
     * @OA\Get(
     *     path="/api/v1/plan/folders/tree",
     *     summary="Get folders tree",
     *     tags={"Plans"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(name="project_id", in="query", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Tree returned",
     *         @OA\JsonContent(type="array", @OA\Items(type="object"))
     *     )
     * )
     */
    public function getFolderTree(): void
    {
        if (!$this->checkAuth()) {
            return;
        }

        $projectId = $_GET['project_id'] ?? null;
        if (!$projectId) {
            Flight::json([
                'error_code' => 400,
                'status' => 'error',
                'message' => 'project_id parameter is required',
                'data' => null
            ], 400);
            return;
        }

        try {
            $connection = $this->database->getConnection();
            $rows = $connection->executeQuery(
                'SELECT id, name, parent_id, created_at, updated_at FROM fw_plan_folders WHERE project_id = ? ORDER BY id ASC',
                [$projectId]
            )->fetchAllAssociative();

            // Строим дерево из плоского списка
            $idToNode = [];
            foreach ($rows as $row) {
                $idToNode[(int)$row['id']] = [
                    'id' => (int)$row['id'],
                    'name' => $row['name'],
                    'parent_id' => $row['parent_id'] !== null ? (int)$row['parent_id'] : null,
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                    'children' => []
                ];
            }

            $roots = [];
            foreach ($idToNode as $id => &$node) {
                $parentId = $node['parent_id'];
                if ($parentId !== null && isset($idToNode[$parentId])) {
                    $idToNode[$parentId]['children'][] = &$node;
                } else {
                    $roots[] = &$node;
                }
            }
            unset($node);

            Flight::json($roots);
        } catch (Exception $e) {
            $this->logger->error('Failed to get folder tree', [
                'error' => $e->getMessage()
            ]);
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to get folder tree',
                'data' => null
            ], 500);
        }
    }

    /**
     * Получить содержимое папки (правая панель)
     * GET /api/v1/plan/folders/{folderId}/content
     *
     * @OA\Get(
     *     path="/api/v1/plan/folders/{folderId}/content",
     *     summary="Get folder content",
     *     tags={"Plans"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(name="folderId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Content returned",
     *         @OA\JsonContent(type="object")
     *     )
     * )
     */
    public function getFolderContent(int $folderId): void
    {
        if (!$this->checkAuth()) {
            return;
        }

        try {
            $connection = $this->database->getConnection();

            // Папка
            $folder = $connection->executeQuery(
                'SELECT id, name, parent_id, created_at, updated_at FROM fw_plan_folders WHERE id = ?',
                [$folderId]
            )->fetchAssociative();

            if (!$folder) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Folder not found',
                    'data' => null
                ], 404);
                return;
            }

            // Подпапки
            $subfolders = $connection->executeQuery(
                'SELECT id, name, parent_id, created_at, updated_at FROM fw_plan_folders WHERE parent_id = ? ORDER BY id ASC',
                [$folderId]
            )->fetchAllAssociative();

            // Файлы
            $files = $connection->executeQuery(
                'SELECT id, file_name, original_name, file_path, folder_id, file_size, mime_type, category, description, version, uploaded_by, uploaded_at, updated_at FROM fw_plan_files WHERE folder_id = ? ORDER BY id ASC',
                [$folderId]
            )->fetchAllAssociative();

            // Приводим типы
            $folderFormatted = [
                'id' => (int)$folder['id'],
                'name' => $folder['name'],
                'parent_id' => $folder['parent_id'] !== null ? (int)$folder['parent_id'] : null,
                'created_at' => $folder['created_at'],
                'updated_at' => $folder['updated_at']
            ];

            $subfoldersFormatted = array_map(function ($f) {
                return [
                    'id' => (int)$f['id'],
                    'name' => $f['name'],
                    'parent_id' => $f['parent_id'] !== null ? (int)$f['parent_id'] : null,
                    'created_at' => $f['created_at'],
                    'updated_at' => $f['updated_at']
                ];
            }, $subfolders);

            $filesFormatted = array_map(function ($fl) {
                return [
                    'id' => (int)$fl['id'],
                    'file_name' => $fl['file_name'],
                    'original_name' => $fl['original_name'],
                    'file_path' => $fl['file_path'],
                    'folder_id' => (int)$fl['folder_id'],
                    'file_size' => (int)$fl['file_size'],
                    'mime_type' => $fl['mime_type'],
                    'category' => $fl['category'],
                    'description' => $fl['description'],
                    'version' => $fl['version'],
                    'uploaded_by' => (int)$fl['uploaded_by'],
                    'uploaded_at' => $fl['uploaded_at'],
                    'updated_at' => $fl['updated_at']
                ];
            }, $files);

            Flight::json([
                'folder' => $folderFormatted,
                'subfolders' => $subfoldersFormatted,
                'files' => $filesFormatted
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to get folder content', [
                'folder_id' => $folderId,
                'error' => $e->getMessage()
            ]);
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to get folder content',
                'data' => null
            ], 500);
        }
    }

    /**
     * Создать папку
     * POST /api/v1/plan/folders
     *
     * @OA\Post(
     *     path="/api/v1/plan/folders",
     *     summary="Create folder",
     *     tags={"Plans"},
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "project_id"},
     *             @OA\Property(property="name", type="string", example="Название папки"),
     *             @OA\Property(property="project_id", type="integer", example=1),
     *             @OA\Property(property="parent_id", type="integer", example=2, description="Optional, null for root folder")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Folder created",
     *         @OA\JsonContent(type="object")
     *     )
     * )
     */
    public function createFolder(): void
    {
        if (!$this->checkAuth()) {
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            Flight::json([
                'error_code' => 400,
                'status' => 'error',
                'message' => 'Invalid JSON input',
                'data' => null
            ], 400);
            return;
        }

        // Валидация обязательных полей
        if (empty($input['name']) || empty($input['project_id'])) {
            Flight::json([
                'error_code' => 400,
                'status' => 'error',
                'message' => 'name and project_id are required',
                'data' => null
            ], 400);
            return;
        }

        $name = trim($input['name']);
        $projectId = (int)$input['project_id'];
        $parentId = isset($input['parent_id']) && $input['parent_id'] !== null ? (int)$input['parent_id'] : null;

        try {
            $connection = $this->database->getConnection();

            // Если указан parent_id, проверяем что родительская папка существует и принадлежит тому же проекту
            if ($parentId !== null) {
                $parentFolder = $connection->executeQuery(
                    'SELECT id, project_id FROM fw_plan_folders WHERE id = ?',
                    [$parentId]
                )->fetchAssociative();

                if (!$parentFolder) {
                    Flight::json([
                        'error_code' => 404,
                        'status' => 'error',
                        'message' => 'Parent folder not found',
                        'data' => null
                    ], 404);
                    return;
                }

                if ((int)$parentFolder['project_id'] !== $projectId) {
                    Flight::json([
                        'error_code' => 400,
                        'status' => 'error',
                        'message' => 'Parent folder does not belong to the same project',
                        'data' => null
                    ], 400);
                    return;
                }
            }

            // Проверяем уникальность имени в рамках родительской папки
            $existingFolder = $connection->executeQuery(
                'SELECT id FROM fw_plan_folders WHERE name = ? AND parent_id ' . ($parentId === null ? 'IS NULL' : '= ?') . ' AND project_id = ?',
                $parentId === null ? [$name, $projectId] : [$name, $parentId, $projectId]
            )->fetchAssociative();

            if ($existingFolder) {
                Flight::json([
                    'error_code' => 409,
                    'status' => 'error',
                    'message' => 'Folder with this name already exists in the same location',
                    'data' => null
                ], 409);
                return;
            }

            // Создаем папку
            $connection->executeStatement(
                'INSERT INTO fw_plan_folders (name, parent_id, project_id, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())',
                [$name, $parentId, $projectId]
            );

            $folderId = $connection->lastInsertId();

            // Получаем созданную папку
            $newFolder = $connection->executeQuery(
                'SELECT id, name, parent_id, project_id, created_at, updated_at FROM fw_plan_folders WHERE id = ?',
                [$folderId]
            )->fetchAssociative();

            Flight::json([
                'id' => (int)$newFolder['id'],
                'name' => $newFolder['name'],
                'parent_id' => $newFolder['parent_id'] !== null ? (int)$newFolder['parent_id'] : null,
                'project_id' => (int)$newFolder['project_id'],
                'created_at' => $newFolder['created_at'],
                'updated_at' => $newFolder['updated_at']
            ], 201);

        } catch (Exception $e) {
            $this->logger->error('Failed to create folder', [
                'name' => $name,
                'project_id' => $projectId,
                'parent_id' => $parentId,
                'error' => $e->getMessage()
            ]);
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to create folder',
                'data' => null
            ], 500);
        }
    }

    /**
     * Удалить папку рекурсивно (со всем содержимым)
     * DELETE /api/v1/plan/folders/{folderId}
     *
     * @OA\Delete(
     *     path="/api/v1/plan/folders/{folderId}",
     *     summary="Delete folder recursively",
     *     tags={"Plans"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(name="folderId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Folder and all contents deleted successfully",
     *         @OA\JsonContent(type="object")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Folder not found",
     *         @OA\JsonContent(type="object")
     *     )
     * )
     */
    public function deleteFolder(int $folderId): void
    {
        if (!$this->checkAuth()) {
            return;
        }

        try {
            $connection = $this->database->getConnection();

            // Проверяем существование папки
            $folder = $connection->executeQuery(
                'SELECT id, name, parent_id, project_id, created_at, updated_at FROM fw_plan_folders WHERE id = ?',
                [$folderId]
            )->fetchAssociative();

            if (!$folder) {
                Flight::json([
                    'error' => 'Folder not found'
                ], 404);
                return;
            }

            // Начинаем транзакцию
            $connection->beginTransaction();

            try {
                // Рекурсивно удаляем папку и все содержимое
                $this->deleteFolderRecursive($connection, $folderId);

                // Подтверждаем транзакцию
                $connection->commit();

                Flight::json([
                    'message' => 'Folder and all contents deleted successfully',
                    'folder' => [
                        'id' => (int)$folder['id'],
                        'name' => $folder['name'],
                        'parent_id' => $folder['parent_id'] !== null ? (int)$folder['parent_id'] : null,
                        'project_id' => (int)$folder['project_id'],
                        'created_at' => $folder['created_at'],
                        'updated_at' => $folder['updated_at']
                    ]
                ]);

            } catch (Exception $e) {
                // Откатываем транзакцию при ошибке
                $connection->rollBack();
                throw $e;
            }

        } catch (Exception $e) {
            $this->logger->error('Failed to delete folder', [
                'folder_id' => $folderId,
                'error' => $e->getMessage()
            ]);
            Flight::json([
                'error' => 'Internal server error'
            ], 500);
        }
    }

    /**
     * Рекурсивное удаление папки и всего содержимого
     */
    private function deleteFolderRecursive($connection, int $folderId): void
    {
        // Получаем все файлы в папке
        $files = $connection->executeQuery(
            'SELECT id, file_path FROM fw_plan_files WHERE folder_id = ?',
            [$folderId]
        )->fetchAllAssociative();

        // Удаляем файлы с диска и из базы
        foreach ($files as $file) {
            // Удаляем файл с диска (заглушка)
            $this->deleteFileFromDisk($file['file_path'], $file['id']);

            // Удаляем запись о файле из базы
            $connection->executeStatement(
                'DELETE FROM fw_plan_files WHERE id = ?',
                [$file['id']]
            );
        }

        // Получаем все подпапки
        $subfolders = $connection->executeQuery(
            'SELECT id FROM fw_plan_folders WHERE parent_id = ?',
            [$folderId]
        )->fetchAllAssociative();

        // Рекурсивно удаляем подпапки
        foreach ($subfolders as $subfolder) {
            $this->deleteFolderRecursive($connection, (int)$subfolder['id']);
        }

        // Удаляем саму папку
        $connection->executeStatement(
            'DELETE FROM fw_plan_folders WHERE id = ?',
            [$folderId]
        );
    }

    /**
     * Загрузить файл
     * POST /api/v1/plan/files/upload
     *
     * @OA\Post(
     *     path="/api/v1/plan/files/upload",
     *     summary="Upload file",
     *     tags={"Plans"},
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"file", "folder_id"},
     *                 @OA\Property(property="file", type="string", format="binary"),
     *                 @OA\Property(property="folder_id", type="integer", example=1),
     *                 @OA\Property(property="fileName", type="string", example="document.pdf"),
     *                 @OA\Property(property="description", type="string", example="Project document"),
     *                 @OA\Property(property="category", type="string", example="pdf"),
     *                 @OA\Property(property="version", type="string", example="1.0")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="File uploaded successfully",
     *         @OA\JsonContent(type="object")
     *     )
     * )
     */
    public function uploadFile(): void
    {
        if (!$this->checkAuth()) {
            return;
        }

        try {
            // Проверяем наличие файла
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                Flight::json([
                    'error' => 'Missing required fields'
                ], 400);
                return;
            }

            // Получаем параметры
            $folderId = $_POST['folder_id'] ?? null;
            $fileName = $_POST['fileName'] ?? null;
            $description = $_POST['description'] ?? '';
            $category = $_POST['category'] ?? 'document';
            $version = $_POST['version'] ?? '1.0';

            // Валидация обязательных полей
            if (!$folderId || !$fileName) {
                Flight::json([
                    'error' => 'Missing required fields'
                ], 400);
                return;
            }

            $folderId = (int)$folderId;
            $file = $_FILES['file'];

            // Проверяем размер файла (максимум 50MB)
            $maxFileSize = 50 * 1024 * 1024; // 50MB
            if ($file['size'] > $maxFileSize) {
                Flight::json([
                    'error' => 'File too large'
                ], 400);
                return;
            }

            $connection = $this->database->getConnection();

            // Проверяем существование папки
            $folder = $connection->executeQuery(
                'SELECT id FROM fw_plan_folders WHERE id = ?',
                [$folderId]
            )->fetchAssociative();

            if (!$folder) {
                Flight::json([
                    'error' => 'Folder not found'
                ], 404);
                return;
            }

            // Генерируем уникальное имя файла
            $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $baseFileName = pathinfo($fileName, PATHINFO_FILENAME); // Убираем расширение из fileName
            $uniqueFileName = $baseFileName . '_' . time() . '_' . uniqid() . '.' . $fileExtension;
            $filePath = '/uploads/plan/' . $uniqueFileName;
            $fullPath = __DIR__ . '/../../public' . $filePath;

            // Создаем директорию uploads/plan если не существует
            $uploadsDir = __DIR__ . '/../../public/uploads/plan';
            if (!is_dir($uploadsDir)) {
                mkdir($uploadsDir, 0755, true);
            }

            // Перемещаем файл
            if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
                Flight::json([
                    'error' => 'Failed to save file'
                ], 500);
                return;
            }

            // Получаем ID пользователя из токена (заглушка)
            $uploadedBy = $this->getCurrentUserId();

            // Сохраняем информацию о файле в базу
            $connection->executeStatement(
                'INSERT INTO fw_plan_files (file_name, original_name, file_path, folder_id, file_size, mime_type, category, description, version, uploaded_by, uploaded_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    $uniqueFileName,
                    $fileName,
                    $filePath,
                    $folderId,
                    $file['size'],
                    $file['type'],
                    $category,
                    $description,
                    $version,
                    $uploadedBy
                ]
            );

            $fileId = $connection->lastInsertId();

            // Получаем созданный файл
            $uploadedFile = $connection->executeQuery(
                'SELECT id, file_name, original_name, file_path, folder_id, file_size, mime_type, category, description, version, uploaded_by, uploaded_at, updated_at FROM fw_plan_files WHERE id = ?',
                [$fileId]
            )->fetchAssociative();

            Flight::json([
                'id' => (int)$uploadedFile['id'],
                'file_name' => $uploadedFile['file_name'],
                'original_name' => $uploadedFile['original_name'],
                'file_path' => $uploadedFile['file_path'],
                'folder_id' => (int)$uploadedFile['folder_id'],
                'file_size' => (int)$uploadedFile['file_size'],
                'mime_type' => $uploadedFile['mime_type'],
                'category' => $uploadedFile['category'],
                'description' => $uploadedFile['description'],
                'version' => $uploadedFile['version'],
                'uploaded_by' => (int)$uploadedFile['uploaded_by'],
                'uploaded_at' => $uploadedFile['uploaded_at'],
                'updated_at' => $uploadedFile['updated_at']
            ], 201);

        } catch (Exception $e) {
            $this->logger->error('Failed to upload file', [
                'error' => $e->getMessage()
            ]);
            Flight::json([
                'error' => 'Internal server error'
            ], 500);
        }
    }

    /**
     * Удалить файл
     * DELETE /api/v1/plan/files/{fileId}
     *
     * @OA\Delete(
     *     path="/api/v1/plan/files/{fileId}",
     *     summary="Delete file",
     *     tags={"Plans"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(name="fileId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="File deleted successfully",
     *         @OA\JsonContent(type="object")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="File not found",
     *         @OA\JsonContent(type="object")
     *     )
     * )
     */
    public function deleteFile(int $fileId): void
    {
        if (!$this->checkAuth()) {
            return;
        }

        try {
            $connection = $this->database->getConnection();

            // Проверяем существование файла
            $file = $connection->executeQuery(
                'SELECT id, file_name, original_name, file_path, folder_id, file_size, mime_type, category, description, version, uploaded_by, uploaded_at, updated_at FROM fw_plan_files WHERE id = ?',
                [$fileId]
            )->fetchAssociative();

            if (!$file) {
                Flight::json([
                    'error' => 'File not found'
                ], 404);
                return;
            }

            // Удаляем файл с диска
            $this->deleteFileFromDisk($file['file_path'], $file['id']);

            // Удаляем запись о файле из базы
            $connection->executeStatement(
                'DELETE FROM fw_plan_files WHERE id = ?',
                [$fileId]
            );

            Flight::json([
                'message' => 'File deleted successfully',
                'file' => [
                    'id' => (int)$file['id'],
                    'file_name' => $file['file_name'],
                    'original_name' => $file['original_name'],
                    'file_path' => $file['file_path'],
                    'folder_id' => (int)$file['folder_id'],
                    'file_size' => (int)$file['file_size'],
                    'mime_type' => $file['mime_type'],
                    'category' => $file['category'],
                    'description' => $file['description'],
                    'version' => $file['version'],
                    'uploaded_by' => (int)$file['uploaded_by'],
                    'uploaded_at' => $file['uploaded_at'],
                    'updated_at' => $file['updated_at']
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to delete file', [
                'file_id' => $fileId,
                'error' => $e->getMessage()
            ]);
            Flight::json([
                'error' => 'Internal server error'
            ], 500);
        }
    }

    /**
     * Скачать файл или показать в браузере
     * GET /api/v1/plan/files/{fileId}/download
     * GET /api/v1/plan/files/{fileId}/download?action=preview
     *
     * @OA\Get(
     *     path="/api/v1/plan/files/{fileId}/download",
     *     summary="Download or preview file",
     *     tags={"Plans"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(name="fileId", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="action", in="query", @OA\Schema(type="string", enum={"preview"})),
     *     @OA\Response(
     *         response=200,
     *         description="File content",
     *         @OA\MediaType(mediaType="application/octet-stream")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="File not found",
     *         @OA\JsonContent(type="object")
     *     )
     * )
     */
    public function downloadFile(int $fileId): void
    {
        if (!$this->checkAuth()) {
            return;
        }
    
        try {
            $connection = $this->database->getConnection();
    
            // Получаем информацию о файле
            $file = $connection->executeQuery(
                'SELECT id, file_name, original_name, file_path, file_size, mime_type FROM fw_plan_files WHERE id = ?',
                [$fileId]
            )->fetchAssociative();
    
            if (!$file) {
                Flight::json([
                    'error' => 'File not found'
                ], 404);
                return;
            }
    
            $filePath = $file['file_path'];
            $fullPath = __DIR__ . '/../../public' . $filePath;
    
            // Проверяем существование файла на диске
            if (!file_exists($fullPath)) {
                $this->logger->error('File not found on disk', [
                    'file_id' => $fileId,
                    'file_path' => $filePath,
                    'full_path' => $fullPath
                ]);
                Flight::json([
                    'error' => 'File not found on disk'
                ], 404);
                return;
            }
    
            // Определяем режим: скачивание или предварительный просмотр
            $action = $_GET['action'] ?? 'download';
            $isPreview = $action === 'preview';
    
            // Устанавливаем заголовки
            header('Content-Type: ' . $file['mime_type']);
            header('Content-Length: ' . $file['file_size']);
            header('Content-Transfer-Encoding: binary');
            
            if ($isPreview) {
                // Для предварительного просмотра - показываем в браузере
                header('Content-Disposition: inline; filename="' . $file['original_name'] . '"');
                header('Cache-Control: public, max-age=3600');
            } else {
                // Для скачивания - принудительное скачивание
                header('Content-Disposition: attachment; filename="' . $file['original_name'] . '"');
                header('Cache-Control: no-cache, must-revalidate');
                header('Pragma: no-cache');
            }
    
            // Логируем перед отправкой
            $this->logger->info('Downloading file', [
                'file_id' => $fileId,
                'action' => $action,
                'is_preview' => $isPreview,
                'file_path' => $fullPath,
                'file_exists' => file_exists($fullPath),
                'file_size' => filesize($fullPath),
                'mime_type' => $file['mime_type'],
                'original_name' => $file['original_name']
            ]);
    
            // Отключаем буферизацию для больших файлов
            if (ob_get_level()) {
                ob_end_clean();
            }
    
            // Отправляем файл по частям для стабильности
            $handle = fopen($fullPath, 'rb');
            if ($handle === false) {
                $this->logger->error('Cannot open file for reading', [
                    'file_id' => $fileId,
                    'file_path' => $fullPath
                ]);
                Flight::json(['error' => 'Cannot open file'], 500);
                return;
            }
    
            // Отправляем файл по 8KB блокам
            while (!feof($handle)) {
                $chunk = fread($handle, 8192);
                if ($chunk === false) {
                    break;
                }
                echo $chunk;
                flush();
            }
            
            fclose($handle);
    
            // Логируем успешную отправку
            $this->logger->info('File sent successfully', [
                'file_id' => $fileId,
                'file_size_sent' => filesize($fullPath),
                'action' => $isPreview ? 'preview' : 'download'
            ]);
    
        } catch (Exception $e) {
            $this->logger->error('Failed to download file', [
                'file_id' => $fileId,
                'error' => $e->getMessage()
            ]);
            Flight::json([
                'error' => 'Internal server error'
            ], 500);
        }
    }

    /**
     * Получить ID текущего пользователя (заглушка)
     */
    private function getCurrentUserId(): int
    {
        // TODO: Реальная реализация получения ID пользователя из токена
        return 1; // Заглушка
    }

    /**
     * Удаление файла с диска
     */
    private function deleteFileFromDisk(string $filePath, int $fileId): void
    {
        $fullPath = __DIR__ . '/../../public' . $filePath;
        
        $this->logger->info('Attempting to delete file from disk', [
            'file_path' => $filePath,
            'full_path' => $fullPath,
            'file_id' => $fileId,
            'exists' => file_exists($fullPath)
        ]);

        if (file_exists($fullPath)) {
            if (!unlink($fullPath)) {
                $this->logger->error('Failed to delete file from disk', [
                    'file_path' => $fullPath,
                    'file_id' => $fileId
                ]);
                throw new Exception('Failed to delete file from disk: ' . $fullPath);
            } else {
                $this->logger->info('File successfully deleted from disk', [
                    'file_path' => $fullPath,
                    'file_id' => $fileId
                ]);
            }
        } else {
            $this->logger->warning('File not found on disk during deletion', [
                'file_path' => $fullPath,
                'file_id' => $fileId
            ]);
        }
    }

    /**
     * Проверка авторизации (reuse из TaskController стиля)
     */
    private function checkAuth(): bool
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            Flight::json([
                'error_code' => 401,
                'status' => 'error',
                'message' => 'Authorization header required',
                'data' => null
            ], 401);
            return false;
        }

        $token = substr($authHeader, 7);
        try {
            $decoded = base64_decode($token);
            if ($decoded === false) {
                Flight::json([
                    'error_code' => 401,
                    'status' => 'error',
                    'message' => 'Invalid token',
                    'data' => null
                ], 401);
                return false;
            }

            $payload = json_decode($decoded, true);
            if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) {
                Flight::json([
                    'error_code' => 401,
                    'status' => 'error',
                    'message' => 'Invalid or expired token',
                    'data' => null
                ], 401);
                return false;
            }

            return true;
        } catch (\Exception $e) {
            Flight::json([
                'error_code' => 401,
                'status' => 'error',
                'message' => 'Invalid token',
                'data' => null
            ], 401);
            return false;
        }
    }
}


