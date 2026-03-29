<?php

namespace App\Controllers;

use App\Database\Database;
use App\Support\UserRoleFields;
use App\Services\TwilioService;
use App\Services\EmailService;
use App\Services\EventLoggingService;
use Doctrine\DBAL\Exception;
use Flight;
use Monolog\Logger;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Profile",
 *     description="User profile management endpoints"
 * )
 */
class ProfileController
{
    private Logger $logger;
    private TwilioService $twilioService;
    private EmailService $emailService;
    private EventLoggingService $eventLoggingService;

    public function __construct(Logger $logger, TwilioService $twilioService, EmailService $emailService)
    {
        $this->logger = $logger;
        $this->twilioService = $twilioService;
        $this->emailService = $emailService;
        $this->eventLoggingService = new EventLoggingService($logger);
    }

    /**
     * Проверить аутентификацию пользователя
     */
    private function checkAuth(): bool
    {
        $currentUser = Flight::get('current_user');
        if (!$currentUser) {
            Flight::json([
                'error_code' => 401,
                'status' => 'error',
                'message' => 'Unauthorized - Token required',
                'data' => null
            ], 401);
            return false;
        }
        return true;
    }

    /**
     * @OA\Get(
     *     path="/profile",
     *     summary="Get user profile",
     *     description="Retrieve current user profile information",
     *     tags={"Profile"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Profile retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Profile retrieved successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="user", type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="email", type="string", example="user@example.com"),
     *                     @OA\Property(property="first_name", type="string", example="John"),
     *                     @OA\Property(property="last_name", type="string", example="Doe"),
     *                     @OA\Property(property="name", type="string", example="John Doe"),
     *                     @OA\Property(property="phone", type="string", example="+1234567890"),
     *                     @OA\Property(property="job_title", type="string", example="Field Worker"),
     *                     @OA\Property(property="role_id", type="integer", nullable=true, example=12),
     *                     @OA\Property(property="role_code", type="string", example="worker"),
     *                     @OA\Property(property="role_name", type="string", example="Worker"),
     *                     @OA\Property(property="role_category", type="string", enum={"global","project","task"}, example="task"),
     *                     @OA\Property(property="role_description", type="string", nullable=true),
     *                     @OA\Property(property="status", type="string", example="active"),
     *                     @OA\Property(property="avatar_url", type="string", example="http://localhost:8000/api/v1/avatar?file=avatar.png"),
     *                     @OA\Property(property="full_img_url", type="string", example="http://localhost:8000/api/v1/full-image?file=full_image.png"),
     *                     @OA\Property(property="two_factor_enabled", type="boolean", example=true),
     *                     @OA\Property(property="last_login", type="string", format="date-time"),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - invalid or missing token",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=401),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Unauthorized"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=500),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Internal server error"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     )
     * )
     */
    public function getProfile(): void
    {
        // Проверка токена
        try {
            $user = Flight::get('current_user');
            
            // Get fresh user data from database to include updated fields
            $connection = Database::getConnection();
            $sql = 'SELECT * FROM fw_v_users WHERE id = ?';
            $freshUser = $connection->executeQuery($sql, [$user['id']])->fetchAssociative();
            
            if (!$freshUser) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'User not found',
                    'data' => null
                ], 404);
                return;
            }
            
            // Use fresh data instead of cached current_user
            $user = $freshUser;

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
                        'job_title' => $user['job_title'] ?? null,
                        ...UserRoleFields::fromUserRow($user),
                        'status' => (bool)$user['status'],
                        'status_changed_at' => $user['status_changed_at'] ?? null,
                        'status_end_at' => $user['status_end_at'] ?? null,
                        'status_reason' => $user['status_reason'] ?? null,
                        'status_details' => $user['status_details'] ?? null,
                        'additional_info' => $user['additional_info'] ?? null,
                        'avatar_url' => $user['avatar_url'] ?: null,
                        'full_img_url' => isset($user['full_img_url']) ? $user['full_img_url'] : null,
                        'two_factor_enabled' => (bool)($user['two_factor_enabled'] ?? false),
                        'last_login' => $user['last_login'] ?? null,
                        'dob' => $user['dob'] ?? null,
                        'gender' => $user['gender'] ?? null,
                        'nationality' => $user['nationality'] ?? null,
                        'country_of_origin' => $user['country_of_origin'] ?? null,
                        'workforce_group' => $user['workforce_group'] ?? null,
                        'city' => $user['city'] ?? null,
                        'emergency' => $this->getEmergencyData($user['id']),
                        'languages' => $this->getUserLanguages($user['id']),
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
     * @OA\Put(
     *     path="/profile",
     *     summary="Update user profile",
     *     description="Update current user profile information",
     *     tags={"Profile"},
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=false,
     *         description="Profile update data",
     *         @OA\JsonContent(
     *             @OA\Property(property="first_name", type="string", example="John", description="User first name"),
     *             @OA\Property(property="last_name", type="string", example="Doe", description="User last name"),
     *             @OA\Property(property="phone", type="string", example="+1234567890", description="User phone number"),
     *             @OA\Property(property="job_title", type="string", example="Field Worker", description="User job title"),
     *             @OA\Property(property="additional_info", type="string", example="Additional information", description="Additional user information"),
     *             @OA\Property(property="dob", type="string", format="date", example="1990-01-15", description="Date of birth"),
     *             @OA\Property(property="gender", type="string", enum={"Male", "Female"}, example="Male", description="Gender"),
     *             @OA\Property(property="nationality", type="string", example="Canadian", description="Nationality"),
     *             @OA\Property(property="country_of_origin", type="string", example="Canada", description="Country of origin"),
     *             @OA\Property(property="workforce_group", type="string", example="Construction", description="Workforce group")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Profile updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Profile updated successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="user", type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="email", type="string", example="user@example.com"),
     *                     @OA\Property(property="first_name", type="string", example="John"),
     *                     @OA\Property(property="last_name", type="string", example="Doe"),
     *                     @OA\Property(property="phone", type="string", example="+1234567890"),
     *                     @OA\Property(property="job_title", type="string", example="Field Worker"),
     *                     @OA\Property(property="dob", type="string", format="date", example="1990-01-15"),
     *                     @OA\Property(property="gender", type="string", enum={"Male", "Female"}, example="Male"),
     *                     @OA\Property(property="nationality", type="string", example="Canadian"),
     *                     @OA\Property(property="country_of_origin", type="string", example="Canada"),
     *                     @OA\Property(property="workforce_group", type="string", example="Construction"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request - validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=400),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Validation failed"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - invalid or missing token",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=401),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Unauthorized"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     )
     * )
     */
    public function updateProfile(): void
    {
        // Проверка токена
        try {
            $user = Flight::get('current_user');

            $requestBody = Flight::request()->getBody();
            $data = json_decode($requestBody, true);

            // Log incoming data for debugging
            $this->logger->info('Profile update request data', [
                'user_id' => $user['id'],
                'request_data' => $data
            ]);

            // Map frontend field names to database field names
            if (isset($data['birth_date'])) {
                $data['dob'] = $data['birth_date'];
                unset($data['birth_date']);
            }

            // Handle languages synchronization
            $languages = null;
            if (isset($data['languages']) && is_array($data['languages'])) {
                $languages = $data['languages'];
                unset($data['languages']); // Remove from profile data
            }

            // Validate input (only if there are profile fields to update)
            if (!empty($data) && !$this->validateProfileData($data)) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Invalid input data',
                    'data' => null
                ], 400);
                return;
            }

            // Get current user data before update for logging
            $currentUserData = $this->getUserById($user['id']);
            $beforeData = [];
            if ($currentUserData) {
                $beforeData = [
                    'first_name' => $currentUserData['first_name'] ?? null,
                    'last_name' => $currentUserData['last_name'] ?? null,
                    'phone' => $currentUserData['phone'] ?? null,
                    'dob' => $currentUserData['dob'] ?? null,
                    'gender' => $currentUserData['gender'] ?? null,
                    'nationality' => $currentUserData['nationality'] ?? null,
                    'country_of_origin' => $currentUserData['country_of_origin'] ?? null,
                    'workforce_group' => $currentUserData['workforce_group'] ?? null,
                    'city' => $currentUserData['city'] ?? null,
                    'job_title' => $currentUserData['job_title'] ?? null
                ];
            }

            // Update user profile
            $updatedUser = $this->updateUserProfile($user['id'], $data);
            
            // Log profile update event
            if (!empty($data)) {
                try {
                    $this->eventLoggingService->logSimple(
                        entityType: 'user',
                        entityId: $user['id'],
                        eventType: 'USER_PROFILE_UPDATED',
                        afterData: array_merge($beforeData, array_filter($data, function($value) {
                            return $value !== null && $value !== '';
                        })),
                        options: [
                            'actor_type' => 'user',
                            'actor_id' => $user['id'],
                            'before_data' => $beforeData,
                            'changed_fields' => array_keys($data),
                            'comment' => 'User profile updated',
                            'ip' => Flight::request()->ip,
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                            'severity' => 'important'
                        ]
                    );
                    
                    // Check if profile became complete after this update
                    $completenessCheck = $this->checkProfileCompleteness($user['id']);
                    if ($completenessCheck['is_complete']) {
                        // Check if profile was incomplete before by checking if any required fields were missing
                        $wasIncomplete = empty($beforeData['first_name']) || 
                                       empty($beforeData['last_name']) || 
                                       empty($beforeData['phone']) || 
                                       empty($beforeData['dob']) || 
                                       empty($beforeData['gender']);
                        
                        if ($wasIncomplete) {
                            $this->eventLoggingService->logSimple(
                                entityType: 'user',
                                entityId: $user['id'],
                                eventType: 'USER_PROFILE_BECAME_COMPLETE',
                                afterData: $completenessCheck,
                                options: [
                                    'actor_type' => 'user',
                                    'actor_id' => $user['id'],
                                    'comment' => 'User profile became complete and ready for activation',
                                    'ip' => Flight::request()->ip,
                                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                                    'severity' => 'important'
                                ]
                            );
                        }
                    }
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to log USER_PROFILE_UPDATED event', [
                        'error' => $e->getMessage(),
                        'user_id' => $user['id']
                    ]);
                }
            }

            // Synchronize languages if provided
            if ($languages !== null) {
                $this->synchronizeWorkerLanguages($user['id'], $languages);
            }

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
                        'job_title' => $updatedUser['job_title'] ?? null,
                        'status' => (bool)$updatedUser['status'],
                        'status_reason' => $updatedUser['status_reason'] ?? null,
                        'status_details' => $updatedUser['status_details'] ?? null,
                        'additional_info' => $updatedUser['additional_info'] ?? null,
                        'dob' => $updatedUser['dob'] ?? null,
                        'gender' => $updatedUser['gender'] ?? null,
                        'nationality' => $updatedUser['nationality'] ?? null,
                        'country_of_origin' => $updatedUser['country_of_origin'] ?? null,
                        'workforce_group' => $updatedUser['workforce_group'] ?? null,
                        'city' => $updatedUser['city'] ?? null,
                        'emergency' => $this->getEmergencyData($updatedUser['id']),
                        'languages' => $this->getUserLanguages($updatedUser['id']),
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
        // Проверка токена
        try {
            $this->logger->info('Avatar upload request started');
            
            $user = Flight::get('current_user');

            $this->logger->info('User authenticated for avatar upload', [
                'user_id' => $user['id'],
                'email' => $user['email']
            ]);

            // Check if files were uploaded
            if (!isset($_FILES['avatar']) && !isset($_FILES['full_image'])) {
                $this->logger->warning('No files uploaded');
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'No files uploaded',
                    'data' => null
                ], 400);
                return;
            }

            $avatarFile = null;
            $fullImageFile = null;

            // Process avatar file if provided
            if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $avatarFile = $_FILES['avatar'];
                $this->logger->info('Avatar file received', [
                    'filename' => $avatarFile['name'],
                    'size' => $avatarFile['size'],
                    'type' => $avatarFile['type']
                ]);
            }

            // Process full image file if provided
            if (isset($_FILES['full_image']) && $_FILES['full_image']['error'] === UPLOAD_ERR_OK) {
                $fullImageFile = $_FILES['full_image'];
                $this->logger->info('Full image file received', [
                    'filename' => $fullImageFile['name'],
                    'size' => $fullImageFile['size'],
                    'type' => $fullImageFile['type']
                ]);
            }

            // Check if at least one file is valid
            if (!$avatarFile && !$fullImageFile) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'No valid files uploaded',
                    'data' => null
                ], 400);
                return;
            }
            
            $avatarUrl = null;
            $fullImageUrl = null;

            // Get current user data to check for existing files
            $connection = Database::getConnection();
            $currentUser = $connection->executeQuery(
                'SELECT avatar_url, full_img_url FROM fw_users WHERE id = ?',
                [$user['id']]
            )->fetchAssociative();
            
            // Delete old files BEFORE saving new ones
            if ($avatarFile) {
                $this->deleteUserFilesByType($user['id'], 'avatar');
            }
            
            if ($fullImageFile) {
                $this->deleteUserFilesByType($user['id'], 'full');
            }

            // Process avatar file
            if ($avatarFile) {
                // Validate avatar file
                if (!$this->validateAvatarFile($avatarFile)) {
                    $this->logger->warning('Avatar file validation failed');
                    Flight::json([
                        'error_code' => 400,
                        'status' => 'error',
                        'message' => 'Invalid avatar file format or size',
                        'data' => null
                    ], 400);
                    return;
                }
                
                $avatarUrl = $this->saveAvatar($avatarFile, $user['id']);
            }

            // Process full image file
            if ($fullImageFile) {
                // Validate full image file
                if (!$this->validateImageFile($fullImageFile)) {
                    $this->logger->warning('Full image file validation failed');
                    Flight::json([
                        'error_code' => 400,
                        'status' => 'error',
                        'message' => 'Invalid full image file format or size',
                        'data' => null
                    ], 400);
                    return;
                }
                
                $fullImageUrl = $this->saveFullImage($fullImageFile, $user['id']);
            }

            $this->logger->info('Both images saved', [
                'avatar_url' => $avatarUrl,
                'full_image_url' => $fullImageUrl
            ]);

            // Update database with new URLs (only if files were uploaded)
            $avatarFullUrl = null;
            $fullImageFullUrl = null;
            
            if ($avatarUrl) {
                $avatarFullUrl = 'http://localhost:8000/api/v1/avatar?file=' . basename($avatarUrl);
            }
            
            if ($fullImageUrl) {
                $fullImageFullUrl = 'http://localhost:8000/api/v1/full-image?file=' . basename($fullImageUrl);
            }
            
            // Use new URLs if provided, otherwise keep existing ones
            $finalAvatarUrl = $avatarFullUrl ?: $currentUser['avatar_url'];
            $finalFullImageUrl = $fullImageFullUrl ?: $currentUser['full_img_url'];
            
            $this->updateUserImages($user['id'], $finalAvatarUrl, $finalFullImageUrl);

            $this->logger->info('Image upload completed successfully');

            // Always return both fields, using new URLs if provided, otherwise existing ones
            $responseData = [
                'avatar_url' => $finalAvatarUrl,
                'full_image_url' => $finalFullImageUrl
            ];
            
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Files uploaded successfully',
                'data' => $responseData
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
     * @OA\Put(
     *     path="/profile/work-status",
     *     summary="Update user work status",
     *     description="Update current user work status (active/inactive)",
     *     tags={"Profile"},
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"isActive"},
     *             @OA\Property(property="isActive", type="boolean", example=true, description="Whether user is active at work"),
     *             @OA\Property(property="inactive_reason", type="string", example="training", description="Reason for inactivity (optional)"),
     *             @OA\Property(property="inactive_reason_details", type="string", example="тшщотшр9ш", description="Additional details about inactivity (optional)"),
     *             @OA\Property(property="inactive_until", type="string", format="date", example="2025-10-14", description="End date for inactivity (optional)")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Work status updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Work status updated successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="work_status", type="object",
     *                     @OA\Property(property="isActive", type="boolean", example=true),
     *                     @OA\Property(property="inactive_reason", type="string", example="training"),
     *                     @OA\Property(property="inactive_reason_details", type="string", example="тшщотшр9ш"),
     *                     @OA\Property(property="inactive_until", type="string", format="date", example="2025-10-14"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request - invalid data",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=400),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Invalid data provided"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - invalid or missing token",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=400),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Unauthorized"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=500),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Internal server error"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     )
     * )
     */
    public function updateWorkStatus(): void
    {
        // Добавляем простую отладку
        file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - updateWorkStatus() called' . PHP_EOL, FILE_APPEND);
        
        // Проверка токена
        try {
            $user = Flight::get('current_user');

            $input = json_decode(Flight::request()->getBody(), true);
            
            if (!isset($input['isActive']) || !is_bool($input['isActive'])) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'isActive field is required and must be boolean',
                    'data' => null
                ], 400);
                return;
            }

            $status = $input['isActive'];
            $status_reason = !empty($input['inactive_reason']) ? $input['inactive_reason'] : null;
            $status_details = !empty($input['inactive_reason_details']) ? $input['inactive_reason_details'] : null;
            $status_end_at = !empty($input['inactive_until']) ? $input['inactive_until'] : null;

            // If trying to activate, check profile completeness first
            if ($status) {
                $completenessCheck = $this->checkProfileCompleteness($user['id']);
                if (!$completenessCheck['is_complete']) {
                    Flight::json([
                        'error_code' => 400,
                        'status' => 'error',
                        'message' => 'Cannot activate user: profile is incomplete',
                        'data' => [
                            'is_complete' => false,
                            'missing_fields' => $completenessCheck['missing_fields'],
                            'message' => $completenessCheck['message']
                        ]
                    ], 400);
                    return;
                }
            }

            // Update work status in database
            try {
                $result = $this->updateUserWorkStatus($user['id'], $status, $status_reason, $status_details, $status_end_at);
                
                if ($result) {
                    Flight::json([
                        'error_code' => 0,
                        'status' => 'success',
                        'message' => 'Work status updated successfully',
                        'data' => [
                            'work_status' => [
                                'isActive' => $status,
                                'inactive_reason' => $status_reason,
                                'inactive_reason_details' => $status_details,
                                'inactive_until' => $status_end_at,
                                'updated_at' => date('Y-m-d H:i:s')
                            ]
                        ]
                    ]);
                } else {
                    Flight::json([
                        'error_code' => 500,
                        'status' => 'error',
                        'message' => 'Failed to update work status',
                        'data' => null
                    ], 500);
                }
            } catch (\Exception $e) {
                $this->logger->error('Error in updateWorkStatus: ' . $e->getMessage(), [
                    'user_id' => $user['id'],
                    'error' => $e->getMessage()
                ]);
                
                // Check if it's a profile completeness error
                if (strpos($e->getMessage(), 'profile is incomplete') !== false) {
                    $completenessCheck = $this->checkProfileCompleteness($user['id']);
                    Flight::json([
                        'error_code' => 400,
                        'status' => 'error',
                        'message' => $e->getMessage(),
                        'data' => [
                            'is_complete' => false,
                            'missing_fields' => $completenessCheck['missing_fields'] ?? [],
                            'message' => $completenessCheck['message'] ?? 'Profile is incomplete'
                        ]
                    ], 400);
                } else {
                    Flight::json([
                        'error_code' => 500,
                        'status' => 'error',
                        'message' => 'Failed to update work status: ' . $e->getMessage(),
                        'data' => null
                    ], 500);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Error updating work status: ' . $e->getMessage());
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Internal server error',
                'data' => null
            ], 500);
        }
    }

    /**
     * Change user password from profile settings
     */
    public function changePassword(): void
    {
        // Проверка токена
        try {
            $user = Flight::get('current_user');

            $requestBody = Flight::request()->getBody();
            $data = json_decode($requestBody, true);

            $this->logger->info('Change password request', [
                'user_id' => $user['id']
            ]);

            // Validate input
            if (!isset($data['current_password']) || !isset($data['new_password'])) {
                Flight::json([
                    'status' => 'error',
                    'message' => 'Current password and new password are required'
                ], 400);
                return;
            }

            $currentPassword = $data['current_password'];
            $newPassword = $data['new_password'];

            // Validate new password strength
            if (strlen($newPassword) < 8) {
                Flight::json([
                    'status' => 'error',
                    'message' => 'New password must be at least 8 characters long'
                ], 400);
                return;
            }

            $connection = Database::getConnection();

            // Get current password hash
            $sql = "SELECT password_hash FROM fw_users WHERE id = ?";
            $result = $connection->executeQuery($sql, [$user['id']]);
            $userData = $result->fetchAssociative();

            if (!$userData) {
                Flight::json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
                return;
            }

            // Verify current password
            if (!password_verify($currentPassword, $userData['password_hash'])) {
                $this->logger->warning('Failed password change attempt - incorrect current password', [
                    'user_id' => $user['id'],
                    'ip' => Flight::request()->ip
                ]);

                Flight::json([
                    'status' => 'error',
                    'message' => 'Current password is incorrect'
                ], 400);
                return;
            }

            // Hash new password
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

            // Update password
            $updateSql = "UPDATE fw_users SET password_hash = ?, updated_at = NOW() WHERE id = ?";
            $connection->executeStatement($updateSql, [$hashedPassword, $user['id']]);

            $this->logger->info('Password changed successfully', [
                'user_id' => $user['id'],
                'ip' => Flight::request()->ip
            ]);

            Flight::json([
                'status' => 'success',
                'message' => 'Password changed successfully'
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error changing password', [
                'user_id' => $user['id'] ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            Flight::json([
                'status' => 'error',
                'message' => 'Failed to change password'
            ], 500);
        }
    }

    /**
     * Serve full image file directly
     */
    public function serveFullImage(): void
    {
        try {
            $filename = Flight::request()->query['file'] ?? null;
            
            if (!$filename) {
                echo 'Full image file not specified';
                return;
            }

            $filepath = __DIR__ . '/../../public/uploads/avatars/' . $filename;

            $this->logger->info('Serving full image file', [
                'filename' => $filename,
                'filepath' => $filepath,
                'file_exists' => file_exists($filepath)
            ]);

            if (!file_exists($filepath)) {
                $this->logger->warning('Full image file not found', [
                    'filename' => $filename,
                    'filepath' => $filepath
                ]);
                echo 'Full image not found';
                return;
            }

            // Set appropriate headers for image
            $mimeType = mime_content_type($filepath);
            header('Content-Type: ' . $mimeType);
            header('Content-Length: ' . filesize($filepath));
            header('Cache-Control: public, max-age=3600'); // Cache for 1 hour
            
            readfile($filepath);

        } catch (Exception $e) {
            $this->logger->error('Error serving full image', [
                'error' => $e->getMessage()
            ]);
            echo 'Error serving full image';
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
     * Get user full image
     */
    public function getFullImage(): void
    {
        // Проверка токена
        try {
            $user = Flight::get('current_user');
            $connection = Database::getConnection();

            $sql = 'SELECT full_img_url FROM fw_v_users WHERE id = ?';
            $user = $connection->executeQuery($sql, [$user['id']])->fetchAssociative();

            if (empty($user['full_img_url'])) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Full image not found',
                    'data' => null
                ], 404);
                return;
            }

            // Return full image URL
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Full image retrieved successfully',
                'data' => [
                    'full_image_url' => $user['full_img_url'],
                    'full_url' => 'http://localhost:8000/api/v1/full-image?file=' . basename($user['full_img_url'])
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error retrieving full image', [
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
            $this->logger->warning('No authorization header or invalid format');
            return null;
        }

        $token = substr($authorization, 7);
        
        try {
            $payload = $this->decodeJWT($token);
            if (!$payload) {
                $this->logger->warning('Failed to decode JWT token');
                return null;
            }

            $this->logger->info('JWT payload decoded', [
                'user_id' => $payload['user_id'] ?? 'not_set',
                'payload' => $payload
            ]);

            // Для work status используем метод без фильтра по статусу
            $user = $this->getUserByIdForWorkStatus($payload['user_id']);
            
            if (!$user) {
                $this->logger->warning('User not found by ID', [
                    'user_id' => $payload['user_id']
                ]);
            } else {
                $this->logger->info('User found', [
                    'user_id' => $user['id'],
                    'email' => $user['email'],
                    'status' => $user['status']
                ]);
            }

            return $user;

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
        $allowedFields = ['first_name', 'last_name', 'phone', 'job_title', 'status', 'status_reason', 'status_details', 'additional_info', 'dob', 'gender', 'nationality', 'country_of_origin', 'workforce_group', 'city'];
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
        $filename = "user_{$userId}_avatar_" . time() . ".{$extension}";
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

        $allowedFields = ['first_name', 'last_name', 'phone', 'job_title', 'status', 'status_reason', 'status_details', 'additional_info', 'dob', 'gender', 'nationality', 'country_of_origin', 'workforce_group', 'city'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $value = $data[$field];
                
                // Convert empty strings to NULL for certain fields
                if (in_array($field, ['phone', 'additional_info', 'nationality', 'country_of_origin', 'workforce_group', 'city', 'dob', 'gender']) && $value === '') {
                    $value = null;
                }
                
                $updateFields[] = "{$field} = ?";
                $params[] = $value;
                $this->logger->info('Adding field to fw_v_users', [
                    'field' => $field,
                    'value' => $value,
                    'original_value' => $data[$field]
                ]);
            }
        }

        if (empty($updateFields)) {
            // No fields to update, just return current user data
            return $this->getUserById($userId);
        }

        $updateFields[] = 'updated_at = NOW()';
        $params[] = $userId;

        $sql = "UPDATE fw_users SET " . implode(', ', $updateFields) . " WHERE id = ?";
        
        $this->logger->info('Executing SQL UPDATE', [
            'sql' => $sql,
            'params' => $params
        ]);
        
        $connection->executeStatement($sql, $params);

        return $this->getUserById($userId);
    }

    /**
     * Validate image file (for full images)
     */
    private function validateImageFile(array $file): bool
    {
        // Check file size (max 10MB for full images)
        $maxSize = 10 * 1024 * 1024; // 10MB
        if ($file['size'] > $maxSize) {
            $this->logger->warning('File too large', [
                'size' => $file['size'],
                'max_size' => $maxSize
            ]);
            return false;
        }

        // Check file type
        $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file['type'], $allowedTypes)) {
            $this->logger->warning('Invalid file type', [
                'type' => $file['type'],
                'allowed_types' => $allowedTypes
            ]);
            return false;
        }

        // Check file extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($extension, $allowedExtensions)) {
            $this->logger->warning('Invalid file extension', [
                'extension' => $extension,
                'allowed_extensions' => $allowedExtensions
            ]);
            return false;
        }

        return true;
    }

    /**
     * Save full image file
     */
    private function saveFullImage(array $file, int $userId): string
    {
        $uploadDir = __DIR__ . '/../../public/uploads/avatars/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = "user_{$userId}_full_" . time() . ".{$extension}";
        $filepath = $uploadDir . $filename;

        $this->logger->info('Saving full image file', [
            'upload_dir' => $uploadDir,
            'filename' => $filename,
            'filepath' => $filepath,
            'tmp_name' => $file['tmp_name']
        ]);

        if (!copy($file['tmp_name'], $filepath)) {
            $this->logger->error('Failed to copy uploaded file', [
                'tmp_name' => $file['tmp_name'],
                'filepath' => $filepath,
                'copy_error' => error_get_last()
            ]);
            throw new Exception('Failed to save full image file');
        }

        $this->logger->info('Full image file saved successfully', [
            'filepath' => $filepath,
            'file_exists' => file_exists($filepath),
            'file_size' => filesize($filepath)
        ]);

        return "/uploads/avatars/{$filename}";
    }

    /**
     * Update user images in database (both avatar and full image)
     */
    private function updateUserImages(int $userId, ?string $avatarUrl, ?string $fullImageUrl): void
    {
        $this->logger->info('Updating user images in database', [
            'user_id' => $userId,
            'avatar_url' => $avatarUrl,
            'full_img_url' => $fullImageUrl
        ]);
        
        $connection = Database::getConnection();
        
        // Build dynamic SQL based on which fields need updating
        $updateFields = [];
        $params = [];
        
        if ($avatarUrl !== null) {
            $updateFields[] = "avatar_url = ?";
            $params[] = $avatarUrl;
        }
        
        if ($fullImageUrl !== null) {
            $updateFields[] = "full_img_url = ?";
            $params[] = $fullImageUrl;
        }
        
        if (!empty($updateFields)) {
            $updateFields[] = "updated_at = NOW()";
            $params[] = $userId;
            
            $sql = "UPDATE fw_users SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $connection->executeStatement($sql, $params);
        }
        
        $this->logger->info('User images updated successfully', [
            'user_id' => $userId,
            'avatar_url' => $avatarUrl,
            'full_img_url' => $fullImageUrl
        ]);
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
     * Synchronize worker languages with database
     */
    private function synchronizeWorkerLanguages(int $workerId, array $languages): void
    {
        try {
            $connection = Database::getConnection();
            
            $this->logger->info('Synchronizing worker languages', [
                'worker_id' => $workerId,
                'languages_count' => count($languages),
                'languages' => $languages
            ]);

            // Get current languages from database
            $currentLanguages = [];
            $result = $connection->executeQuery(
                "SELECT language_id, prof_level FROM fw_user_languages WHERE worker_id = ?",
                [$workerId]
            );
            
            while ($row = $result->fetchAssociative()) {
                $currentLanguages[$row['language_id']] = $row['prof_level'];
            }

            // Process new languages array
            $newLanguages = [];
            foreach ($languages as $lang) {
                    if (isset($lang['language_id']) && isset($lang['prof_level'])) {
                        $newLanguages[$lang['language_id']] = $lang['prof_level'];
                }
            }

            // Find languages to add/update
            foreach ($newLanguages as $languageId => $profLevel) {
                if (!isset($currentLanguages[$languageId])) {
                    // Add new language
                    $connection->executeStatement(
                        "INSERT INTO fw_user_languages (worker_id, language_id, prof_level) VALUES (?, ?, ?)",
                        [$workerId, $languageId, $profLevel]
                    );
                    $this->logger->info('Added new language', [
                        'worker_id' => $workerId,
                        'language_id' => $languageId,
                        'prof_level' => $profLevel
                    ]);
                } elseif ($currentLanguages[$languageId] !== $profLevel) {
                    // Update existing language
                    $connection->executeStatement(
                        "UPDATE fw_user_languages SET prof_level = ? WHERE worker_id = ? AND language_id = ?",
                        [$profLevel, $workerId, $languageId]
                    );
                    $this->logger->info('Updated language proficiency', [
                        'worker_id' => $workerId,
                        'language_id' => $languageId,
                        'old_level' => $currentLanguages[$languageId],
                        'new_level' => $profLevel
                    ]);
                }
            }

            // Find languages to remove
            foreach ($currentLanguages as $languageId => $profLevel) {
                if (!isset($newLanguages[$languageId])) {
                    // Remove language
                    $connection->executeStatement(
                        "DELETE FROM fw_user_languages WHERE worker_id = ? AND language_id = ?",
                        [$workerId, $languageId]
                    );
                    $this->logger->info('Removed language', [
                        'worker_id' => $workerId,
                        'language_id' => $languageId
                    ]);
                }
            }

        } catch (Exception $e) {
            $this->logger->error('Error synchronizing worker languages', [
                'worker_id' => $workerId,
                'error' => $e->getMessage()
            ]);
            // Don't throw exception to avoid breaking profile update
        }
    }

    /**
     * Get emergency contact information
     * 
     * @OA\Get(
     *     path="/api/v1/profile/emergency",
     *     summary="Get emergency contact information",
     *     description="Retrieve emergency contact and medical information for the authenticated user",
     *     tags={"Profile"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Emergency contact information retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Emergency contact information retrieved successfully"),
     *             @OA\Property(property="data", ref="#/components/schemas/EmergencyContact")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=401),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Unauthorized")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=500),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Failed to retrieve emergency contact information")
     *         )
     *     )
     * )
     */
    public function getEmergencyContact(): void
    {
        try {
            $user = Flight::get('current_user');
            $connection = Database::getConnection();
            
            $sql = "SELECT emergency FROM fw_v_users WHERE id = ?";
            
            $result = $connection->executeQuery($sql, [$user['id']]);
            $row = $result->fetchAssociative();
            
            // Parse JSON from emergency field
            $emergencyData = [];
            if ($row['emergency']) {
                $emergencyData = json_decode($row['emergency'], true) ?: [];
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Emergency contact information retrieved successfully',
                'data' => [
                    'primary_contact_name' => $emergencyData['primary_contact_name'] ?? null,
                    'primary_contact_phone' => $emergencyData['primary_contact_phone'] ?? null,
                    'primary_contact_relationship' => $emergencyData['primary_contact_relationship'] ?? null,
                    'secondary_contact_name' => $emergencyData['secondary_contact_name'] ?? null,
                    'secondary_contact_phone' => $emergencyData['secondary_contact_phone'] ?? null,
                    'secondary_contact_relationship' => $emergencyData['secondary_contact_relationship'] ?? null,
                    'blood_type' => $emergencyData['blood_type'] ?? null,
                    'allergies' => $emergencyData['allergies'] ?? null,
                    'medical_conditions' => $emergencyData['medical_conditions'] ?? null,
                    'medications' => $emergencyData['medications'] ?? null,
                    'medical_notes' => $emergencyData['medical_notes'] ?? null,
                    'insurance_company' => $emergencyData['insurance_company'] ?? null,
                    'policy_number' => $emergencyData['policy_number'] ?? null,
                    'insurance_emergency_contact' => $emergencyData['insurance_emergency_contact'] ?? null
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error fetching emergency contact information', [
                'user_id' => $user['id'],
                'error' => $e->getMessage()
            ]);
            
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve emergency contact information',
                'data' => null
            ], 500);
        }
    }

    /**
     * Update emergency contact information
     * 
     * @OA\Put(
     *     path="/api/v1/profile/emergency",
     *     summary="Update emergency contact information",
     *     description="Update emergency contact and medical information for the authenticated user",
     *     tags={"Profile"},
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/EmergencyContact")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Emergency contact information updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Emergency contact information retrieved successfully"),
     *             @OA\Property(property="data", ref="#/components/schemas/EmergencyContact")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=400),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Invalid request format. Expected data object.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=401),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Unauthorized")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=500),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Failed to update emergency contact information")
     *         )
     *     )
     * )
     */
    public function updateEmergencyContact(): void
    {
        try {
            $user = Flight::get('current_user');
            $requestBody = Flight::request()->getBody();
            $data = json_decode($requestBody, true);

            $this->logger->info('Emergency contact update request', [
                'user_id' => $user['id'],
                'request_data' => $data
            ]);

            if (!isset($data['data']) || !is_array($data['data'])) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Invalid request format. Expected data object.',
                    'data' => null
                ], 400);
                return;
            }

            $emergencyData = $data['data'];
            $allowedFields = [
                'primary_contact_name', 'primary_contact_phone', 'primary_contact_relationship',
                'secondary_contact_name', 'secondary_contact_phone', 'secondary_contact_relationship',
                'blood_type', 'allergies', 'medical_conditions', 'medications', 'medical_notes',
                'insurance_company', 'policy_number', 'insurance_emergency_contact'
            ];

            // Filter and clean the data
            $filteredData = [];
            foreach ($allowedFields as $field) {
                if (isset($emergencyData[$field])) {
                    $value = $emergencyData[$field];
                    
                    // Convert empty strings to null
                    if ($value === '') {
                        $value = null;
                    }
                    
                    $filteredData[$field] = $value;
                }
            }

            if (empty($filteredData)) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'No valid fields provided for update',
                    'data' => null
                ], 400);
                return;
            }

            $connection = Database::getConnection();
            
            // Get existing emergency data and merge with new data
            $existingSql = "SELECT emergency FROM fw_users WHERE id = ?";
            $existingResult = $connection->executeQuery($existingSql, [$user['id']]);
            $existingRow = $existingResult->fetchAssociative();
            
            $existingData = [];
            if ($existingRow['emergency']) {
                $existingData = json_decode($existingRow['emergency'], true) ?: [];
            }
            
            // Merge existing data with new data
            $mergedData = array_merge($existingData, $filteredData);
            
            // Convert to JSON
            $emergencyJson = json_encode($mergedData, JSON_UNESCAPED_UNICODE);
            
            $sql = "UPDATE fw_users SET emergency = ?, updated_at = NOW() WHERE id = ?";
            
            $this->logger->info('Updating emergency contact', [
                'user_id' => $user['id'],
                'emergency_json' => $emergencyJson
            ]);

            $connection->executeStatement($sql, [$emergencyJson, $user['id']]);

            // Log emergency contact update event
            try {
                $this->eventLoggingService->logSimple(
                    entityType: 'user',
                    entityId: $user['id'],
                    eventType: 'USER_EMERGENCY_CONTACT_UPDATED',
                    afterData: $mergedData,
                    options: [
                        'actor_type' => 'user',
                        'actor_id' => $user['id'],
                        'before_data' => $existingData,
                        'changed_fields' => array_keys($filteredData),
                        'comment' => 'User emergency contact and medical information updated',
                        'ip' => Flight::request()->ip,
                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                        'severity' => 'important'
                    ]
                );
                
                // Check if profile became complete after this update
                $completenessCheck = $this->checkProfileCompleteness($user['id']);
                if ($completenessCheck['is_complete']) {
                    // Check if profile was incomplete before
                    $beforeCompleteness = [];
                    if (!empty($existingData)) {
                        // Reconstruct previous emergency data to check completeness
                        $tempEmergencyData = $existingData;
                        $tempUserData = $this->getUserById($user['id']);
                        if ($tempUserData) {
                            $tempUserData['emergency'] = json_encode($tempEmergencyData);
                            // Simple check: if key fields were missing before
                            $wasIncomplete = empty($tempEmergencyData['primary_contact_name']) || 
                                           empty($tempEmergencyData['primary_contact_phone']) ||
                                           empty($tempEmergencyData['blood_type']) ||
                                           empty($tempEmergencyData['insurance_company']) ||
                                           empty($tempEmergencyData['policy_number']);
                            
                            if ($wasIncomplete) {
                                $this->eventLoggingService->logSimple(
                                    entityType: 'user',
                                    entityId: $user['id'],
                                    eventType: 'USER_PROFILE_BECAME_COMPLETE',
                                    afterData: $completenessCheck,
                                    options: [
                                        'actor_type' => 'user',
                                        'actor_id' => $user['id'],
                                        'comment' => 'User profile became complete and ready for activation',
                                        'ip' => Flight::request()->ip,
                                        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                                        'severity' => 'important'
                                    ]
                                );
                            }
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->logger->warning('Failed to log USER_EMERGENCY_CONTACT_UPDATED event', [
                    'error' => $e->getMessage(),
                    'user_id' => $user['id']
                ]);
            }

            // Return updated data
            $this->getEmergencyContact();

        } catch (Exception $e) {
            $this->logger->error('Error updating emergency contact', [
                'user_id' => $user['id'],
                'error' => $e->getMessage()
            ]);
            
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to update emergency contact information',
                'data' => null
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/profile/activation-status",
     *     summary="Check profile activation readiness",
     *     description="Check if user profile has all required data for activation (personal data, medical, insurance, emergency contacts)",
     *     tags={"Profile"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Profile activation status retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Profile activation status retrieved successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="is_complete", type="boolean", example=false),
     *                 @OA\Property(property="missing_fields", type="object",
     *                     @OA\Property(property="personal", type="array", @OA\Items(type="string"), example={"dob", "gender"}),
     *                     @OA\Property(property="medical", type="array", @OA\Items(type="string"), example={"blood_type"}),
     *                     @OA\Property(property="insurance", type="array", @OA\Items(type="string"), example={"insurance_company", "policy_number"}),
     *                     @OA\Property(property="emergency", type="array", @OA\Items(type="string"), example={"primary_contact_name", "primary_contact_phone"})
     *                 ),
     *                 @OA\Property(property="message", type="string", example="Profile is incomplete. Please fill all required fields.")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=401),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Unauthorized")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=500),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Failed to check profile activation status")
     *         )
     *     )
     * )
     */
    public function getActivationStatus(): void
    {
        try {
            $user = Flight::get('current_user');
            
            $completenessCheck = $this->checkProfileCompleteness($user['id']);
            
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Profile activation status retrieved successfully',
                'data' => $completenessCheck
            ]);
        } catch (Exception $e) {
            $this->logger->error('Error checking profile activation status', [
                'user_id' => $user['id'] ?? null,
                'error' => $e->getMessage()
            ]);
            
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to check profile activation status',
                'data' => null
            ], 500);
        }
    }

    /**
     * Get professional data
     * 
     * @OA\Get(
     *     path="/api/v1/profile/professional",
     *     summary="Get professional data",
     *     description="Retrieve professional information including work experience, education, certifications, and skills for the authenticated user",
     *     tags={"Profile"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Professional data retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Professional data retrieved successfully"),
     *             @OA\Property(property="data", ref="#/components/schemas/ProfessionalData")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=401),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Unauthorized")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=500),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Failed to retrieve professional data")
     *         )
     *     )
     * )
     */
    public function getProfessionalData(): void
    {
        try {
            $user = Flight::get('current_user');
            $connection = Database::getConnection();
            
            $sql = "SELECT 
                        total_experience, education_level, field_of_study, institution_name, graduation_year,
                        professional_summary, specialized_skills, specialized_experience, key_projects,
                        previous_employers, `references`, availability, travel_willingness, drivers_license,
                        red_seal, provincial_certificate, union_membership, equipment_tools, whmis,
                        first_aid, fall_protection, confined_space, lockout_tagout, other_safety
                    FROM fw_user_professional WHERE user_id = ?";
            
            $result = $connection->executeQuery($sql, [$user['id']]);
            $professionalData = $result->fetchAssociative();

            if (!$professionalData) {
                // Return empty structure if no professional data exists
                Flight::json([
                    'status' => 'success',
                    'data' => [
                        'user_id' => $user['id'],
                        'total_experience' => null,
                        'education_level' => null,
                        'field_of_study' => null,
                        'institution_name' => null,
                        'graduation_year' => null,
                        'professional_summary' => null,
                        'specialized_skills' => null,
                        'specialized_experience' => null,
                        'key_projects' => null,
                        'previous_employers' => null,
                        'references' => null,
                        'availability' => null,
                        'travel_willingness' => null,
                        'drivers_license' => null,
                        'red_seal' => null,
                        'provincial_certificate' => null,
                        'union_membership' => null,
                        'equipment_tools' => null,
                        'whmis' => null,
                        'first_aid' => null,
                        'fall_protection' => null,
                        'confined_space' => null,
                        'lockout_tagout' => null,
                        'other_safety' => null
                    ]
                ]);
                return;
            }

            // Add user_id to the response data
            $professionalData['user_id'] = $user['id'];
            
            Flight::json([
                'status' => 'success',
                'data' => $professionalData
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error fetching professional data', [
                'user_id' => $user['id'],
                'error' => $e->getMessage()
            ]);
            
            Flight::json([
                'status' => 'error',
                'message' => 'Failed to retrieve professional data'
            ], 500);
        }
    }

    /**
     * Update professional data
     * 
     * @OA\Put(
     *     path="/api/v1/profile/professional",
     *     summary="Update professional data",
     *     description="Update professional information including work experience, education, certifications, and skills for the authenticated user",
     *     tags={"Profile"},
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="data", ref="#/components/schemas/ProfessionalData")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Professional data updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Professional data retrieved successfully"),
     *             @OA\Property(property="data", ref="#/components/schemas/ProfessionalData")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Bad request",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=400),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Invalid request format. Expected data object.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=401),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Unauthorized")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Internal server error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=500),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Failed to update professional data")
     *         )
     *     )
     * )
     */
    public function updateProfessionalData(): void
    {
        try {
            $user = Flight::get('current_user');
            $requestBody = Flight::request()->getBody();
            $data = json_decode($requestBody, true);

            $this->logger->info('Professional data update request', [
                'user_id' => $user['id'],
                'request_data' => $data
            ]);

            // Data is now sent directly without wrapper
            $professionalData = $data;
            $allowedFields = [
                'total_experience', 'education_level', 'field_of_study', 'institution_name', 'graduation_year',
                'professional_summary', 'specialized_skills', 'specialized_experience', 'key_projects',
                'previous_employers', 'references', 'availability', 'travel_willingness', 'drivers_license',
                'red_seal', 'provincial_certificate', 'union_membership', 'equipment_tools', 'whmis',
                'first_aid', 'fall_protection', 'confined_space', 'lockout_tagout', 'other_safety'
            ];
            
            // Remove user_id from data if present (it's not allowed to be updated)
            unset($professionalData['user_id']);

            $connection = Database::getConnection();
            
            // Check if professional data exists
            $checkSql = "SELECT id FROM fw_user_professional WHERE user_id = ?";
            $existingResult = $connection->executeQuery($checkSql, [$user['id']]);
            $existing = $existingResult->fetchAssociative();

            $updateFields = [];
            $params = [];

            foreach ($allowedFields as $field) {
                if (isset($professionalData[$field])) {
                    $value = $professionalData[$field];
                    
                    // Convert empty strings to null for all fields
                    if ($value === '') {
                        $value = null;
                    }
                    
                    // Convert total_experience and graduation_year to integer if they are numeric
                    if (in_array($field, ['total_experience', 'graduation_year']) && is_numeric($value)) {
                        $value = (int) $value;
                    }
                    
                    // Handle references field with backticks for SQL
                    $sqlField = $field === 'references' ? '`references`' : $field;
                    $updateFields[] = "{$sqlField} = ?";
                    $params[] = $value;
                    
                    // Log references field specifically
                    if ($field === 'references') {
                        $this->logger->info('Processing references field', [
                            'field' => $field,
                            'value' => $value,
                            'sqlField' => $sqlField
                        ]);
                    }
                }
            }

            if (empty($updateFields)) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'No valid fields provided for update',
                    'data' => null
                ], 400);
                return;
            }

            if ($existing) {
                // Update existing record
                $params[] = $user['id'];
                $sql = "UPDATE fw_user_professional SET " . implode(', ', $updateFields) . ", updated_at = NOW() WHERE user_id = ?";
            } else {
                // Insert new record
                $params[] = $user['id'];
                $sql = "INSERT INTO fw_user_professional (" . implode(', ', array_map(function($field) {
                    return str_replace(' = ?', '', $field);
                }, $updateFields)) . ", user_id, created_at, updated_at) VALUES (" . str_repeat('?, ', count($updateFields)) . "?, NOW(), NOW())";
            }
            
            $this->logger->info('Updating professional data', [
                'user_id' => $user['id'],
                'sql' => $sql,
                'params' => $params
            ]);

            $connection->executeStatement($sql, $params);

            // Return updated data
            $this->getProfessionalData();

        } catch (Exception $e) {
            $this->logger->error('Error updating professional data', [
                'user_id' => $user['id'],
                'error' => $e->getMessage()
            ]);
            
            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to update professional data',
                'data' => null
            ], 500);
        }
    }

    /**
     * Get emergency data for user
     */
    private function getEmergencyData(int $userId): ?array
    {
        try {
            $connection = Database::getConnection();
            $sql = "SELECT emergency FROM fw_v_users WHERE id = ?";
            $result = $connection->executeQuery($sql, [$userId]);
            $row = $result->fetchAssociative();
            
            if ($row['emergency']) {
                return json_decode($row['emergency'], true) ?: null;
            }
            
            return null;
        } catch (Exception $e) {
            $this->logger->error('Error fetching emergency data', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get user languages
     */
    private function getUserLanguages(int $userId): array
    {
        try {
            $connection = Database::getConnection();
            
            $result = $connection->executeQuery(
                "SELECT wl.language_id, l.name as language_name, wl.prof_level 
                 FROM fw_user_languages wl 
                 INNER JOIN fw_languages l ON wl.language_id = l.id 
                 WHERE wl.worker_id = ? 
                 ORDER BY l.name",
                [$userId]
            );

            $languages = [];
            while ($row = $result->fetchAssociative()) {
                $languages[] = [
                    'language_id' => (int)$row['language_id'],
                    'language_name' => $row['language_name'],
                    'prof_level' => $row['prof_level'],
                    'worker_id' => $userId
                ];
            }

            return $languages;
        } catch (Exception $e) {
            $this->logger->error('Error fetching user languages', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Get user by ID
     */
    private function getUserById(int $userId): ?array
    {
        $connection = Database::getConnection();
        
        $sql = 'SELECT * 
                FROM fw_v_users 
                WHERE id = ?';
        
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

    /**
     * Check if user profile has all required data for activation
     * Required fields:
     * - Personal data: first_name, last_name, phone, dob, gender
     * - Medical data: blood_type
     * - Insurance: insurance_company, policy_number
     * - Emergency contacts: primary_contact_name, primary_contact_phone
     */
    private function checkProfileCompleteness(int $userId): array
    {
        try {
            $connection = Database::getConnection();
            
            // Get user data
            $sql = "SELECT first_name, last_name, phone, dob, gender, emergency 
                    FROM fw_v_users 
                    WHERE id = ?";
            $result = $connection->executeQuery($sql, [$userId]);
            $user = $result->fetchAssociative();
            
            if (!$user) {
                return [
                    'is_complete' => false,
                    'missing_fields' => [],
                    'message' => 'User not found'
                ];
            }
            
            // Parse emergency data
            $emergencyData = [];
            if ($user['emergency']) {
                $emergencyData = json_decode($user['emergency'], true) ?: [];
            }
            
            // Check required fields
            $missingFields = [];
            
            // Personal data
            if (empty($user['first_name'])) {
                $missingFields['personal'][] = 'first_name';
            }
            if (empty($user['last_name'])) {
                $missingFields['personal'][] = 'last_name';
            }
            if (empty($user['phone'])) {
                $missingFields['personal'][] = 'phone';
            }
            if (empty($user['dob'])) {
                $missingFields['personal'][] = 'dob';
            }
            if (empty($user['gender'])) {
                $missingFields['personal'][] = 'gender';
            }
            
            // Medical data
            if (empty($emergencyData['blood_type'])) {
                $missingFields['medical'][] = 'blood_type';
            }
            
            // Insurance
            if (empty($emergencyData['insurance_company'])) {
                $missingFields['insurance'][] = 'insurance_company';
            }
            if (empty($emergencyData['policy_number'])) {
                $missingFields['insurance'][] = 'policy_number';
            }
            
            // Emergency contacts
            if (empty($emergencyData['primary_contact_name'])) {
                $missingFields['emergency'][] = 'primary_contact_name';
            }
            if (empty($emergencyData['primary_contact_phone'])) {
                $missingFields['emergency'][] = 'primary_contact_phone';
            }
            
            $isComplete = empty($missingFields);
            
            return [
                'is_complete' => $isComplete,
                'missing_fields' => $missingFields,
                'message' => $isComplete 
                    ? 'Profile is complete and ready for activation' 
                    : 'Profile is incomplete. Please fill all required fields.'
            ];
        } catch (\Exception $e) {
            $this->logger->error('Error checking profile completeness: ' . $e->getMessage());
            return [
                'is_complete' => false,
                'missing_fields' => [],
                'message' => 'Error checking profile completeness'
            ];
        }
    }

    /**
     * Update user work status in database
     */
    private function updateUserWorkStatus(int $userId, bool $isActive, ?string $inactiveReason, ?string $inactiveReasonDetails, ?string $status_end_at): bool
    {
        try {
            $connection = Database::getConnection();
            
            // Get current status before update
            $currentUserSql = "SELECT status, status_reason, status_details, status_end_at FROM fw_users WHERE id = ?";
            $currentResult = $connection->executeQuery($currentUserSql, [$userId]);
            $currentUser = $currentResult->fetchAssociative();
            $oldStatus = (bool)($currentUser['status'] ?? false);
            
            if ($isActive) {
                // Check if profile is complete before activation
                $completenessCheck = $this->checkProfileCompleteness($userId);
                if (!$completenessCheck['is_complete']) {
                    throw new \Exception('Cannot activate user: profile is incomplete. Missing fields: ' . json_encode($completenessCheck['missing_fields']));
                }
                
                // If user is active, clear inactive reasons
                $sql = "UPDATE fw_users SET 
                        status = TRUE,
                        status_reason = NULL,
                        status_details = NULL,
                        status_changed_at = NOW(),
                        status_end_at = NULL,
                        updated_at = NOW() 
                        WHERE id = ?";
                $connection->executeStatement($sql, [$userId]);
                
                // Log activation event
                // Using logSimple for USER_ACTIVATED - always writes to fw_event_log
                // USER_STATUS_CHANGED is too generic - use specific USER_ACTIVATED instead
                try {
                    $this->eventLoggingService->logSimple(
                        entityType: 'user',
                        entityId: $userId,
                        eventType: 'USER_ACTIVATED',
                        afterData: [
                            'status' => true,
                            'profile_complete' => true,
                            'activated_at' => date('c'),
                            'previous_status' => $oldStatus,
                            'previous_status_reason' => $currentUser['status_reason'] ?? null
                        ],
                        options: [
                            'actor_type' => 'user',
                            'actor_id' => $userId,
                            'before_data' => [
                                'status' => $oldStatus,
                                'status_reason' => $currentUser['status_reason'] ?? null,
                                'status_details' => $currentUser['status_details'] ?? null,
                                'status_end_at' => $currentUser['status_end_at'] ?? null
                            ],
                            'changed_fields' => ['status', 'status_reason', 'status_details', 'status_end_at', 'status_changed_at'],
                            'comment' => 'User activated - all required profile data completed',
                            'ip' => Flight::request()->ip ?? null,
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                            'severity' => 'important'
                        ]
                    );
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to log USER_ACTIVATED event', [
                        'error' => $e->getMessage(),
                        'user_id' => $userId
                    ]);
                }
            } else {
                // If user is inactive, set reasons
                $sql = "UPDATE fw_users SET 
                        status = FALSE,
                        status_reason = ?,
                        status_details = ?,
                        status_changed_at = NOW(),
                        status_end_at = ?,
                        updated_at = NOW() 
                        WHERE id = ?";
                $connection->executeStatement($sql, [$inactiveReason, $inactiveReasonDetails, $status_end_at, $userId]);
                
                // Log deactivation event
                // Using logSimple for USER_DEACTIVATED - always writes to fw_event_log
                // USER_STATUS_CHANGED is too generic - use specific USER_DEACTIVATED instead
                $isTemporary = !empty($status_end_at);
                $comment = $isTemporary 
                    ? "User temporarily deactivated until {$status_end_at}. Reason: {$inactiveReason}" 
                    : "User deactivated. Reason: {$inactiveReason}";
                
                try {
                    $this->eventLoggingService->logSimple(
                        entityType: 'user',
                        entityId: $userId,
                        eventType: 'USER_DEACTIVATED',
                        afterData: [
                            'status' => false,
                            'status_reason' => $inactiveReason,
                            'status_details' => $inactiveReasonDetails,
                            'status_end_at' => $status_end_at,
                            'is_temporary' => $isTemporary,
                            'deactivated_at' => date('c'),
                            'previous_status' => $oldStatus
                        ],
                        options: [
                            'actor_type' => 'user',
                            'actor_id' => $userId,
                            'before_data' => [
                                'status' => $oldStatus,
                                'status_reason' => $currentUser['status_reason'] ?? null,
                                'status_details' => $currentUser['status_details'] ?? null,
                                'status_end_at' => $currentUser['status_end_at'] ?? null
                            ],
                            'changed_fields' => ['status', 'status_reason', 'status_details', 'status_end_at', 'status_changed_at'],
                            'comment' => $comment,
                            'ip' => Flight::request()->ip ?? null,
                            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                            'severity' => 'important'
                        ]
                    );
                } catch (\Exception $e) {
                    $this->logger->warning('Failed to log USER_DEACTIVATED event', [
                        'error' => $e->getMessage(),
                        'user_id' => $userId
                    ]);
                }
            }
            
            return true;
        } catch (\Exception $e) {
            $this->logger->error('Error updating work status in database: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get user by ID without status filter (for work status operations)
     */
    private function getUserByIdForWorkStatus(int $userId): ?array
    {
        try {
            $connection = Database::getConnection();
            
            $sql = 'SELECT *
                    FROM fw_v_users 
                    WHERE id = ?';
            
            $this->logger->info('Executing SQL query', [
                'sql' => $sql,
                'user_id' => $userId
            ]);
            
            $result = $connection->executeQuery($sql, [$userId]);
            $user = $result->fetchAssociative();

            if (!$user) {
                $this->logger->warning('No user found in database', [
                    'user_id' => $userId,
                    'sql' => $sql
                ]);
            } else {
                $this->logger->info('User found in database', [
                    'user_id' => $user['id'],
                    'email' => $user['email'],
                    'status' => $user['status']
                ]);
            }

            return $user ?: null;
        } catch (\Exception $e) {
            $this->logger->error('Database error in getUserByIdForWorkStatus', [
                'error' => $e->getMessage(),
                'user_id' => $userId
            ]);
            return null;
        }
    }

    /**
     * Delete user files by type (avatar or full)
     */
    private function deleteUserFilesByType(int $userId, string $type): void
    {
        try {
            $uploadsDir = __DIR__ . '/../../public/uploads/avatars/';
            $pattern = $uploadsDir . "user_{$userId}_{$type}_*";
            $files = glob($pattern);
            
            $this->logger->info('Deleting user files by type', [
                'user_id' => $userId,
                'type' => $type,
                'pattern' => $pattern,
                'found_files' => count($files),
                'files' => array_map('basename', $files)
            ]);
            
            $deletedCount = 0;
            foreach ($files as $file) {
                if (is_file($file)) {
                    if (unlink($file)) {
                        $deletedCount++;
                        $this->logger->info('User file deleted', [
                            'user_id' => $userId,
                            'type' => $type,
                            'file' => basename($file)
                        ]);
                    } else {
                        $this->logger->warning('Failed to delete user file', [
                            'user_id' => $userId,
                            'type' => $type,
                            'file' => basename($file)
                        ]);
                    }
                }
            }
            
            $this->logger->info('User files cleanup completed', [
                'user_id' => $userId,
                'type' => $type,
                'deleted_count' => $deletedCount
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Error deleting user files', [
                'user_id' => $userId,
                'type' => $type,
                'error' => $e->getMessage()
            ]);
        }
    }
}
