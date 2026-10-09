<?php

namespace App\Controllers;

use App\Database\Database;
use App\Support\ClientListContactFilter;
use App\Support\ClientListSort;
use Flight;
use Exception;
use Monolog\Logger;

class PhysicianController
{
    private Logger $logger;
    private Database $database;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        
        try {
            $this->database = new Database();
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize PhysicianController database', [
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
     * Получить список врачей с фильтрацией
     * GET /api/v1/physicians
     */
    public function getPhysicians()
    {
        // Проверка токена
        try {
            $country = Flight::request()->query->country ?? null;
            $region = Flight::request()->query->region ?? null;
            $city = Flight::request()->query->city ?? null;
            $specialty = Flight::request()->query->specialty ?? null;
            $search = trim((string)(Flight::request()->query->search ?? ''));
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

            if ($city) {
                $whereConditions[] = "city = ?";
                $params[] = $city;
            }

            if ($specialty) {
                $whereConditions[] = "specialty = ?";
                $params[] = $specialty;
            }

            if ($search !== '') {
                if (ctype_digit($search)) {
                    $whereConditions[] = "(id = ? OR fullName LIKE ? OR company LIKE ? OR specialty LIKE ? OR email LIKE ? OR city LIKE ? OR fullAddress LIKE ?)";
                    $searchTerm = '%' . $search . '%';
                    array_push($params, (int)$search, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
                } else {
                    $whereConditions[] = "(fullName LIKE ? OR company LIKE ? OR specialty LIKE ? OR email LIKE ? OR city LIKE ? OR fullAddress LIKE ?)";
                    $searchTerm = '%' . $search . '%';
                    array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
                }
            }

            ClientListContactFilter::apply(
                'physician',
                $whereConditions,
                Flight::request()->query->nonEmpty ?? null,
                Flight::request()->query->empty ?? null,
                Flight::request()->query->missingContacts ?? null,
            );

            $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

            // Получить общее количество
            $countSql = "SELECT COUNT(*) as total FROM physician $whereClause";
            $countResult = $this->database->getConnection()->executeQuery($countSql, $params);
            $total = $countResult->fetchAssociative()['total'];

            $orderBy = ClientListSort::resolveOrderBy(
                Flight::request()->query->sortBy ?? null,
                Flight::request()->query->sortDir ?? null,
                [
                    'id' => 'id',
                    'fullName' => 'fullName',
                    'specialty' => 'specialty',
                    'company' => 'company',
                    'email' => 'email',
                ],
                'fullName',
            );

            // Получить врачей
            $sql = "SELECT id, prefTitle, fullName, company, specialty, cellPhone, email, faxNumber, officePhone, fullAddress, unitNumb, streetNumber, country, region, city, postal, notes, lat, lng FROM physician $whereClause ORDER BY $orderBy LIMIT $limit OFFSET $offset";

            $result = $this->database->getConnection()->executeQuery($sql, $params);
            $physicians = $result->fetchAllAssociative();

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Physicians retrieved successfully',
                'data' => [
                    'physicians' => $physicians,
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total' => $total,
                        'pages' => ceil($total / $limit)
                    ]
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error getting physicians: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve physicians',
                'data' => null
            ], 500);
        }
    }

    /**
     * Получить врача по ID
     * GET /api/v1/physicians/12345
     */
    public function getPhysician($id = null)
    {
        // Проверка токена
        try {
            if (!$id) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Physician ID is required',
                    'data' => null
                ], 400);
                return;
            }

            $sql = "SELECT id, prefTitle, fullName, company, specialty, cellPhone, email, faxNumber, officePhone, fullAddress, unitNumb, streetNumber, country, region, city, postal, notes, lat, lng FROM physician WHERE id = ?";
            $params = [$id];

            $result = $this->database->getConnection()->executeQuery($sql, $params);
            $physician = $result->fetchAssociative();

            if (!$physician) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Physician not found',
                    'data' => null
                ], 404);
                return;
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Physician retrieved successfully',
                'data' => $physician
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error getting physician: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve physician',
                'data' => null
            ], 500);
        }
    }

    /**
     * Создать нового врача
     * POST /api/v1/physicians
     */
    public function createPhysician()
    {
        // Проверка токена
        try {
            $data = Flight::request()->data;

            // Валидация обязательных полей
            $requiredFields = ['fullName', 'email'];
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

            $sql = "INSERT INTO physician (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
            
            $this->database->getConnection()->executeStatement($sql, $values);
            
            $physicianId = $this->database->getConnection()->lastInsertId();

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Physician created successfully',
                'data' => [
                    'id' => $physicianId
                ]
            ], 201);

        } catch (Exception $e) {
            $this->logger->error('Error creating physician: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to create physician',
                'data' => null
            ], 500);
        }
    }

    /**
     * Обновить врача
     * PUT /api/v1/physicians/12345
     */
    public function updatePhysician($id)
    {
        // Проверка токена
        try {
            if (!$id) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Physician ID is required',
                    'data' => null
                ], 400);
                return;
            }

            $data = Flight::request()->data;

            $allowedFields = [
                'prefTitle', 'fullName', 'company', 'specialty',
                'cellPhone', 'email', 'faxNumber', 'officePhone',
                'fullAddress', 'unitNumb', 'streetNumber', 'country', 'region',
                'city', 'postal', 'notes', 'lat', 'lng',
            ];

            // Проверить существование врача
            $checkSql = "SELECT id FROM physician WHERE id = ?";
            $checkStmt = $this->database->getConnection()->executeQuery($checkSql, [$id]);
            
            if (!$checkStmt->fetchAssociative()) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Physician not found',
                    'data' => null
                ], 404);
                return;
            }

            // Подготовка SQL для обновления
            $fields = [];
            $values = [];

            foreach ($data as $key => $value) {
                if (in_array($key, ['id'], true)) continue;
                if (!in_array($key, $allowedFields, true)) continue;
                $fields[] = "$key = ?";
                $values[] = $value;
            }

            if (empty($fields)) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'No valid fields to update',
                    'data' => null
                ], 400);
                return;
            }

            $values[] = $id; // Для WHERE условия

            $sql = "UPDATE physician SET " . implode(', ', $fields) . " WHERE id = ?";
            
            $affectedRows = $this->database->getConnection()->executeStatement($sql, $values);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Physician updated successfully',
                'data' => [
                    'id' => $id,
                    'affected_rows' => $affectedRows
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error updating physician: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to update physician',
                'data' => null
            ], 500);
        }
    }

    /**
     * Удалить врача
     * DELETE /api/v1/physicians/12345
     */
    public function deletePhysician($id)
    {
        // Проверка токена
        try {
            if (!$id) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Physician ID is required',
                    'data' => null
                ], 400);
                return;
            }

            // Проверить существование врача
            $checkSql = "SELECT id FROM physician WHERE id = ?";
            $checkStmt = $this->database->getConnection()->executeQuery($checkSql, [$id]);
            
            if (!$checkStmt->fetchAssociative()) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Physician not found',
                    'data' => null
                ], 404);
                return;
            }

            // Удалить врача
            $sql = "DELETE FROM physician WHERE id = ?";
            $affectedRows = $this->database->getConnection()->executeStatement($sql, [$id]);

            if ($affectedRows === 0) {
                Flight::json([
                    'error_code' => 500,
                    'status' => 'error',
                    'message' => 'Failed to delete physician',
                    'data' => null
                ], 500);
                return;
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Physician deleted successfully',
                'data' => [
                    'id' => $id
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error deleting physician: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to delete physician',
                'data' => null
            ], 500);
        }
    }
}
