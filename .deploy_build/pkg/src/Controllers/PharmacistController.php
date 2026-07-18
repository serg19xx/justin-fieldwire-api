<?php

namespace App\Controllers;

use App\Database\Database;
use App\Support\ClientListContactFilter;
use App\Support\ClientListSort;
use Flight;
use Exception;
use Monolog\Logger;

class PharmacistController
{
    private Logger $logger;
    private Database $database;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        
        try {
            $this->database = new Database();
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize PharmacistController database', [
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
     * Получить список фармацевтов с фильтрацией
     * GET /api/v1/pharmacists
     */
    public function getPharmacists()
    {
        // Проверка токена
        try {
            $country = Flight::request()->query->country ?? null;
            $region = Flight::request()->query->region ?? null;
            $city = Flight::request()->query->city ?? null;
            $search = trim((string)(Flight::request()->query->search ?? ''));
            $page = (int)(Flight::request()->query->page ?? 1);
            $limit = (int)(Flight::request()->query->limit ?? 50);
            $offset = ($page - 1) * $limit;

            $whereConditions = [];
            $params = [];

            if ($country) {
                $whereConditions[] = "pa.country = ?";
                $params[] = $country;
            }

            if ($region) {
                $whereConditions[] = "pa.region = ?";
                $params[] = $region;
            }

            if ($city) {
                $whereConditions[] = "pa.city = ?";
                $params[] = $city;
            }

            if ($search !== '') {
                if (ctype_digit($search)) {
                    $whereConditions[] = "(pp.id = ? OR pp.pharmId = ? OR pp.fullName LIKE ? OR pp.reg_number LIKE ? OR pp.email LIKE ? OR pp.workplace LIKE ? OR pa.operName LIKE ?)";
                    $searchTerm = '%' . $search . '%';
                    array_push($params, (int)$search, (int)$search, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
                } else {
                    $whereConditions[] = "(pp.fullName LIKE ? OR pp.reg_number LIKE ? OR pp.email LIKE ? OR pp.workplace LIKE ? OR pa.operName LIKE ?)";
                    $searchTerm = '%' . $search . '%';
                    array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
                }
            }

            ClientListContactFilter::apply(
                'pharmacist',
                $whereConditions,
                Flight::request()->query->nonEmpty ?? null,
                Flight::request()->query->empty ?? null,
                Flight::request()->query->missingContacts ?? null,
            );

            $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

            // Получить общее количество
            $countSql = "SELECT COUNT(*) as total FROM pharmacist pp LEFT JOIN pharma pa ON pp.pharmId = pa.id $whereClause";
            $countResult = $this->database->getConnection()->executeQuery($countSql, $params);
            $total = $countResult->fetchAssociative()['total'];

            $orderBy = ClientListSort::resolveOrderBy(
                Flight::request()->query->sortBy ?? null,
                Flight::request()->query->sortDir ?? null,
                [
                    'id' => 'pp.id',
                    'fullName' => 'pp.fullName',
                    'reg_number' => 'pp.reg_number',
                    'operName' => 'pa.operName',
                    'workplace' => 'pp.workplace',
                    'email' => 'pp.email',
                ],
                'fullName',
            );

            // Получить фармацевтов с JOIN
            $sql = "SELECT pp.*, pa.operName, pa.city, pa.country, pa.region FROM pharmacist pp LEFT JOIN pharma pa ON pp.pharmId = pa.id $whereClause ORDER BY $orderBy LIMIT $limit OFFSET $offset";

            $result = $this->database->getConnection()->executeQuery($sql, $params);
            $pharmacists = $result->fetchAllAssociative();

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Pharmacists retrieved successfully',
                'data' => [
                    'pharmacists' => $pharmacists,
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total' => $total,
                        'pages' => ceil($total / $limit)
                    ]
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error getting pharmacists: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve pharmacists',
                'data' => null
            ], 500);
        }
    }

