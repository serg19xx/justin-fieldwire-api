<?php

namespace App\Controllers;

use App\Database\Database;
use App\Services\TwilioService;
use App\Services\EmailService;
use Doctrine\DBAL\Exception;
use Flight;
use Monolog\Logger;

class ProfileController
{
    private Logger $logger;
    private TwilioService $twilioService;
    private EmailService $emailService;

    public function __construct(Logger $logger, TwilioService $twilioService, EmailService $emailService)
    {
        $this->logger = $logger;
        $this->twilioService = $twilioService;
        $this->emailService = $emailService;
    }

    /**
     * Get current user profile
     */
    public function getProfile(): void
    {
        try {
            $user = $this->getCurrentUser();
            if (!$user) {
                Flight::json([
                    'error_code' => 401,
                    'status' => 'error',
                    'message' => 'Unauthorized',
                    'data' => null
                ], 401);
                return;
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Profile retrieved successfully',
                'data' => [
                    'user' => [
                        'id' => $user['id'],
                        'email' => $user['email'],
                        'first_name' => $user['first_name'] ?? null,
                        'last_name' => $user['last_name'] ?? null,
                        'name' => ($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''),
                        'phone' => $user['phone'] ?? null,
                        'user_type' => $user['user_type'] ?? null,
                        'job_title' => $user['job_title'] ?? null,
                        'status' => $user['status'] ?? 'active',
                        'additional_info' => $user['additional_info'] ?? null,
                        'avatar_url' => $user['avatar_url'] ? 'http://localhost:8000/api/v1/avatar?file=' . basename($user['avatar_url']) : null,
                        'two_factor_enabled' => (bool)($user['two_factor_enabled'] ?? false),
                        'last_login' => $user['last_login'] ?? null,
                        'created_at' => $user['created_at'] ?? null,
                        'updated_at' => $user['updated_at'] ?? null
                    ]
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error retrieving profile', [
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Internal server error',
                'data' => null
            ], 500);
        }
    }

