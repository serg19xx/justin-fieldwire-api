<?php

namespace App\Controllers;

use App\Database\Database;
use Flight;
use Monolog\Logger;

class GeographyController
{
    private $logger;
    private $db;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->db = new Database();
    }

    /**
     * Получить список стран и регионов
     */
    public function getCountriesAndRegions()
    {
        try {
            $this->logger->info('Getting countries and regions list');

            $sql = "
                SELECT JSON_ARRAYAGG(country_json ORDER BY country_name) AS result
                FROM (
                    SELECT
                        c.name AS country_name,
                        JSON_OBJECT(
                            'id', c.id,
                            'name', c.name,
                            'code2', c.code2,
                            'regions', COALESCE(
                                (
                                    SELECT JSON_ARRAYAGG(
                                        JSON_OBJECT(
                                            'id', s.id, 
                                            'name', s.name, 
                                            'state_subdivision_code', s.state_subdivision_code, 
                                            'code2', s.state_subdivision_code
                                        )
                                        ORDER BY s.name
                                    )
                                    FROM subdivisions s
                                    WHERE s.country_code2 = c.code2
                                ),
                                JSON_ARRAY()
                            )
                        ) AS country_json
                    FROM country c
                    WHERE c.active = 1
                ) t
            ";

            $result = $this->db->getConnection()->executeQuery($sql)->fetchAssociative();

            if (!$result || !$result['result']) {
                Flight::json([
                    'error_code' => 0,
                    'status' => 'success',
                    'message' => 'No countries found',
                    'data' => []
                ]);
                return;
            }

            $countries = json_decode($result['result'], true);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Countries and regions retrieved successfully',
                'data' => [
                    'countries' => $countries,
                    'total_countries' => count($countries)
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Error getting countries and regions: ' . $e->getMessage());
            
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve countries and regions',
                'data' => null
            ], 500);
        }
    }

    /**
     * Получить список только стран
     */
    public function getCountries()
    {
        try {
            $this->logger->info('Getting countries list');

            $sql = "
                SELECT id, name, code2, code3
                FROM country 
                WHERE active = 1 
                ORDER BY name
            ";

            $result = $this->db->getConnection()->executeQuery($sql)->fetchAllAssociative();

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Countries retrieved successfully',
                'data' => [
                    'countries' => $result,
                    'total_countries' => count($result)
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Error getting countries: ' . $e->getMessage());
            
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve countries',
                'data' => null
            ], 500);
        }
    }

    /**
     * Получить регионы для конкретной страны
     */
    public function getRegionsByCountry($countryCode)
    {
        try {
            $this->logger->info("Getting regions for country: {$countryCode}");

            $sql = "
                SELECT id, name, state_subdivision_code as code2
                FROM subdivisions 
                WHERE country_code2 = ? 
                ORDER BY name
            ";

            $result = $this->db->getConnection()->executeQuery($sql, [$countryCode])->fetchAllAssociative();

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Regions retrieved successfully',
                'data' => [
                    'country_code' => $countryCode,
                    'regions' => $result,
                    'total_regions' => count($result)
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Error getting regions: ' . $e->getMessage());
            
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve regions',
                'data' => null
            ], 500);
        }
    }
}
