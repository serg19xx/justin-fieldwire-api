<?php

namespace App\Controllers;

use App\Database\Database;
use Flight;
use Exception;
use Monolog\Logger;

class DriverController
{
    private Logger $logger;
    private Database $database;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        
        try {
            $this->database = new Database();
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize DriverController database', [
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
     * Получить список водителей с фильтрацией
     * GET /api/v1/drivers
     */
    public function getDrivers()
    {
        // Проверка токена
        if (!$this->checkAuth()) {
            return;
        }

        try {
            $country = Flight::request()->query->country ?? null;
            $region = Flight::request()->query->province ?? null;
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

            $whereClause = !empty($whereConditions) ? "WHERE " . implode(" AND ", $whereConditions) : "";

            // Получить общее количество
            $countSql = "SELECT COUNT(*) as total FROM driver $whereClause";
            $countResult = $this->database->getConnection()->executeQuery($countSql, $params);
            $total = $countResult->fetchAssociative()['total'];

            // Получить водителей
            $sql = "SELECT id, fullName, phone, email, address, country, region, city, dlat, dlng, active_plan FROM driver $whereClause ORDER BY fullName LIMIT $limit OFFSET $offset";

            $result = $this->database->getConnection()->executeQuery($sql, $params);
            $drivers = $result->fetchAllAssociative();

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Drivers retrieved successfully',
                'data' => [
                    'drivers' => $drivers,
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total' => $total,
                        'pages' => ceil($total / $limit)
                    ]
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error getting drivers: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve drivers',
                'data' => null
            ], 500);
        }
    }

    /**
     * Получить водителя по ID
     * GET /api/v1/drivers/12345
     */
    public function getDriver($id = null)
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
                    'message' => 'Driver ID is required',
                    'data' => null
                ], 400);
                return;
            }

            $sql = "SELECT id, fullName, phone, email, address, country, region, city, dlat, dlng, active_plan FROM driver WHERE id = ?";
            $params = [$id];

            $result = $this->database->getConnection()->executeQuery($sql, $params);
            $driver = $result->fetchAssociative();

            if (!$driver) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Driver not found',
                    'data' => null
                ], 404);
                return;
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Driver retrieved successfully',
                'data' => $driver
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error getting driver: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve driver',
                'data' => null
            ], 500);
        }
    }

    /**
     * Создать нового водителя
     * POST /api/v1/drivers
     */
    public function createDriver()
    {
        // Проверка токена
        if (!$this->checkAuth()) {
            return;
        }

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

            $sql = "INSERT INTO driver (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
            
            $this->database->getConnection()->executeStatement($sql, $values);
            
            $driverId = $this->database->getConnection()->lastInsertId();

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Driver created successfully',
                'data' => [
                    'id' => $driverId
                ]
            ], 201);

        } catch (Exception $e) {
            $this->logger->error('Error creating driver: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to create driver',
                'data' => null
            ], 500);
        }
    }

    /**
     * Обновить водителя
     * PUT /api/v1/drivers/12345
     */
    public function updateDriver($id)
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
                    'message' => 'Driver ID is required',
                    'data' => null
                ], 400);
                return;
            }

            $data = Flight::request()->data;

            // Проверить существование водителя
            $checkSql = "SELECT id FROM driver WHERE id = ?";
            $checkStmt = $this->database->getConnection()->executeQuery($checkSql, [$id]);
            
            if (!$checkStmt->fetchAssociative()) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Driver not found',
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

            $sql = "UPDATE driver SET " . implode(', ', $fields) . " WHERE id = ?";
            
            $stmt = $this->database->getConnection()->executeStatement($sql, $values);
            
            $affectedRows = $stmt->rowCount();

            if ($affectedRows === 0) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'No changes made to driver',
                    'data' => null
                ], 400);
                return;
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Driver updated successfully',
                'data' => [
                    'id' => $id,
                    'affected_rows' => $affectedRows
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error updating driver: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to update driver',
                'data' => null
            ], 500);
        }
    }

    /**
     * Удалить водителя
     * DELETE /api/v1/drivers/12345
     */
    public function deleteDriver($id)
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
                    'message' => 'Driver ID is required',
                    'data' => null
                ], 400);
                return;
            }

            // Проверить существование водителя
            $checkSql = "SELECT id FROM driver WHERE id = ?";
            $checkStmt = $this->database->getConnection()->executeQuery($checkSql, [$id]);
            
            if (!$checkStmt->fetchAssociative()) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Driver not found',
                    'data' => null
                ], 404);
                return;
            }

            // Удалить водителя
            $sql = "DELETE FROM driver WHERE id = ?";
            $stmt = $this->database->getConnection()->executeStatement($sql, [$id]);
            
            $affectedRows = $stmt->rowCount();

            if ($affectedRows === 0) {
                Flight::json([
                    'error_code' => 500,
                    'status' => 'error',
                    'message' => 'Failed to delete driver',
                    'data' => null
                ], 500);
                return;
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Driver deleted successfully',
                'data' => [
                    'id' => $id
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error deleting driver: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to delete driver',
                'data' => null
            ], 500);
        }
    }
}
