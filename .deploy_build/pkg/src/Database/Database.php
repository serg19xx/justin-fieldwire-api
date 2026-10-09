<?php

namespace App\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Monolog\Logger;

class Database
{
    private static ?Connection $connection = null;
    private static ?Logger $logger = null;

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
            'host' => self::env('DB_HOST', 'localhost'),
            'port' => (int) self::env('DB_PORT', '3306'),
            'dbname' => self::env('DB_NAME', 'fieldwire_api'),
            'user' => self::env('DB_USERNAME', 'root'),
            'password' => self::env('DB_PASSWORD', ''),
            'charset' => self::env('DB_CHARSET', 'utf8mb4'),
            'options' => [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ],
        ];

        try {
            $connection = DriverManager::getConnection($config);
            
            if (self::$logger !== null) {
                self::$logger->info('Database connection established', [
                    'host' => $config['host'],
                    'port' => $config['port'],
                    'database' => $config['dbname']
                ]);
            }

            return $connection;
        } catch (Exception $e) {
            if (self::$logger !== null) {
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

    /** Dotenv may populate getenv/$_SERVER without $_ENV depending on php.ini variables_order. */
    private static function env(string $key, string $default = ''): string
    {
        if (array_key_exists($key, $_ENV) && $_ENV[$key] !== null && $_ENV[$key] !== '') {
            return (string) $_ENV[$key];
        }
        if (array_key_exists($key, $_SERVER) && $_SERVER[$key] !== null && $_SERVER[$key] !== '') {
            return (string) $_SERVER[$key];
        }
        $fromGetenv = getenv($key);
        if ($fromGetenv !== false && $fromGetenv !== '') {
            return (string) $fromGetenv;
        }
        return $default;
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
            if (self::$logger !== null) {
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

    /**
     * Get logger instance
     */
    public static function getLogger(): ?Logger
    {
        return self::$logger;
    }
}
