<?php

namespace App\Controllers;

use App\Database\Database;
use App\Services\TwilioService;
use App\Services\EmailService;
use Doctrine\DBAL\Exception;
use Flight;
use Monolog\Logger;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Two-Factor",
 *     description="Two-factor authentication management"
 * )
 */
class TwoFactorController
{
    private Logger $logger;
    private TwilioService $twilioService;
    private EmailService $emailService;

    public function __construct(Logger $logger, TwilioService $twilioService, EmailService $emailService)
    {
        $this->logger = $logger;
        $this->twilioService = $twilioService;
        $this->emailService = $emailService;
        
        // Direct file logging for debugging
        file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - TwoFactorController constructor called' . PHP_EOL, FILE_APPEND);
    }

    /**
     * @OA\Post(
     *     path="/api/v1/2fa/send-code",
     *     summary="Send 2FA verification code",
     *     description="Send verification code via SMS or email",
     *     tags={"Two-Factor"},
     *     @OA\RequestBody(
     *         required=true,
     *         description="2FA code request",
     *         @OA\JsonContent(
     *             required={"email", "delivery_method"},
     *             @OA\Property(property="email", type="string", format="email", example="user@example.com"),
     *             @OA\Property(property="delivery_method", type="string", enum={"sms", "email"}, example="sms")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Code sent successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Verification code sent successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="delivery_method", type="string", example="sms"),
     *                 @OA\Property(property="expires_at", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request - validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=400),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Invalid input data")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="User not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=404),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="User not found")
     *         )
     *     )
     * )
     */
    public function sendCode(): void
    {
        // Direct file logging for debugging
        file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - TwoFactorController::sendCode() called' . PHP_EOL, FILE_APPEND);
        
        try {
            $requestBody = Flight::request()->getBody();
            $data = json_decode($requestBody, true);

            // Validate input
            if (!$this->validateSendCodeData($data)) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Invalid input data. Email and delivery_method are required.',
                    'data' => null
                ], 400);
                return;
            }

            $email = $data['email'];
            $deliveryMethod = $data['delivery_method'] ?? 'sms'; // Default to SMS

            // Validate delivery method
            if (!in_array($deliveryMethod, ['sms', 'email'])) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Invalid delivery method. Use "sms" or "email".',
                    'data' => null
                ], 400);
                return;
            }

            // Get user from database
            $user = $this->getUserByEmail($email);
            if (!$user) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'User not found',
                    'data' => null
                ], 404);
                return;
            }

            // Check if user has required contact method
            if ($deliveryMethod === 'sms' && empty($user['phone'])) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Phone number not found for this user',
                    'data' => null
                ], 400);
                return;
            }

            if ($deliveryMethod === 'email' && empty($user['email'])) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Email not found for this user',
                    'data' => null
                ], 400);
                return;
            }

            // Generate verification code
            $code = $this->twilioService->generateVerificationCode();
            $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 minutes

            // Store verification code in database
            $this->storeVerificationCode($user['id'], $code, $expiresAt);

            $codeSent = false;
            $contactInfo = '';

            if ($deliveryMethod === 'sms') {
                // Validate phone number
                if (!$this->twilioService->validatePhoneNumber($user['phone'])) {
                    Flight::json([
                        'error_code' => 400,
                        'status' => 'error',
                        'message' => 'Invalid phone number format',
                        'data' => null
                    ], 400);
                    return;
                }

                // Send SMS
                file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - About to send SMS to ' . $user['phone'] . ' with code ' . $code . PHP_EOL, FILE_APPEND);
                $codeSent = $this->twilioService->sendVerificationCode($user['phone'], $code);
                $contactInfo = $this->maskPhoneNumber($user['phone']);
                
                file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - SMS send result: ' . ($codeSent ? 'SUCCESS' : 'FAILED') . PHP_EOL, FILE_APPEND);

            } elseif ($deliveryMethod === 'email') {
                // Send email
                $codeSent = $this->emailService->sendVerificationCode($user['email'], $code, $user['first_name'] ?? 'User');
                $contactInfo = $this->maskEmail($user['email']);
            }

            if (!$codeSent) {
                Flight::json([
                    'error_code' => 500,
                    'status' => 'error',
                    'message' => 'Failed to send verification code',
                    'data' => null
                ], 500);
                return;
            }

            $this->logger->info('2FA verification code sent', [
                'user_id' => $user['id'],
                'email' => $email,
                'delivery_method' => $deliveryMethod,
                'contact_info' => $contactInfo
            ]);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Verification code sent successfully',
                'data' => [
                    'user_id' => $user['id'],
                    'delivery_method' => $deliveryMethod,
                    'contact_info' => $contactInfo,
                    'expires_at' => $expiresAt
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Error sending 2FA code', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Internal server error',
                'data' => null,
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/2fa/verify-code",
     *     summary="Verify 2FA code",
     *     description="Verify the 2FA code sent to user",
     *     tags={"Two-Factor"},
     *     @OA\RequestBody(
     *         required=true,
     *         description="2FA code verification",
     *         @OA\JsonContent(
     *             required={"email", "code"},
     *             @OA\Property(property="email", type="string", format="email", example="user@example.com"),
     *             @OA\Property(property="code", type="string", example="123456")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Code verified successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Code verified successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="verified", type="boolean", example=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request - validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=400),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Invalid input data")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Invalid or expired code",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=401),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Invalid or expired code")
     *         )
     *     )
     * )
     */
    public function verifyCode(): void
    {
        try {
            $requestBody = Flight::request()->getBody();
            $data = json_decode($requestBody, true);

            // Validate input
            if (!$this->validateVerifyCodeData($data)) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Invalid input data. User ID and code are required.',
                    'data' => null
                ], 400);
                return;
            }

            $userId = $data['user_id'];
            $code = $data['code'];

            // Verify code
            $verification = $this->verifyStoredCode($userId, $code);

            if (!$verification) {
                Flight::json([
                    'error_code' => 401,
                    'status' => 'error',
                    'message' => 'Invalid or expired verification code',
                    'data' => null
                ], 401);
                return;
            }

            // Get user data
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

            // Generate JWT token
            $token = $this->generateToken($user);

            // Mark code as used
            $this->markCodeAsUsed($userId, $code);

            $this->logger->info('2FA verification successful', [
                'user_id' => $userId,
                'email' => $user['email']
            ]);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Verification successful',
                'data' => [
                    'user' => [
                        'id' => $user['id'],
                        'email' => $user['email'],
                        'first_name' => $user['first_name'],
                        'last_name' => $user['last_name'],
                        'name' => $user['first_name'] . ' ' . $user['last_name'],
                        'phone' => $user['phone'],
                        'user_type' => $user['user_type'],
                        'job_title' => $user['job_title'],
                        'status' => $user['status'],
                        'additional_info' => $user['additional_info'],
                        'avatar_url' => $user['avatar_url'],
                        'two_factor_enabled' => (bool)$user['two_factor_enabled'],
                        'last_login' => $user['last_login']
                    ],
                    'token' => $token,
                    'expires_at' => date('c', time() + 3600)
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Error verifying 2FA code', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Internal server error',
                'data' => null,
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Enable 2FA for user
     */
    public function enable2FA(): void
    {
        try {
            $requestBody = Flight::request()->getBody();
            $data = json_decode($requestBody, true);

            if (!isset($data['user_id']) || !isset($data['phone'])) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'User ID and phone number are required',
                    'data' => null
                ], 400);
                return;
            }

            $userId = $data['user_id'];
            $phone = $data['phone'];

            // Validate phone number
            if (!$this->twilioService->validatePhoneNumber($phone)) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Invalid phone number format',
                    'data' => null
                ], 400);
                return;
            }

            // Update user's phone and enable 2FA
            $this->updateUser2FA($userId, $phone, true);

            $this->logger->info('2FA enabled for user', [
                'user_id' => $userId,
                'phone' => $phone
            ]);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => '2FA enabled successfully',
                'data' => [
                    'user_id' => $userId,
                    'phone' => $this->maskPhoneNumber($phone),
                    'two_factor_enabled' => true
                ]
            ]);

        } catch (\Exception $e) {
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
     * Disable 2FA for user
     */
    public function disable2FA(): void
    {
        try {
            $requestBody = Flight::request()->getBody();
            $data = json_decode($requestBody, true);

            if (!isset($data['user_id'])) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'User ID is required',
                    'data' => null
                ], 400);
                return;
            }

            $userId = $data['user_id'];

            // Disable 2FA
            $this->updateUser2FA($userId, null, false);

            $this->logger->info('2FA disabled for user', [
                'user_id' => $userId
            ]);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => '2FA disabled successfully',
                'data' => [
                    'user_id' => $userId,
                    'two_factor_enabled' => false
                ]
            ]);

        } catch (\Exception $e) {
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

    // Private helper methods

    private function validateSendCodeData(?array $data): bool
    {
        return $data && 
               isset($data['email']) && 
               filter_var($data['email'], FILTER_VALIDATE_EMAIL) &&
               isset($data['delivery_method']) &&
               in_array($data['delivery_method'], ['sms', 'email']);
    }

    private function validateVerifyCodeData(?array $data): bool
    {
        return $data && isset($data['user_id']) && isset($data['code']) && strlen($data['code']) === 6;
    }

    private function getUserByEmail(string $email): ?array
    {
        try {
            $connection = Database::getConnection();
            $sql = 'SELECT * FROM fw_users WHERE email = ?';
            $result = $connection->executeQuery($sql, [$email]);
            $user = $result->fetchAssociative();
            return $user ?: null;
        } catch (Exception $e) {
            $this->logger->error('Database error getting user by email', [
                'error' => $e->getMessage(),
                'email' => $email
            ]);
            return null;
        }
    }

    private function getUserById(int $userId): ?array
    {
        try {
            $connection = Database::getConnection();
            $sql = 'SELECT * FROM fw_users WHERE id = ?';
            $result = $connection->executeQuery($sql, [$userId]);
            $user = $result->fetchAssociative();
            return $user ?: null;
        } catch (Exception $e) {
            $this->logger->error('Database error getting user by ID', [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
            return null;
        }
    }

    private function storeVerificationCode(int $userId, string $code, string $expiresAt): bool
    {
        try {
            $connection = Database::getConnection();
            
            // Delete any existing codes for this user
            $connection->executeStatement('DELETE FROM two_factor_codes WHERE user_id = ?', [$userId]);
            
            // Insert new code
            $connection->executeStatement(
                'INSERT INTO two_factor_codes (user_id, code, expires_at, created_at) VALUES (?, ?, ?, NOW())',
                [$userId, $code, $expiresAt]
            );
            
            return true;
        } catch (Exception $e) {
            $this->logger->error('Database error storing verification code', [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
            return false;
        }
    }

    private function verifyStoredCode(int $userId, string $code): bool
    {
        try {
            $connection = Database::getConnection();
            $sql = 'SELECT * FROM two_factor_codes WHERE user_id = ? AND code = ? AND expires_at > NOW() AND used = 0';
            $result = $connection->executeQuery($sql, [$userId, $code]);
            return $result->fetchAssociative() !== false;
        } catch (Exception $e) {
            $this->logger->error('Database error verifying code', [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
            return false;
        }
    }

    private function markCodeAsUsed(int $userId, string $code): bool
    {
        try {
            $connection = Database::getConnection();
            $connection->executeStatement(
                'UPDATE two_factor_codes SET used = 1, used_at = NOW() WHERE user_id = ? AND code = ?',
                [$userId, $code]
            );
            return true;
        } catch (Exception $e) {
            $this->logger->error('Database error marking code as used', [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
            return false;
        }
    }

    private function updateUser2FA(int $userId, ?string $phone, bool $enabled): bool
    {
        try {
            $connection = Database::getConnection();
            $connection->executeStatement(
                'UPDATE fw_users SET phone = ?, two_factor_enabled = ?, updated_at = NOW() WHERE id = ?',
                [$phone, $enabled ? 1 : 0, $userId]
            );
            return true;
        } catch (Exception $e) {
            $this->logger->error('Database error updating user 2FA', [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
            return false;
        }
    }

    private function generateToken(array $user): string
    {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode([
            'user_id' => $user['id'],
            'email' => $user['email'],
            'name' => $user['first_name'] . ' ' . $user['last_name'],
            'user_type' => $user['user_type'],
            'iat' => time(),
            'exp' => time() + 3600
        ]);

        $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));

        $secret = $_ENV['JWT_SECRET'] ?? 'your-secret-key-change-in-production';
        $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, $secret, true);
        $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

        return $base64Header . "." . $base64Payload . "." . $base64Signature;
    }



    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return $email;
        }

        $username = $parts[0];
        $domain = $parts[1];

        if (strlen($username) <= 2) {
            $maskedUsername = $username;
        } else {
            $maskedUsername = substr($username, 0, 1) . '***' . substr($username, -1);
        }

        return $maskedUsername . '@' . $domain;
    }

    private function maskPhoneNumber(string $phone): string
    {
        if (strlen($phone) <= 4) {
            return $phone;
        }
        
        $masked = substr($phone, 0, -4) . '****';
        return $masked;
    }

    /**
     * Toggle 2FA status for user
     */
    public function toggle2FA(): void
    {
        try {
            $requestBody = Flight::request()->getBody();
            $data = json_decode($requestBody, true);

            if (!isset($data['enabled']) || !in_array($data['enabled'], [0, 1])) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Invalid input. "enabled" field must be 0 or 1',
                    'data' => null
                ], 400);
                return;
            }

            $enabled = (bool)$data['enabled'];
            $userId = $data['user_id'] ?? null;

            if (!$userId) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'User ID is required',
                    'data' => null
                ], 400);
                return;
            }

            // Update user's 2FA status
            $this->updateUser2FA($userId, null, $enabled);

            $this->logger->info('2FA status toggled for user', [
                'user_id' => $userId,
                'enabled' => $enabled
            ]);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => $enabled ? '2FA enabled successfully' : '2FA disabled successfully',
                'data' => [
                    'user_id' => $userId,
                    'two_factor_enabled' => $enabled
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Error toggling 2FA', [
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
}
