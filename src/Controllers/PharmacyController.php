<?php

namespace App\Controllers;

use App\Database\Database;
use App\Support\ClientListContactFilter;
use App\Support\ClientListSort;
use Flight;
use Exception;
use Monolog\Logger;

class PharmacyController
{
    private Logger $logger;
    private Database $database;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        
        try {
            $this->database = new Database();
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize PharmacyController database', [
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
     * Получить список аптек с фильтрацией
     * GET /api/v1/pharmacies
     */
    public function getPharmacies()
    {
        // Проверка токена
        try {
            $country = Flight::request()->query->country ?? null;
            $region = Flight::request()->query->region ?? null;
            $subType = Flight::request()->query->sub_type ?? null;
            $salesCycle = Flight::request()->query->sales_cycle ?? null;
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

            if ($subType) {
                $whereConditions[] = "sub_type = ?";
                $params[] = $subType;
            }

            if ($salesCycle) {
                $whereConditions[] = "sales_cycle = ?";
                $params[] = $salesCycle;
            }

            if ($search !== '') {
                if (ctype_digit($search)) {
                    $whereConditions[] = "(id = ? OR operName LIKE ? OR legalName LIKE ? OR email LIKE ? OR contact LIKE ? OR city LIKE ? OR street LIKE ?)";
                    $searchTerm = '%' . $search . '%';
                    array_push($params, (int)$search, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
                } else {
                    $whereConditions[] = "(operName LIKE ? OR legalName LIKE ? OR email LIKE ? OR contact LIKE ? OR city LIKE ? OR street LIKE ?)";
                    $searchTerm = '%' . $search . '%';
                    array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
                }
            }

            ClientListContactFilter::apply(
                'pharma',
                $whereConditions,
                Flight::request()->query->nonEmpty ?? null,
                Flight::request()->query->empty ?? null,
                Flight::request()->query->missingContacts ?? null,
            );

            $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

            // Получить общее количество
            $countSql = "SELECT COUNT(*) as total FROM pharma $whereClause";
            $countResult = $this->database->getConnection()->executeQuery($countSql, $params);
            $total = $countResult->fetchAssociative()['total'];

            $orderBy = ClientListSort::resolveOrderBy(
                Flight::request()->query->sortBy ?? null,
                Flight::request()->query->sortDir ?? null,
                [
                    'id' => 'id',
                    'operName' => 'operName',
                    'legalName' => 'legalName',
                    'country' => 'country',
                    'region' => 'region',
                    'city' => 'city',
                    'email' => 'email',
                ],
                'operName',
            );

            // Получить аптеки
            $sql = "SELECT id, operName, legalName, contact, owner, manager, unitNumb, phone, cell, email, fax, twilioPhone, fullAddress, street, city, region, country, postcode, lat, lng, `no-centrals`, otpFee, marketingFee, sub_type, comp_volumes, sales_cycle, notes FROM pharma $whereClause ORDER BY $orderBy LIMIT $limit OFFSET $offset";

            $result = $this->database->getConnection()->executeQuery($sql, $params);
            $pharmacies = $result->fetchAllAssociative();

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Pharmacies retrieved successfully',
                'data' => [
                    'pharmacies' => $pharmacies,
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total' => $total,
                        'pages' => ceil($total / $limit)
                    ]
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error getting pharmacies: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve pharmacies',
                'data' => null
            ], 500);
        }
    }

