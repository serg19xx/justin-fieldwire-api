<?php

namespace App\Controllers;

use App\Database\Database;
use Flight;
use Exception;
use Monolog\Logger;

class MedicalClinicController
{
    private Logger $logger;
    private Database $database;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        
        try {
            $this->database = new Database();
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize MedicalClinicController database', [
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
     * Получить список медицинских клиник с фильтрацией
     * GET /api/v1/medical-clinics
     */
    public function getMedicalClinics()
    {
        // Проверка токена
        if (!$this->checkAuth()) {
            return;
        }

        try {
            $country = Flight::request()->query->country ?? null;
            $region = Flight::request()->query->region ?? null;
            $clinicType = Flight::request()->query->clinicType ?? null;
            $page = (int)(Flight::request()->query->page ?? 1);
            $limit = (int)(Flight::request()->query->limit ?? 50);
            $offset = ($page - 1) * $limit;

            $whereConditions = [];
            $params = [];

            if ($country) {
                $whereConditions[] = "country = ?";
                $params[] = $country;
            }

            if ($region) {
                $whereConditions[] = "region = ?";
                $params[] = $region;
            }

            if ($clinicType) {
                $whereConditions[] = "clinicType = ?";
                $params[] = $clinicType;
            }

            $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

            // Получить общее количество
            $countSql = "SELECT COUNT(*) as total FROM medical_clinic $whereClause";
            $countResult = $this->database->getConnection()->executeQuery($countSql, $params);
            $total = $countResult->fetchAssociative()['total'];

            // Получить медицинские клиники
            $sql = "SELECT id, clinicName, clinicType, contactName, phone, fax, email, unitNumb, streetName, city, region, country, postal, notes, fullAddress, geoAddress, geoCoordinates FROM medical_clinic $whereClause ORDER BY clinicName LIMIT $limit OFFSET $offset";

            $result = $this->database->getConnection()->executeQuery($sql, $params);
            $medicalClinics = $result->fetchAllAssociative();

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Medical clinics retrieved successfully',
                'data' => [
                    'medical_clinics' => $medicalClinics,
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total' => $total,
                        'pages' => ceil($total / $limit)
                    ]
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error getting medical clinics: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve medical clinics',
                'data' => null
            ], 500);
        }
    }

    /**
     * Получить медицинскую клинику по ID
     * GET /api/v1/medical-clinics/12345
     */
    public function getMedicalClinic($id = null)
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
                    'message' => 'Medical clinic ID is required',
                    'data' => null
                ], 400);
                return;
            }

            $sql = "SELECT id, clinicName, clinicType, contactName, phone, fax, email, unitNumb, streetName, city, region, country, postal, notes, fullAddress, geoAddress, geoCoordinates FROM medical_clinic WHERE id = ?";
            $params = [$id];

            $result = $this->database->getConnection()->executeQuery($sql, $params);
            $medicalClinic = $result->fetchAssociative();

            if (!$medicalClinic) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Medical clinic not found',
                    'data' => null
                ], 404);
                return;
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Medical clinic retrieved successfully',
                'data' => $medicalClinic
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error getting medical clinic: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve medical clinic',
                'data' => null
            ], 500);
        }
    }

    /**
     * Создать новую медицинскую клинику
     * POST /api/v1/medical-clinics
     */
    public function createMedicalClinic()
    {
        // Проверка токена
        if (!$this->checkAuth()) {
            return;
        }

        try {
            $data = Flight::request()->data;

            // Валидация обязательных полей
            $requiredFields = ['clinicName', 'contactName', 'phone', 'fax', 'email', 'region', 'country'];
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

            $sql = "INSERT INTO medical_clinic (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
            
            $this->database->getConnection()->executeStatement($sql, $values);
            
            $clinicId = $this->database->getConnection()->lastInsertId();

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Medical clinic created successfully',
                'data' => [
                    'id' => $clinicId
                ]
            ], 201);

        } catch (Exception $e) {
            $this->logger->error('Error creating medical clinic: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to create medical clinic',
                'data' => null
            ], 500);
        }
    }

    /**
     * Обновить медицинскую клинику
     * PUT /api/v1/medical-clinics/12345
     */
    public function updateMedicalClinic($id)
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
                    'message' => 'Medical clinic ID is required',
                    'data' => null
                ], 400);
                return;
            }

            $data = Flight::request()->data;

            // Проверить существование клиники
            $checkSql = "SELECT id FROM medical_clinic WHERE id = ?";
            $checkStmt = $this->database->getConnection()->executeQuery($checkSql, [$id]);
            
            if (!$checkStmt->fetchAssociative()) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Medical clinic not found',
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

            $sql = "UPDATE medical_clinic SET " . implode(', ', $fields) . " WHERE id = ?";
            
            $stmt = $this->database->getConnection()->executeStatement($sql, $values);
            
            $affectedRows = $stmt->rowCount();

            if ($affectedRows === 0) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'No changes made to medical clinic',
                    'data' => null
                ], 400);
                return;
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Medical clinic updated successfully',
                'data' => [
                    'id' => $id,
                    'affected_rows' => $affectedRows
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error updating medical clinic: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to update medical clinic',
                'data' => null
            ], 500);
        }
    }

    /**
     * Удалить медицинскую клинику
     * DELETE /api/v1/medical-clinics/12345
     */
    public function deleteMedicalClinic($id)
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
                    'message' => 'Medical clinic ID is required',
                    'data' => null
                ], 400);
                return;
            }

            // Проверить существование клиники
            $checkSql = "SELECT id FROM medical_clinic WHERE id = ?";
            $checkStmt = $this->database->getConnection()->executeQuery($checkSql, [$id]);
            
            if (!$checkStmt->fetchAssociative()) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Medical clinic not found',
                    'data' => null
                ], 404);
                return;
            }

            // Удалить клинику
            $sql = "DELETE FROM medical_clinic WHERE id = ?";
            $stmt = $this->database->getConnection()->executeStatement($sql, [$id]);
            
            $affectedRows = $stmt->rowCount();

            if ($affectedRows === 0) {
                Flight::json([
                    'error_code' => 500,
                    'status' => 'error',
                    'message' => 'Failed to delete medical clinic',
                    'data' => null
                ], 500);
                return;
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Medical clinic deleted successfully',
                'data' => [
                    'id' => $id
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error deleting medical clinic: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to delete medical clinic',
                'data' => null
            ], 500);
        }
    }
}