    /**
     * Получить фармацевта по ID
     * GET /api/v1/pharmacists/12345
     */
    public function getPharmacist($id = null)
    {
        // Проверка токена
        try {
            if (!$id) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Pharmacist ID is required',
                    'data' => null
                ], 400);
                return;
            }

            $sql = "SELECT pp.*, pa.operName FROM pharmacist pp LEFT JOIN pharma pa ON pp.pharmId = pa.id WHERE pp.id = ?";
            $params = [$id];

            $result = $this->database->getConnection()->executeQuery($sql, $params);
            $pharmacist = $result->fetchAssociative();

            if (!$pharmacist) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Pharmacist not found',
                    'data' => null
                ], 404);
                return;
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Pharmacist retrieved successfully',
                'data' => $pharmacist
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error getting pharmacist: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve pharmacist',
                'data' => null
            ], 500);
        }
    }

    /**
     * Создать нового фармацевта
     * POST /api/v1/pharmacists
     */
    public function createPharmacist()
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
                if (in_array($key, ['id', 'operName'], true)) continue;
                $fields[] = $key;
                $placeholders[] = '?';
                $values[] = $value;
            }

            $sql = "INSERT INTO pharmacist (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
            
            $this->database->getConnection()->executeStatement($sql, $values);
            
            $pharmacistId = $this->database->getConnection()->lastInsertId();

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Pharmacist created successfully',
                'data' => [
                    'id' => $pharmacistId
                ]
            ], 201);

        } catch (Exception $e) {
            $this->logger->error('Error creating pharmacist: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to create pharmacist',
                'data' => null
            ], 500);
        }
    }

    /**
     * Обновить фармацевта
     * PUT /api/v1/pharmacists/12345
     */
    public function updatePharmacist($id)
    {
        // Проверка токена
        try {
            if (!$id) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Pharmacist ID is required',
                    'data' => null
                ], 400);
                return;
            }

            $data = Flight::request()->data;

            $allowedFields = [
                'pharmId', 'fullName', 'reg_number', 'pharm_owned',
                'workplace', 'cell_phone', 'email', 'notes',
            ];

            // Проверить существование фармацевта
            $checkSql = "SELECT id FROM pharmacist WHERE id = ?";
            $checkStmt = $this->database->getConnection()->executeQuery($checkSql, [$id]);
            
            if (!$checkStmt->fetchAssociative()) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Pharmacist not found',
                    'data' => null
                ], 404);
                return;
            }

            // Подготовка SQL для обновления
            $fields = [];
            $values = [];

            foreach ($data as $key => $value) {
                if (in_array($key, ['id', 'operName'], true)) continue;
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

            $sql = "UPDATE pharmacist SET " . implode(', ', $fields) . " WHERE id = ?";
            
            $affectedRows = $this->database->getConnection()->executeStatement($sql, $values);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Pharmacist updated successfully',
                'data' => [
                    'id' => $id,
                    'affected_rows' => $affectedRows
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error updating pharmacist: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to update pharmacist',
                'data' => null
            ], 500);
        }
    }

    /**
     * Удалить фармацевта
     * DELETE /api/v1/pharmacists/12345
     */
    public function deletePharmacist($id)
    {
        // Проверка токена
        try {
            if (!$id) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Pharmacist ID is required',
                    'data' => null
                ], 400);
                return;
            }

            // Проверить существование фармацевта
            $checkSql = "SELECT id FROM pharmacist WHERE id = ?";
            $checkStmt = $this->database->getConnection()->executeQuery($checkSql, [$id]);
            
            if (!$checkStmt->fetchAssociative()) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Pharmacist not found',
                    'data' => null
                ], 404);
                return;
            }

            // Удалить фармацевта
            $sql = "DELETE FROM pharmacist WHERE id = ?";
            $affectedRows = $this->database->getConnection()->executeStatement($sql, [$id]);

            if ($affectedRows === 0) {
                Flight::json([
                    'error_code' => 500,
                    'status' => 'error',
                    'message' => 'Failed to delete pharmacist',
                    'data' => null
                ], 500);
                return;
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Pharmacist deleted successfully',
                'data' => [
                    'id' => $id
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error deleting pharmacist: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to delete pharmacist',
                'data' => null
            ], 500);
        }
    }
}
