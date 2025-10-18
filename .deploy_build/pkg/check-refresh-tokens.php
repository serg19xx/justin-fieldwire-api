<?php
// Check refresh tokens in database

require_once 'vendor/autoload.php';

use App\Database\Database;
use Dotenv\Dotenv;

// Load environment variables from env.development
Dotenv::createImmutable(__DIR__, 'env.development')->load();

try {
    $connection = Database::getConnection();
    
    echo "=== Refresh Tokens in Database ===\n";
    
    $sql = "SELECT rt.*, u.email, u.first_name, u.last_name 
            FROM fw_refresh_tokens rt
            INNER JOIN fw_users u ON rt.user_id = u.id
            ORDER BY rt.created_at DESC
            LIMIT 10";
    
    $result = $connection->executeQuery($sql);
    $tokens = $result->fetchAllAssociative();
    
    if (empty($tokens)) {
        echo "No refresh tokens found in database.\n";
    } else {
        foreach ($tokens as $token) {
            echo "Token ID: {$token['id']}\n";
            echo "User: {$token['email']} ({$token['first_name']} {$token['last_name']})\n";
            echo "Token: " . substr($token['token'], 0, 20) . "...\n";
            echo "Created: " . date('Y-m-d H:i:s', $token['created_at']) . "\n";
            echo "Expires: " . date('Y-m-d H:i:s', $token['expires_at']) . "\n";
            echo "Last Used: " . ($token['last_used_at'] ? date('Y-m-d H:i:s', $token['last_used_at']) : 'Never') . "\n";
            echo "Revoked: " . ($token['revoked'] ? 'Yes' : 'No') . "\n";
            if ($token['revoked']) {
                echo "Revoked At: " . date('Y-m-d H:i:s', $token['revoked_at']) . "\n";
            }
            echo "User Agent: " . substr($token['user_agent'] ?? 'N/A', 0, 50) . "\n";
            echo "IP: " . ($token['ip_address'] ?? 'N/A') . "\n";
            echo "---\n";
        }
    }
    
    echo "\n=== Clean up expired tokens ===\n";
    
    $cleanupSql = "DELETE FROM fw_refresh_tokens 
                   WHERE expires_at < ? 
                   OR (revoked = 1 AND revoked_at < ?)";
    
    $currentTime = time();
    $oldTime = $currentTime - (30 * 24 * 60 * 60); // 30 days ago
    
    $deleted = $connection->executeStatement($cleanupSql, [$currentTime, $oldTime]);
    
    echo "Cleaned up expired/old revoked tokens.\n";
    
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