    /**
     * Получить аптеку по ID
     * GET /api/v1/pharmacies/12345
     */
    public function getPharmacy($id = null)
    {
        // Проверка токена
        try {
            if (!$id) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Pharmacy ID is required',
                    'data' => null
                ], 400);
                return;
            }

            $sql = "SELECT id, operName, legalName, contact, owner, manager, unitNumb, phone, cell, email, fax, twilioPhone, fullAddress, street, city, region, country, postcode, lat, lng, `no-centrals`, otpFee, marketingFee, sub_type, comp_volumes, sales_cycle, notes FROM pharma WHERE id = ?";
            $params = [$id];

            $result = $this->database->getConnection()->executeQuery($sql, $params);
            $pharmacy = $result->fetchAssociative();

            if (!$pharmacy) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Pharmacy not found',
                    'data' => null
                ], 404);
                return;
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Pharmacy retrieved successfully',
                'data' => $pharmacy
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error getting pharmacy: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve pharmacy',
                'data' => null
            ], 500);
        }
    }

    /**
     * Создать новую аптеку
     * POST /api/v1/pharmacies
     */
    public function createPharmacy()
    {
        // Проверка токена
        try {
            $data = Flight::request()->data;

            // Валидация обязательных полей
            $requiredFields = ['operName', 'email', 'login'];
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

            $sql = "INSERT INTO pharma (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
            
            $this->database->getConnection()->executeStatement($sql, $values);
            
            $pharmacyId = $this->database->getConnection()->lastInsertId();

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Pharmacy created successfully',
                'data' => [
                    'id' => $pharmacyId
                ]
            ], 201);

        } catch (Exception $e) {
            $this->logger->error('Error creating pharmacy: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to create pharmacy',
                'data' => null
            ], 500);
        }
    }

    /**
     * Обновить аптеку
     * PUT /api/v1/pharmacies/12345
     */
    public function updatePharmacy($id)
    {
        // Проверка токена
        try {
            if (!$id) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Pharmacy ID is required',
                    'data' => null
                ], 400);
                return;
            }

            $data = Flight::request()->data;

            $allowedFields = [
                'operName', 'legalName', 'contact', 'owner', 'manager',
                'unitNumb', 'phone', 'cell', 'email', 'fax', 'twilioPhone',
                'fullAddress', 'street', 'city', 'region', 'country', 'postcode',
                'lat', 'lng', 'otpFee', 'marketingFee', 'sub_type', 'comp_volumes',
                'sales_cycle', 'notes',
            ];

            // Проверить существование аптеки
            $checkSql = "SELECT id FROM pharma WHERE id = ?";
            $checkStmt = $this->database->getConnection()->executeQuery($checkSql, [$id]);
            
            if (!$checkStmt->fetchAssociative()) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Pharmacy not found',
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

            $sql = "UPDATE pharma SET " . implode(', ', $fields) . " WHERE id = ?";
            
            $affectedRows = $this->database->getConnection()->executeStatement($sql, $values);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Pharmacy updated successfully',
                'data' => [
                    'id' => $id,
                    'affected_rows' => $affectedRows
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error updating pharmacy: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to update pharmacy',
                'data' => null
            ], 500);
        }
    }

    /**
     * Удалить аптеку
     * DELETE /api/v1/pharmacies/12345
     */
    public function deletePharmacy($id)
    {
        // Проверка токена
        try {
            if (!$id) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Pharmacy ID is required',
                    'data' => null
                ], 400);
                return;
            }

            // Проверить существование аптеки
            $checkSql = "SELECT id FROM pharma WHERE id = ?";
            $checkStmt = $this->database->getConnection()->executeQuery($checkSql, [$id]);
            
            if (!$checkStmt->fetchAssociative()) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Pharmacy not found',
                    'data' => null
                ], 404);
                return;
            }

            // Удалить аптеку
            $sql = "DELETE FROM pharma WHERE id = ?";
            $affectedRows = $this->database->getConnection()->executeStatement($sql, [$id]);

            if ($affectedRows === 0) {
                Flight::json([
                    'error_code' => 500,
                    'status' => 'error',
                    'message' => 'Failed to delete pharmacy',
                    'data' => null
                ], 500);
                return;
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Pharmacy deleted successfully',
                'data' => [
                    'id' => $id
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error deleting pharmacy: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to delete pharmacy',
                'data' => null
            ], 500);
        }
    }
}
