<?php

namespace App\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Monolog\Logger;

class Database
{
    private static ?Connection $connection = null;
    private static Logger $logger;

    public static function setLogger(Logger $logger): void
    {
        self::$logger = $logger;
    }

    /**
     * Get database connection
     */
    public static function getConnection(): Connection
    {
        if (self::$connection === null) {
            self::$connection = self::createConnection();
        }

        return self::$connection;
    }

    /**
     * Create new database connection
     */
    private static function createConnection(): Connection
    {
        $config = [
            'driver' => 'pdo_mysql',
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'port' => $_ENV['DB_PORT'] ?? 3306,
            'dbname' => $_ENV['DB_NAME'] ?? 'fieldwire_api',
            'user' => $_ENV['DB_USERNAME'] ?? 'root',
            'password' => $_ENV['DB_PASSWORD'] ?? '',
            'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
            'options' => [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ],
        ];

        try {
            $connection = DriverManager::getConnection($config);
            
            if (isset(self::$logger)) {
                self::$logger->info('Database connection established', [
                    'host' => $config['host'],
                    'port' => $config['port'],
                    'database' => $config['dbname']
                ]);
            }

            return $connection;
        } catch (Exception $e) {
            if (isset(self::$logger)) {
                self::$logger->error('Database connection failed', [
                    'error' => $e->getMessage(),
                    'host' => $config['host'],
                    'port' => $config['port'],
                    'database' => $config['dbname']
                ]);
            }
            
            throw new \RuntimeException('Failed to connect to database: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Test database connection
     */
    public static function testConnection(): bool
    {
        try {
            $connection = self::getConnection();
            $connection->executeQuery('SELECT 1');
            return true;
        } catch (\Exception $e) {
            if (isset(self::$logger)) {
                self::$logger->error('Database connection test failed', [
                    'error' => $e->getMessage()
                ]);
            }
            return false;
        }
    }

    /**
     * Close database connection
     */
    public static function closeConnection(): void
    {
        if (self::$connection !== null) {
            self::$connection->close();
            self::$connection = null;
        }
    }
}