    /**
     * Update user profile
     */
    public function updateProfile(): void
    {
        try {
            $user = $this->getCurrentUser();
            if (!$user) {
                Flight::json([
                    'error_code' => 401,
                    'status' => 'error',
                    'message' => 'Unauthorized',
                    'data' => null
                ], 401);
                return;
            }

            $requestBody = Flight::request()->getBody();
            $data = json_decode($requestBody, true);

            // Validate input
            if (!$this->validateProfileData($data)) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Invalid input data',
                    'data' => null
                ], 400);
                return;
            }

            // Update user profile
            $updatedUser = $this->updateUserProfile($user['id'], $data);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Profile updated successfully',
                'data' => [
                    'user' => [
                        'id' => $updatedUser['id'],
                        'email' => $updatedUser['email'],
                        'first_name' => $updatedUser['first_name'] ?? null,
                        'last_name' => $updatedUser['last_name'] ?? null,
                        'name' => ($updatedUser['first_name'] ?? '') . ' ' . ($updatedUser['last_name'] ?? ''),
                        'phone' => $updatedUser['phone'] ?? null,
                        'user_type' => $updatedUser['user_type'] ?? null,
                        'job_title' => $updatedUser['job_title'] ?? null,
                        'status' => $updatedUser['status'] ?? 'active',
                        'additional_info' => $updatedUser['additional_info'] ?? null,
                        'avatar_url' => $updatedUser['avatar_url'] ? 'http://localhost:8000/api/v1/avatar?file=' . basename($updatedUser['avatar_url']) : null,
                        'two_factor_enabled' => (bool)($updatedUser['two_factor_enabled'] ?? false),
                        'updated_at' => $updatedUser['updated_at'] ?? null
                    ]
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error updating profile', [
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Internal server error',
                'data' => null
            ], 500);
        }
    }

    /**
     * Upload avatar
     */
    public function uploadAvatar(): void
    {
        try {
            $this->logger->info('Avatar upload request started');
            
            $user = $this->getCurrentUser();
            if (!$user) {
                $this->logger->warning('Unauthorized avatar upload attempt');
                Flight::json([
                    'error_code' => 401,
                    'status' => 'error',
                    'message' => 'Unauthorized',
                    'data' => null
                ], 401);
                return;
            }

            $this->logger->info('User authenticated for avatar upload', [
                'user_id' => $user['id'],
                'email' => $user['email']
            ]);

            // Check if file was uploaded
            if (!isset($_FILES['avatar'])) {
                $this->logger->warning('No avatar file in request');
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'No file uploaded',
                    'data' => null
                ], 400);
                return;
            }

            if ($_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
                $this->logger->warning('File upload error', [
                    'error_code' => $_FILES['avatar']['error']
                ]);
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Upload error: ' . $_FILES['avatar']['error'],
                    'data' => null
                ], 400);
                return;
            }

            $file = $_FILES['avatar'];
            
            $this->logger->info('File received', [
                'filename' => $file['name'],
                'size' => $file['size'],
                'type' => $file['type'],
                'tmp_name' => $file['tmp_name']
            ]);
            
            // Validate file
            if (!$this->validateAvatarFile($file)) {
                $this->logger->warning('File validation failed');
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Invalid file format or size',
                    'data' => null
                ], 400);
                return;
            }

            // Upload and save avatar
            $avatarUrl = $this->saveAvatar($file, $user['id']);

            $this->logger->info('Avatar saved', [
                'avatar_url' => $avatarUrl
            ]);

            // Update user avatar in database
            $this->updateUserAvatar($user['id'], $avatarUrl);

            $this->logger->info('Avatar upload completed successfully');

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Avatar uploaded successfully',
                'data' => [
                    'avatar_url' => $avatarUrl,
                    'full_url' => 'http://localhost:8000/api/v1/avatar?file=' . basename($avatarUrl)
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error uploading avatar', [
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Internal server error',
                'data' => null
            ], 500);
        }
    }

    /**
     * Enable 2FA for current user
     */
    public function enable2FA(): void
    {
        try {
            $user = $this->getCurrentUser();
            if (!$user) {
                Flight::json([
                    'error_code' => 401,
                    'status' => 'error',
                    'message' => 'Unauthorized',
                    'data' => null
                ], 401);
                return;
            }

            $requestBody = Flight::request()->getBody();
            $data = json_decode($requestBody, true);

            // Validate input
            if (!isset($data['delivery_method']) || !in_array($data['delivery_method'], ['sms', 'email'])) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Invalid delivery method. Use "sms" or "email".',
                    'data' => null
                ], 400);
                return;
            }

            $deliveryMethod = $data['delivery_method'];

            // Check if user has required contact method
            if ($deliveryMethod === 'sms' && empty($user['phone'])) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Phone number required for SMS 2FA',
                    'data' => null
                ], 400);
                return;
            }

            // Generate verification code
            $code = $this->twilioService->generateVerificationCode();
            $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 minutes

            // Save verification code to database
            $this->saveVerificationCode($user['id'], $code, $expiresAt, $deliveryMethod);

            // Send verification code
            if ($deliveryMethod === 'sms') {
                $this->twilioService->sendSMS($user['phone'], "Your FieldWire verification code is: {$code}");
            } else {
                $this->emailService->sendEmail(
                    $user['email'],
                    'FieldWire 2FA Verification Code',
                    "Your verification code is: {$code}"
                );
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => '2FA verification code sent',
                'data' => [
                    'delivery_method' => $deliveryMethod,
                    'expires_at' => $expiresAt
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error enabling 2FA', [
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Internal server error',
                'data' => null
            ], 500);
        }
    }

    /**
     * Disable 2FA for current user
     */
    public function disable2FA(): void
    {
        try {
            $user = $this->getCurrentUser();
            if (!$user) {
                Flight::json([
                    'error_code' => 401,
                    'status' => 'error',
                    'message' => 'Unauthorized',
                    'data' => null
                ], 401);
                return;
            }

            $requestBody = Flight::request()->getBody();
            $data = json_decode($requestBody, true);

            // Validate input
            if (!isset($data['verification_code']) || empty($data['verification_code'])) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Verification code is required',
                    'data' => null
                ], 400);
                return;
            }

            $verificationCode = $data['verification_code'];

            // Verify code
            if (!$this->verifyCode($user['id'], $verificationCode)) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Invalid verification code',
                    'data' => null
                ], 400);
                return;
            }

            // Disable 2FA
            $this->update2FAStatus($user['id'], false);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => '2FA disabled successfully',
                'data' => null
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error disabling 2FA', [
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Internal server error',
                'data' => null
            ], 500);
        }
    }

    /**
     * Serve avatar file directly
     */
    public function serveAvatar(): void
    {
        try {
            $filename = Flight::request()->query->file ?? null;
            
            if (!$filename) {
                http_response_code(404);
                echo 'Avatar file not specified';
                return;
            }

            // Validate filename to prevent directory traversal
            if (preg_match('/[\/\\\\]/', $filename)) {
                http_response_code(400);
                echo 'Invalid filename';
                return;
            }

            $filepath = __DIR__ . '/../../public/uploads/avatars/' . $filename;
            
            $this->logger->info('Serving avatar file', [
                'filename' => $filename,
                'filepath' => $filepath,
                'file_exists' => file_exists($filepath)
            ]);
            
            if (!file_exists($filepath)) {
                $this->logger->warning('Avatar file not found', [
                    'filepath' => $filepath
                ]);
                http_response_code(404);
                echo 'Avatar not found';
                return;
            }

            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            
            // Set appropriate content type
            switch (strtolower($extension)) {
                case 'jpg':
                case 'jpeg':
                    header('Content-Type: image/jpeg');
                    break;
                case 'png':
                    header('Content-Type: image/png');
                    break;
                case 'gif':
                    header('Content-Type: image/gif');
                    break;
                default:
                    header('Content-Type: application/octet-stream');
            }
            
            // Set cache headers
            header('Cache-Control: public, max-age=31536000'); // 1 year
            header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + 31536000));
            
            // Output file
            readfile($filepath);
            exit;

        } catch (Exception $e) {
            $this->logger->error('Error serving avatar', [
                'error' => $e->getMessage()
            ]);

            http_response_code(500);
            echo 'Internal server error';
        }
    }

    /**
     * Get user avatar
     */
    public function getAvatar(): void
    {
        try {
            $userId = Flight::request()->query->user_id ?? null;
            
            if (!$userId) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'User ID is required',
                    'data' => null
                ], 400);
                return;
            }

            $user = $this->getUserById($userId);
            if (!$user) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'User not found',
                    'data' => null
                ], 404);
                return;
            }

            if (empty($user['avatar_url'])) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Avatar not found',
                    'data' => null
                ], 404);
                return;
            }

            // Return avatar URL
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Avatar retrieved successfully',
                'data' => [
                    'avatar_url' => $user['avatar_url'],
                    'full_url' => 'http://localhost:8000/api/v1/avatar?file=' . basename($user['avatar_url'])
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error retrieving avatar', [
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Internal server error',
                'data' => null
            ], 500);
        }
    }

    /**
     * Get current user from JWT token
     */
    private function getCurrentUser(): ?array
    {
        $headers = getallheaders();
        $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (empty($authorization) || !str_starts_with($authorization, 'Bearer ')) {
            return null;
        }

        $token = substr($authorization, 7);
        
        try {
            $payload = $this->decodeJWT($token);
            if (!$payload) {
                return null;
            }

            return $this->getUserById($payload['user_id']);

        } catch (Exception $e) {
            $this->logger->error('Error decoding JWT token', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Validate profile update data
     */
    private function validateProfileData(?array $data): bool
    {
        if (!$data || !is_array($data)) {
            return false;
        }

        // At least one field should be provided
        $allowedFields = ['first_name', 'last_name', 'phone', 'job_title', 'additional_info'];
        $hasValidField = false;

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $hasValidField = true;
                break;
            }
        }

        if (!$hasValidField) {
            return false;
        }

        // Validate email if provided
        if (isset($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return true;
    }

    /**
     * Validate avatar file
     */
    private function validateAvatarFile(array $file): bool
    {
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
        $maxSize = 5 * 1024 * 1024; // 5MB

        // Check file size
        if ($file['size'] > $maxSize) {
            $this->logger->warning('File too large', [
                'size' => $file['size'],
                'max_size' => $maxSize
            ]);
            return false;
        }

        // Check MIME type
        if (in_array($file['type'], $allowedTypes)) {
            return true;
        }

        // Fallback: check file extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (in_array($extension, $allowedExtensions)) {
            $this->logger->info('Using extension validation for file', [
                'filename' => $file['name'],
                'extension' => $extension,
                'mime_type' => $file['type']
            ]);
            return true;
        }

        // Additional check: try to get real MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $realMimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (in_array($realMimeType, $allowedTypes)) {
            $this->logger->info('Using real MIME type validation', [
                'filename' => $file['name'],
                'real_mime_type' => $realMimeType,
                'reported_mime_type' => $file['type']
            ]);
            return true;
        }

        $this->logger->warning('File validation failed', [
            'filename' => $file['name'],
            'mime_type' => $file['type'],
            'real_mime_type' => $realMimeType ?? 'unknown',
            'extension' => $extension,
            'size' => $file['size']
        ]);

        return false;
    }

    /**
     * Save avatar file
     */
    private function saveAvatar(array $file, int $userId): string
    {
        $uploadDir = __DIR__ . '/../../public/uploads/avatars/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = "user_{$userId}_" . time() . ".{$extension}";
        $filepath = $uploadDir . $filename;

        $this->logger->info('Saving avatar file', [
            'upload_dir' => $uploadDir,
            'filename' => $filename,
            'filepath' => $filepath,
            'tmp_name' => $file['tmp_name']
        ]);

        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            $this->logger->error('Failed to move uploaded file', [
                'tmp_name' => $file['tmp_name'],
                'filepath' => $filepath,
                'upload_error' => error_get_last()
            ]);
            throw new Exception('Failed to save avatar file');
        }

        $this->logger->info('Avatar file saved successfully', [
            'filepath' => $filepath,
            'file_exists' => file_exists($filepath),
            'file_size' => filesize($filepath)
        ]);

        return "/uploads/avatars/{$filename}";
    }

    /**
     * Update user profile in database
     */
    private function updateUserProfile(int $userId, array $data): array
    {
        $connection = Database::getConnection();

        $updateFields = [];
        $params = [];

        $allowedFields = ['first_name', 'last_name', 'phone', 'job_title', 'additional_info'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateFields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($updateFields)) {
            throw new Exception('No valid fields to update');
        }

        $updateFields[] = 'updated_at = NOW()';
        $params[] = $userId;

        $sql = "UPDATE fw_users SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $connection->executeStatement($sql, $params);

        return $this->getUserById($userId);
    }

    /**
     * Update user avatar in database
     */
    private function updateUserAvatar(int $userId, string $avatarUrl): void
    {
        $connection = Database::getConnection();
        
        $sql = "UPDATE fw_users SET avatar_url = ?, updated_at = NOW() WHERE id = ?";
        $connection->executeStatement($sql, [$avatarUrl, $userId]);
    }

    /**
     * Get user by ID
     */
    private function getUserById(int $userId): ?array
    {
        $connection = Database::getConnection();
        
        $sql = 'SELECT id, email, first_name, last_name, phone, user_type, job_title, status, 
                       additional_info, avatar_url, two_factor_enabled, last_login, created_at, updated_at 
                FROM fw_users 
                WHERE id = ? AND status = "active"';
        
        $result = $connection->executeQuery($sql, [$userId]);
        $user = $result->fetchAssociative();

        return $user ?: null;
    }

    /**
     * Save verification code to database
     */
    private function saveVerificationCode(int $userId, string $code, string $expiresAt, string $deliveryMethod): void
    {
        $connection = Database::getConnection();
        
        // Delete existing codes for this user
        $sql = "DELETE FROM two_factor_codes WHERE user_id = ?";
        $connection->executeStatement($sql, [$userId]);
        
        // Insert new code
        $sql = "INSERT INTO two_factor_codes (user_id, code, expires_at, delivery_method, created_at) 
                VALUES (?, ?, ?, ?, NOW())";
        $connection->executeStatement($sql, [$userId, $code, $expiresAt, $deliveryMethod]);
    }

    /**
     * Verify 2FA code
     */
    private function verifyCode(int $userId, string $code): bool
    {
        $connection = Database::getConnection();
        
        $sql = "SELECT * FROM two_factor_codes 
                WHERE user_id = ? AND code = ? AND expires_at > NOW() 
                ORDER BY created_at DESC LIMIT 1";
        
        $result = $connection->executeQuery($sql, [$userId, $code]);
        $verificationCode = $result->fetchAssociative();

        if (!$verificationCode) {
            return false;
        }

        // Delete used code
        $sql = "DELETE FROM two_factor_codes WHERE id = ?";
        $connection->executeStatement($sql, [$verificationCode['id']]);

        return true;
    }

    /**
     * Update 2FA status
     */
    private function update2FAStatus(int $userId, bool $enabled): void
    {
        $connection = Database::getConnection();
        
        $sql = "UPDATE fw_users SET two_factor_enabled = ?, updated_at = NOW() WHERE id = ?";
        $connection->executeStatement($sql, [$enabled ? 1 : 0, $userId]);
    }

    /**
     * Decode JWT token
     */
    private function decodeJWT(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
        
        if (!$payload || !isset($payload['exp']) || $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }
}
