<?php

/**
 * Database Setup Script for FieldWire API
 * 
 * This script creates the database and tables for the FieldWire API.
 * Run this script after setting up your MySQL server and updating the .env file.
 */

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

use App\Database\Database;
use Doctrine\DBAL\Connection;

echo "🚀 FieldWire API - Database Setup\n";
echo "================================\n\n";

try {
    // Test database connection
    echo "📡 Testing database connection...\n";
    
    if (!Database::testConnection()) {
        echo "❌ Failed to connect to database!\n";
        echo "Please check your database configuration in .env file:\n";
        echo "- DB_HOST: " . ($_ENV['DB_HOST'] ?? 'not set') . "\n";
        echo "- DB_PORT: " . ($_ENV['DB_PORT'] ?? 'not set') . "\n";
        echo "- DB_NAME: " . ($_ENV['DB_NAME'] ?? 'not set') . "\n";
        echo "- DB_USERNAME: " . ($_ENV['DB_USERNAME'] ?? 'not set') . "\n";
        exit(1);
    }
    
    echo "✅ Database connection successful!\n\n";
    
    // Get database connection
    $connection = Database::getConnection();
    
    // Create tables
    echo "📋 Creating database tables...\n";
    
    // Example table creation (you can add more tables here)
    $tables = [
        'users' => "
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                uuid CHAR(36) NOT NULL UNIQUE,
                email VARCHAR(255) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100) NOT NULL,
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_email (email),
                INDEX idx_uuid (uuid),
                INDEX idx_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",
        
        'api_logs' => "
            CREATE TABLE IF NOT EXISTS api_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                method VARCHAR(10) NOT NULL,
                path VARCHAR(255) NOT NULL,
                status_code INT NOT NULL,
                response_time FLOAT NOT NULL,
                user_agent TEXT,
                ip_address VARCHAR(45),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_method (method),
                INDEX idx_path (path),
                INDEX idx_status (status_code),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        "
    ];
    
    foreach ($tables as $tableName => $sql) {
        try {
            $connection->executeStatement($sql);
            echo "✅ Table '{$tableName}' created successfully\n";
        } catch (\Exception $e) {
            echo "❌ Failed to create table '{$tableName}': " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n🎉 Database setup completed successfully!\n";
    echo "You can now start the API server with: ./scripts/start-server.sh\n";
    
} catch (\Exception $e) {
    echo "❌ Database setup failed: " . $e->getMessage() . "\n";
    exit(1);
}
