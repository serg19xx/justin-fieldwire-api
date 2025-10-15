<?php

namespace App\Controllers;

use App\Database\Database;
use Flight;
use Exception;
use Monolog\Logger;

class LanguageController
{
    private $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @OA\Get(
     *     path="/api/v1/languages",
     *     tags={"Languages"},
     *     summary="Get all languages",
     *     description="Retrieve all available languages",
     *     @OA\Response(
     *         response=200,
     *         description="Languages retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Languages retrieved successfully"),
     *             @OA\Property(property="data", type="array", @OA\Items(
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="English")
     *             ))
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=500),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Failed to retrieve languages")
     *         )
     *     )
     * )
     */
    public function getLanguages(): void
    {
        try {
            $connection = Database::getConnection();
            $result = $connection->executeQuery("SELECT id, name FROM fw_languages ORDER BY name");
            $languages = [];
            
            while ($row = $result->fetchAssociative()) {
                $languages[] = [
                    'id' => (int)$row['id'],
                    'name' => $row['name']
                ];
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Languages retrieved successfully',
                'data' => $languages
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error fetching languages', ['error' => $e->getMessage()]);
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve languages'
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/languages",
     *     tags={"Languages"},
     *     summary="Create a new language",
     *     description="Add a new language to the system",
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", example="Spanish")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Language created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Language created successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="Spanish")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=400),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Language name is required")
     *         )
     *     ),
     *     @OA\Response(
     *         response=409,
     *         description="Conflict",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=409),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Language already exists")
     *         )
     *     )
     * )
     */
    public function createLanguage(): void
    {
        try {
            $input = json_decode(Flight::request()->getBody(), true);
            
            if (!isset($input['name']) || empty(trim($input['name']))) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Language name is required'
                ], 400);
                return;
            }

            $connection = Database::getConnection();
            $name = trim($input['name']);

            // Check if language already exists
            $existing = $connection->executeQuery(
                "SELECT id FROM fw_languages WHERE name = ?",
                [$name]
            )->fetchAssociative();

            if ($existing) {
                Flight::json([
                    'error_code' => 409,
                    'status' => 'error',
                    'message' => 'Language already exists'
                ], 409);
                return;
            }

            // Insert new language
            $connection->executeStatement(
                "INSERT INTO fw_languages (name) VALUES (?)",
                [$name]
            );

            $languageId = $connection->lastInsertId();

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Language created successfully',
                'data' => [
                    'id' => (int)$languageId,
                    'name' => $name
                ]
            ], 201);

        } catch (Exception $e) {
            $this->logger->error('Error creating language', ['error' => $e->getMessage()]);
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to create language'
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/workers/{workerId}/languages",
     *     tags={"Worker Languages"},
     *     summary="Get worker languages",
     *     description="Retrieve all languages for a specific worker",
     *     @OA\Parameter(
     *         name="workerId",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer"),
     *         description="Worker ID"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Worker languages retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Worker languages retrieved successfully"),
     *             @OA\Property(property="data", type="array", @OA\Items(
     *                 @OA\Property(property="language_id", type="integer", example=1),
     *                 @OA\Property(property="language_name", type="string", example="English"),
     *                 @OA\Property(property="prof_level", type="string", example="Fluent")
     *             ))
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Worker not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=404),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Worker not found")
     *         )
     *     )
     * )
     */
    public function getWorkerLanguages(string $workerId): void
    {
        try {
            $connection = Database::getConnection();
            $workerIdInt = (int)$workerId;
            
            // Check if worker exists
            $worker = $connection->executeQuery(
                "SELECT id FROM fw_users WHERE id = ? AND archived_at IS NULL",
                [$workerIdInt]
            )->fetchAssociative();

            if (!$worker) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Worker not found'
                ], 404);
                return;
            }

            $result = $connection->executeQuery(
                "SELECT wl.language_id, l.name as language_name, wl.prof_level 
                 FROM fw_user_languages wl 
                 INNER JOIN fw_languages l ON wl.language_id = l.id 
                 WHERE wl.worker_id = ? 
                 ORDER BY l.name",
                [$workerIdInt]
            );

            $languages = [];
            while ($row = $result->fetchAssociative()) {
                $languages[] = [
                    'language_id' => (int)$row['language_id'],
                    'language_name' => $row['language_name'],
                    'prof_level' => $row['prof_level']
                ];
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Worker languages retrieved successfully',
                'data' => $languages
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error fetching worker languages', ['error' => $e->getMessage()]);
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve worker languages'
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/workers/{workerId}/languages",
     *     tags={"Worker Languages"},
     *     summary="Add language to worker",
     *     description="Add a language with proficiency level to a worker",
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="workerId",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer"),
     *         description="Worker ID"
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="language_id", type="integer", example=1),
     *             @OA\Property(property="prof_level", type="string", enum={"Basic", "Intermidiate", "Fluent"}, example="Fluent")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Language added to worker successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Language added to worker successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=400),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Language ID and proficiency level are required")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Worker or language not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=404),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Worker or language not found")
     *         )
     *     ),
     *     @OA\Response(
     *         response=409,
     *         description="Conflict",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=409),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Worker already has this language")
     *         )
     *     )
     * )
     */
    public function addWorkerLanguage(string $workerId): void
    {
        try {
            $input = json_decode(Flight::request()->getBody(), true);
            
            if (!isset($input['language_id']) || !isset($input['prof_level'])) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Language ID and proficiency level are required'
                ], 400);
                return;
            }

            $connection = Database::getConnection();
            $workerIdInt = (int)$workerId;
            $languageId = (int)$input['language_id'];
            $profLevel = $input['prof_level'];

            // Validate proficiency level
            if (!in_array($profLevel, ['Basic', 'Intermidiate', 'Fluent'])) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Invalid proficiency level. Must be Basic, Intermidiate, or Fluent'
                ], 400);
                return;
            }

            // Check if worker exists
            $worker = $connection->executeQuery(
                "SELECT id FROM fw_users WHERE id = ? AND archived_at IS NULL",
                [$workerIdInt]
            )->fetchAssociative();

            if (!$worker) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Worker not found'
                ], 404);
                return;
            }

            // Check if language exists
            $language = $connection->executeQuery(
                "SELECT id FROM fw_languages WHERE id = ?",
                [$languageId]
            )->fetchAssociative();

            if (!$language) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Language not found'
                ], 404);
                return;
            }

            // Check if worker already has this language
            $existing = $connection->executeQuery(
                "SELECT worker_id FROM fw_user_languages WHERE worker_id = ? AND language_id = ?",
                [$workerIdInt, $languageId]
            )->fetchAssociative();

            if ($existing) {
                Flight::json([
                    'error_code' => 409,
                    'status' => 'error',
                    'message' => 'Worker already has this language'
                ], 409);
                return;
            }

            // Add language to worker
            $connection->executeStatement(
                "INSERT INTO fw_user_languages (worker_id, language_id, prof_level) VALUES (?, ?, ?)",
                [$workerIdInt, $languageId, $profLevel]
            );

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Language added to worker successfully'
            ], 201);

        } catch (Exception $e) {
            $this->logger->error('Error adding language to worker', ['error' => $e->getMessage()]);
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to add language to worker'
            ], 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/v1/workers/{workerId}/languages/{languageId}",
     *     tags={"Worker Languages"},
     *     summary="Update worker language proficiency",
     *     description="Update the proficiency level of a worker's language",
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="workerId",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer"),
     *         description="Worker ID"
     *     ),
     *     @OA\Parameter(
     *         name="languageId",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer"),
     *         description="Language ID"
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="prof_level", type="string", enum={"Basic", "Intermidiate", "Fluent"}, example="Fluent")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Worker language updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Worker language updated successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=400),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Proficiency level is required")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Worker language not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=404),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Worker language not found")
     *         )
     *     )
     * )
     */
    public function updateWorkerLanguage(string $workerId, string $languageId): void
    {
        try {
            $input = json_decode(Flight::request()->getBody(), true);
            
            if (!isset($input['prof_level'])) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Proficiency level is required'
                ], 400);
                return;
            }

            $connection = Database::getConnection();
            $workerIdInt = (int)$workerId;
            $languageIdInt = (int)$languageId;
            $profLevel = $input['prof_level'];

            // Validate proficiency level
            if (!in_array($profLevel, ['Basic', 'Intermidiate', 'Fluent'])) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Invalid proficiency level. Must be Basic, Intermidiate, or Fluent'
                ], 400);
                return;
            }

            // Check if worker language exists
            $existing = $connection->executeQuery(
                "SELECT worker_id FROM fw_user_languages WHERE worker_id = ? AND language_id = ?",
                [$workerIdInt, $languageIdInt]
            )->fetchAssociative();

            if (!$existing) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Worker language not found'
                ], 404);
                return;
            }

            // Update proficiency level
            $connection->executeStatement(
                "UPDATE fw_user_languages SET prof_level = ? WHERE worker_id = ? AND language_id = ?",
                [$profLevel, $workerIdInt, $languageIdInt]
            );

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Worker language updated successfully'
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error updating worker language', ['error' => $e->getMessage()]);
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to update worker language'
            ], 500);
        }
    }

    /**
     * @OA\Put(
     *     path="/api/v1/languages/{languageId}",
     *     tags={"Languages"},
     *     summary="Update language name",
     *     description="Update the name of an existing language",
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="languageId",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer"),
     *         description="Language ID"
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", example="Updated Language Name")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Language updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Language updated successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="Updated Language Name")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=400),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Language name is required")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Language not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=404),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Language not found")
     *         )
     *     ),
     *     @OA\Response(
     *         response=409,
     *         description="Conflict",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=409),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Language name already exists")
     *         )
     *     )
     * )
     */
    public function updateLanguage(string $languageId): void
    {
        try {
            $input = json_decode(Flight::request()->getBody(), true);
            
            if (!isset($input['name']) || empty(trim($input['name']))) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Language name is required'
                ], 400);
                return;
            }

            $connection = Database::getConnection();
            $languageIdInt = (int)$languageId;
            $name = trim($input['name']);

            // Check if language exists
            $existing = $connection->executeQuery(
                "SELECT id FROM fw_languages WHERE id = ?",
                [$languageIdInt]
            )->fetchAssociative();

            if (!$existing) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Language not found'
                ], 404);
                return;
            }

            // Check if new name already exists (excluding current language)
            $nameExists = $connection->executeQuery(
                "SELECT id FROM fw_languages WHERE name = ? AND id != ?",
                [$name, $languageIdInt]
            )->fetchAssociative();

            if ($nameExists) {
                Flight::json([
                    'error_code' => 409,
                    'status' => 'error',
                    'message' => 'Language name already exists'
                ], 409);
                return;
            }

            // Update language name
            $connection->executeStatement(
                "UPDATE fw_languages SET name = ? WHERE id = ?",
                [$name, $languageIdInt]
            );

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Language updated successfully',
                'data' => [
                    'id' => $languageIdInt,
                    'name' => $name
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error updating language', ['error' => $e->getMessage()]);
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to update language'
            ], 500);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/workers/{workerId}/languages/{languageId}",
     *     tags={"Worker Languages"},
     *     summary="Remove language from worker",
     *     description="Remove a language from a worker",
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="workerId",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer"),
     *         description="Worker ID"
     *     ),
     *     @OA\Parameter(
     *         name="languageId",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer"),
     *         description="Language ID"
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Language removed from worker successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Language removed from worker successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Worker language not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=404),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Worker language not found")
     *         )
     *     )
     * )
     */
    public function removeWorkerLanguage(string $workerId, string $languageId): void
    {
        try {
            $connection = Database::getConnection();
            $workerIdInt = (int)$workerId;
            $languageIdInt = (int)$languageId;

            // Check if worker language exists
            $existing = $connection->executeQuery(
                "SELECT worker_id FROM fw_user_languages WHERE worker_id = ? AND language_id = ?",
                [$workerIdInt, $languageIdInt]
            )->fetchAssociative();

            if (!$existing) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Worker language not found'
                ], 404);
                return;
            }

            // Remove language from worker
            $connection->executeStatement(
                "DELETE FROM fw_user_languages WHERE worker_id = ? AND language_id = ?",
                [$workerIdInt, $languageIdInt]
            );

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Language removed from worker successfully'
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error removing language from worker', ['error' => $e->getMessage()]);
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to remove language from worker'
            ], 500);
        }
    }
}
