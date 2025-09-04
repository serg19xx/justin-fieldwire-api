<?php

namespace App\Controllers;

use App\Database\Database;
use Flight;
use Exception;
use Monolog\Logger;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Patients",
 *     description="Patient management endpoints"
 * )
 */
class PatientController
{
    private Logger $logger;
    private Database $database;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        
        try {
            // Исправляем: используем new Database() вместо getInstance()
            $this->database = new Database();
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize PatientController database', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Проверить аутентификацию пользователя
     */
    private function checkAuth(): bool
    {
        $currentUser = Flight::get('current_user');
        if (!$currentUser) {
            Flight::json([
                'success' => false,
                'error' => 'Unauthorized - Token required',
                'error_code' => 401
            ], 401);
            return false;
        }
        return true;
    }

    /**
     * Получить список пациентов с фильтрацией по стране и провинции
     * GET /api/v1/patients
     * 
     * @OA\Get(
     *     path="/api/v1/patients",
     *     summary="Get list of patients",
     *     description="Retrieve a paginated list of patients with optional filtering by country and region",
     *     tags={"Patients"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Page number for pagination",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, default=1)
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         description="Number of items per page",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, maximum=100, default=10)
     *     ),
     *     @OA\Parameter(
     *         name="country",
     *         in="query",
     *         description="Filter by country name",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="region",
     *         in="query",
     *         description="Filter by region/province name",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful response with patients list",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="patients", type="array", @OA\Items(type="object")),
     *                 @OA\Property(property="pagination", type="object",
     *                     @OA\Property(property="current_page", type="integer"),
     *                     @OA\Property(property="total_pages", type="integer"),
     *                     @OA\Property(property="total_items", type="integer"),
     *                     @OA\Property(property="items_per_page", type="integer")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - Token required",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="error", type="string", example="Unauthorized - Token required"),
     *             @OA\Property(property="error_code", type="integer", example=401)
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="error", type="string", example="Internal server error"),
     *             @OA\Property(property="error_code", type="integer", example=500)
     *         )
     *     )
     * )
     */
    public function getPatients()
    {
        // Проверка токена
        if (!$this->checkAuth()) {
            return;
        }

        try {
            $country = Flight::request()->query->country ?? null;
            $province = Flight::request()->query->province ?? null;
            $page = (int)(Flight::request()->query->page ?? 1);
            $limit = (int)(Flight::request()->query->limit ?? 50);
            $offset = ($page - 1) * $limit;

            $whereConditions = [];
            $params = [];

            if ($country) {
                $whereConditions[] = "country = ?";
                $params[] = $country;
            }

            if ($province) {
                $whereConditions[] = "province = ?";
                $params[] = $province;
            }

            $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

            // Получить общее количество
            $countSql = "SELECT COUNT(*) as total FROM patient $whereClause";
            $countResult = $this->database->getConnection()->executeQuery($countSql, $params);
            $total = $countResult->fetchAssociative()['total'];

            // Получить пациентов
            $sql = "SELECT id, firstName, lastName, fullAddress, pharmId, lat, lng, cell, email, birthday, gender, country, province, city, postal, address1, address2 FROM patient $whereClause ORDER BY lastName, firstName LIMIT $limit OFFSET $offset";

            $result = $this->database->getConnection()->executeQuery($sql, $params);
            $patients = $result->fetchAllAssociative();

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Patients retrieved successfully',
                'data' => [
                    'patients' => $patients,
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total' => $total,
                        'pages' => ceil($total / $limit)
                    ]
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error getting patients: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve patients',
                'data' => null
            ], 500);
        }
    }

    /**
     * Получить пациента по ID, email или имени (substring)
     * GET /api/v1/patients/12345
     * 
     * @OA\Get(
     *     path="/api/v1/patients/{id}",
     *     summary="Get patient by ID",
     *     description="Retrieve a specific patient by ID, email, or name substring",
     *     tags={"Patients"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Patient ID, email, or name substring",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful response with patient data",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=true),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="patient", type="object")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Patient not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="error", type="string", example="Patient not found"),
     *             @OA\Property(property="error_code", type="integer", example=404)
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - Token required",
     *         @OA\JsonContent(
     *             @OA\Property(property="success", type="boolean", example=false),
     *             @OA\Property(property="error", type="string", example="Unauthorized - Token required"),
     *             @OA\Property(property="error_code", type="integer", example=401)
     *         )
     *     )
     * )
     */
    public function getPatient($id = null)
    {
        // Проверка токена
        if (!$this->checkAuth()) {
            return;
        }

        try {
            $searchType = Flight::request()->query->search_type ?? 'id';
            $searchValue = $id ?? Flight::request()->query->search_value ?? null;

            if (!$searchValue) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Search value is required',
                    'data' => null
                ], 400);
                return;
            }

            $sql = "";
            $params = [];

            switch ($searchType) {
                case 'id':
                    $sql = "SELECT id, firstName, lastName, fullAddress, pharmId, lat, lng, cell, email, birthday, gender, country, province, city, postal, address1, address2 FROM patient WHERE id = ?";
                    $params = [$searchValue];
                    break;
                case 'email':
                    $sql = "SELECT id, firstName, lastName, fullAddress, pharmId, lat, lng, cell, email, birthday, gender, country, province, city, postal, address1, address2 FROM patient WHERE email = ?";
                    $params = [$searchValue];
                    break;
                case 'name':
                    $sql = "SELECT id, firstName, lastName, fullAddress, pharmId, lat, lng, cell, email, birthday, gender, country, province, city, postal, address1, address2 FROM patient WHERE 
                            firstName LIKE ? OR 
                            lastName LIKE ? OR 
                            CONCAT(firstName, ' ', lastName) LIKE ?";
                    $searchPattern = "%$searchValue%";
                    $params = [$searchPattern, $searchPattern, $searchPattern];
                    break;
                default:
                    Flight::json([
                        'error_code' => 400,
                        'status' => 'error',
                        'message' => 'Invalid search type. Use: id, email, or name',
                        'data' => null
                    ], 400);
                    return;
            }

            $result = $this->database->getConnection()->executeQuery($sql, $params);
            $patient = $result->fetchAssociative();

            if (!$patient) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Patient not found',
                    'data' => null
                ], 404);
                return;
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Patient retrieved successfully',
                'data' => $patient
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error getting patient: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve patient',
                'data' => null
            ], 500);
        }
    }

    /**
     * Создать нового пациента
     * POST /api/v1/patients
     */
    public function createPatient()
    {
        // Проверка токена
        if (!$this->checkAuth()) {
            return;
        }

        try {
            $data = Flight::request()->data;

            // Валидация обязательных полей
            $requiredFields = ['firstName', 'lastName', 'buildingType'];
            foreach ($requiredFields as $field) {
                if (empty($data->$field)) {
                    Flight::json([
                        'error_code' => 400,
                        'status' => 'error',
                        'message' => "Field '$field' is required",
                        'data' => null
                    ], 400);
                    return;
                }
            }

            // Подготовка SQL
            $fields = [];
            $placeholders = [];
            $values = [];

            foreach ($data as $key => $value) {
                if (in_array($key, ['id'])) continue; // Пропускаем ID
                $fields[] = $key;
                $placeholders[] = '?';
                $values[] = $value;
            }

            $sql = "INSERT INTO patient (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
            
            $stmt = $this->database->getConnection()->executeStatement($sql, [$id]);
            
            
            $patientId = $this->database->getConnection()->lastInsertId();

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Patient created successfully',
                'data' => [
                    'id' => $patientId
                ]
            ], 201);

        } catch (Exception $e) {
            $this->logger->error('Error creating patient: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to create patient',
                'data' => null
            ], 500);
        }
    }

    /**
     * Обновить пациента
     * PUT /api/v1/patients/12345
     */
    public function updatePatient($id)
    {
        // Проверка токена
        if (!$this->checkAuth()) {
            return;
        }

        try {
            if (!$id) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Patient ID is required',
                    'data' => null
                ], 400);
                return;
            }

            $data = Flight::request()->data;

            // Проверить существование пациента
            $checkSql = "SELECT id FROM patient WHERE id = ?";
            $checkStmt = $this->database->getConnection()->executeQuery($checkSql, [$id]);
            
            
            if (!$checkStmt->fetchAssociative()) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Patient not found',
                    'data' => null
                ], 404);
                return;
            }

            // Подготовка SQL для обновления
            $fields = [];
            $values = [];

            foreach ($data as $key => $value) {
                if (in_array($key, ['id'])) continue; // Пропускаем ID
                $fields[] = "$key = ?";
                $values[] = $value;
            }

            $values[] = $id; // Для WHERE условия

            $sql = "UPDATE patient SET " . implode(', ', $fields) . " WHERE id = ?";
            
            $stmt = $this->database->getConnection()->executeStatement($sql, [$id]);
            
            
            $affectedRows = $stmt->rowCount();

            if ($affectedRows === 0) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'No changes made to patient',
                    'data' => null
                ], 400);
                return;
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Patient updated successfully',
                'data' => [
                    'id' => $id,
                    'affected_rows' => $affectedRows
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error updating patient: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to update patient',
                'data' => null
            ], 500);
        }
    }

    /**
     * Удалить пациента
     * DELETE /api/v1/patients/12345
     */
    public function deletePatient($id)
    {
        // Проверка токена
        if (!$this->checkAuth()) {
            return;
        }

        try {
            if (!$id) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Patient ID is required',
                    'data' => null
                ], 400);
                return;
            }

            // Проверить существование пациента
            $checkSql = "SELECT id FROM patient WHERE id = ?";
            $checkStmt = $this->database->getConnection()->executeQuery($checkSql, [$id]);
            
            
            if (!$checkStmt->fetchAssociative()) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Patient not found',
                    'data' => null
                ], 404);
                return;
            }

            // Удалить пациента
            $sql = "DELETE FROM patient WHERE id = ?";
            $stmt = $this->database->getConnection()->executeStatement($sql, [$id]);
            $stmt;
            
            $affectedRows = $stmt->rowCount();

            if ($affectedRows === 0) {
                Flight::json([
                    'error_code' => 500,
                    'status' => 'error',
                    'message' => 'Failed to delete patient',
                    'data' => null
                ], 500);
                return;
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Patient deleted successfully',
                'data' => [
                    'id' => $id
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error deleting patient: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to delete patient',
                'data' => null
            ], 500);
        }
    }
}


