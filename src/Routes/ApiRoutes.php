<?php

namespace App\Routes;

use App\Controllers\HealthController;
use App\Controllers\DatabaseController;
use App\Controllers\AuthController;
use App\Controllers\GeographyController;
use App\Controllers\WorkerController;
use App\Controllers\RegistrationController;
use App\Controllers\ProjectController;
use App\Controllers\TaskController;
use App\Controllers\ProjectTeamController;
use App\Controllers\ScheduleWeekController;
use App\Controllers\ScheduleEntryMessageController;
use App\Controllers\ScheduleEntryDocumentController;
use App\Controllers\TaskFieldPhotoController;
use App\Controllers\EventLogController;
use App\Controllers\N8nIntegrationController;
use App\Controllers\LanguageController;
use App\Controllers\EventRulesController;
use App\Controllers\MessageTemplatesController;
use App\Controllers\ClientController;
use App\Controllers\CalendarController;
use App\Database\Database;
use Flight;
use Monolog\Logger;
use OpenApi\Annotations as OA;

class ApiRoutes
{
    private Logger $logger;
    private Database $database;

    public function __construct(Logger $logger, Database $database)
    {
        $this->logger = $logger;
        $this->database = $database;
        $this->register();
    }

    public function register(): void
    {
        // CORS headers are handled by CorsMiddleware in Application.php

        // API v1 routes
        $this->registerV1Routes();
        
        // Swagger documentation routes
        Flight::route('GET /swagger.json', function() {
            try {
                $filePath = __DIR__ . '/../../public/swagger.php';
                if (!file_exists($filePath)) {
                    throw new \Exception('Swagger file not found: ' . $filePath);
                }
                require_once $filePath;
            } catch (\Exception $e) {
                Flight::json(['error' => 'Failed to load Swagger specification'], 500);
            }
        });

        // Swagger UI route
        Flight::route('GET /docs', function() {
            try {
                $filePath = __DIR__ . '/../../public/swagger-ui.php';
                if (!file_exists($filePath)) {
                    throw new \Exception('Swagger UI file not found: ' . $filePath);
                }
                require_once $filePath;
            } catch (\Exception $e) {
                Flight::json(['error' => 'Failed to load Swagger UI'], 500);
            }
        });

        Flight::route('GET /api/docs', function() {
            Flight::json(['message' => 'API docs route works!']);
        });

        // Test route
        Flight::route('GET /test', function () {
            Flight::json(['message' => 'Test route works!']);
        });
        
        // API documentation
        Flight::route('GET /api', function () {
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'API information retrieved',
                'data' => [
                    'name' => 'FieldWire API',
                    'version' => '1.0.0',
                    'description' => 'REST API built with FlightPHP',
                    'documentation' => [
                        'swagger_ui' => 'GET /docs',
                        'swagger_json' => 'GET /swagger.json',
                        'endpoints' => 'GET /api/v1/health',
                        'version' => 'GET /api/v1/version'
                    ],
                    'versions' => [
                        'v1' => [
                            'status' => 'stable',
                            'endpoints' => [
                                'health' => 'GET /api/v1/health',
                                'version' => 'GET /api/v1/version',
                                'database_tables' => 'GET /api/v1/database/tables',
                                'auth_login' => 'POST /api/v1/auth/login',
                                'auth_logout' => 'POST /api/v1/auth/logout',
                                'auth_check_session' => 'POST /api/v1/auth/check-session',
                                'auth_refresh_token' => 'POST /api/v1/auth/refresh-token',
                                'auth_validate_invitation' => 'GET /api/v1/auth/validate-invitation-token',
                                'auth_change_password' => 'POST /api/v1/auth/change-password',
                                'auth_forgot_password' => 'POST /api/v1/auth/forgot-password',
                                'auth_reset_password' => 'POST /api/v1/auth/reset-password',
                                'profile_get' => 'GET /api/v1/profile',
                                'profile_update' => 'PUT /api/v1/profile',
                                'profile_avatar' => 'POST /api/v1/profile/avatar',
                                '2fa_toggle' => 'POST /api/v1/2fa/toggle',
                                'roles_get' => 'GET /api/v1/roles',
                                'event_rules_get' => 'GET /api/v1/admin/event-rules',
                                'event_rules_get_by_type' => 'GET /api/v1/admin/event-rules/{event_type}',
                                'event_rules_create' => 'POST /api/v1/admin/event-rules',
                                'event_rules_update' => 'PUT /api/v1/admin/event-rules/{event_type}',
                                'event_rules_delete' => 'DELETE /api/v1/admin/event-rules/{event_type}',
                                'event_rules_conditions' => 'GET /api/v1/admin/event-rules/conditions',
                                'event_rules_actions' => 'GET /api/v1/admin/event-rules/actions',
                                'message_templates_get' => 'GET /api/v1/admin/message-templates',
                                'message_templates_get_by_id' => 'GET /api/v1/admin/message-templates/{id}',
                                'message_templates_create' => 'POST /api/v1/admin/message-templates',
                                'message_templates_update' => 'PUT /api/v1/admin/message-templates/{id}',
                                'message_templates_delete' => 'DELETE /api/v1/admin/message-templates/{id}',
                                'message_templates_by_event' => 'GET /api/v1/admin/message-templates/by-event/{event_type}',
                                'event_logs_get' => 'GET /api/v1/event-logs',
                                'event_logs_get_by_id' => 'GET /api/v1/event-logs/{id}',
                                'event_logs_create' => 'POST /api/v1/event-logs',
                                'event_logs_outbox_pending' => 'GET /api/v1/event-logs/outbox/pending',
                                'event_logs_outbox_update_status' => 'PUT /api/v1/event-logs/outbox/{id}/status',
                                'n8n_webhook_manual_trigger' => 'POST /api/v1/n8n/webhook/manual-trigger',
                                'n8n_scheduled_data_collection' => 'GET /api/v1/n8n/scheduled/data-collection',
                                'n8n_workflow_status' => 'GET /api/v1/n8n/workflow/status',
                                
                                // Language endpoints
                                'languages_get' => 'GET /api/v1/languages',
                                'languages_create' => 'POST /api/v1/languages',
                                'languages_update' => 'PUT /api/v1/languages/{languageId}',
                                'worker_languages_get' => 'GET /api/v1/workers/{workerId}/languages',
                                'worker_languages_add' => 'POST /api/v1/workers/{workerId}/languages',
                                'worker_languages_update' => 'PUT /api/v1/workers/{workerId}/languages/{languageId}',
                                'worker_languages_remove' => 'DELETE /api/v1/workers/{workerId}/languages/{languageId}',
                                'tasks_reorder' => 'PUT /api/v1/projects/{project_id}/tasks/reorder',
                                'tasks_normalize_order' => 'PUT /api/v1/projects/{project_id}/tasks/normalize-order',
                                'dependencies_create' => 'POST /api/v1/projects/{project_id}/dependencies',
                                'dependencies_update' => 'PUT /api/v1/dependencies/{dependency_id}',
                                'dependencies_delete' => 'DELETE /api/v1/dependencies/{dependency_id}',
                                'dependencies_project' => 'GET /api/v1/projects/{project_id}/dependencies',
                                'dependencies_task' => 'GET /api/v1/tasks/{task_id}/dependencies',
                                'task_templates_list' => 'GET /api/v1/task-templates',
                                'task_templates_get' => 'GET /api/v1/task-templates/{id}',
                                'task_templates_create' => 'POST /api/v1/task-templates',
                                'task_templates_update' => 'PUT /api/v1/task-templates/{id}',
                                'task_templates_delete' => 'DELETE /api/v1/task-templates/{id}'
                            ]
                        ]
                    ]
                ]
            ]);
        });

        // 404 handler for API routes
        Flight::map('notFound', function () {
            Flight::json([
                'error_code' => 404,
                'status' => 'error',
                'message' => 'Endpoint not found',
                'data' => null
            ], 404);
        });
    }

    private function registerV1Routes(): void
    {
        // Health check endpoint
        Flight::route('GET /api/v1/health', [new HealthController($this->logger), 'getHealth']);
        
        // Version info endpoint
        Flight::route('GET /api/v1/version', [new HealthController($this->logger), 'getVersion']);
        
        // Legacy route for backward compatibility
        Flight::route('GET /api/health', [new HealthController($this->logger), 'getHealth']);
        
        // Database tables
        Flight::route('GET /api/v1/database/tables', [new DatabaseController(), 'getTables']);

        // Authentication routes
        Flight::route('POST /api/v1/auth/login', [new AuthController($this->logger), 'login']);
        
        // Logout route with auth middleware
        $authMiddleware = new \App\Middleware\AuthMiddleware($this->logger);
        Flight::route('POST /api/v1/auth/logout', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $authController = new AuthController($this->logger);
                $authController->logout();
            }
        });
        
        Flight::route('POST /api/v1/auth/check-session', [new AuthController($this->logger), 'checkSession']);
        Flight::route('POST /api/v1/auth/refresh-token', [new AuthController($this->logger), 'refreshToken']);
        Flight::route('GET /api/v1/auth/validate-invitation-token', [new AuthController($this->logger), 'validateInvitationToken']);
        Flight::route('POST /api/v1/auth/change-password', [new AuthController($this->logger), 'changePassword']);
        Flight::route('POST /api/v1/auth/forgot-password', [new AuthController($this->logger), 'forgotPassword']);
        Flight::route('POST /api/v1/auth/reset-password', [new AuthController($this->logger), 'resetPassword']);
        
        // Legacy auth route for backward compatibility
        Flight::route('POST /auth/login', [new AuthController($this->logger), 'login']);

        // Create shared auth middleware for all protected routes
        $authMiddleware = new \App\Middleware\AuthMiddleware($this->logger);

        // Profile management routes (protected)
        try {
            $twilioService = new \App\Services\TwilioService($this->logger);
            $emailService = new \App\Services\EmailService($this->logger);
            $profileController = new \App\Controllers\ProfileController($this->logger, $twilioService, $emailService);
            
            // Profile routes with auth middleware
            Flight::route('GET /api/v1/profile', function() use ($profileController, $authMiddleware) {
                if ($authMiddleware->handle()) {
                    $profileController->getProfile();
                }
            });

            $scheduleWeekController = new ScheduleWeekController($this->logger);
            Flight::route('GET /api/v1/me/schedule', function() use ($scheduleWeekController, $authMiddleware) {
                if ($authMiddleware->handle()) {
                    $scheduleWeekController->getMySchedule();
                }
            });
            Flight::route('GET /api/v1/users/@user_id/schedule', function($user_id) use ($scheduleWeekController, $authMiddleware) {
                if ($authMiddleware->handle()) {
                    $scheduleWeekController->getUserSchedule((int) $user_id);
                }
            });
            
            Flight::route('PUT /api/v1/profile', function() use ($profileController, $authMiddleware) {
                if ($authMiddleware->handle()) {
                    $profileController->updateProfile();
                }
            });
            
            Flight::route('POST /api/v1/profile/avatar', function() use ($profileController, $authMiddleware) {
                if ($authMiddleware->handle()) {
                    $profileController->uploadAvatar();
                }
            });
            
            Flight::route('GET /api/v1/profile/avatar', function() use ($profileController) {
                $profileController->getAvatar();
            });
            
            Flight::route('GET /api/v1/avatar', function() use ($profileController) {
                $profileController->serveAvatar();
            });
            
            // Full image routes (for serving only)
            Flight::route('GET /api/v1/profile/full-image', function() use ($profileController) {
                $profileController->getFullImage();
            });
            
            Flight::route('GET /api/v1/full-image', function() use ($profileController) {
                $profileController->serveFullImage();
            });
            
            // Work status management route
            Flight::route('PUT /api/v1/profile/work-status', function() use ($profileController, $authMiddleware) {
                if ($authMiddleware->handle()) {
                    $profileController->updateWorkStatus();
                }
            });
            
            // Emergency contact routes
            Flight::route('GET /api/v1/profile/emergency', function() use ($profileController, $authMiddleware) {
                if ($authMiddleware->handle()) {
                    $profileController->getEmergencyContact();
                }
            });
            
            Flight::route('PUT /api/v1/profile/emergency', function() use ($profileController, $authMiddleware) {
                if ($authMiddleware->handle()) {
                    $profileController->updateEmergencyContact();
                }
            });
            
            Flight::route('GET /api/v1/profile/activation-status', function() use ($profileController, $authMiddleware) {
                if ($authMiddleware->handle()) {
                    $profileController->getActivationStatus();
                }
            });
            
            // Professional data routes
            Flight::route('GET /api/v1/profile/professional', function() use ($profileController, $authMiddleware) {
                if ($authMiddleware->handle()) {
                    $profileController->getProfessionalData();
                }
            });
            
            Flight::route('PUT /api/v1/profile/professional', function() use ($profileController, $authMiddleware) {
                if ($authMiddleware->handle()) {
                    $profileController->updateProfessionalData();
                }
            });
            
            // Change password route
            Flight::route('POST /api/v1/profile/change-password', function() use ($profileController, $authMiddleware) {
                if ($authMiddleware->handle()) {
                    $profileController->changePassword();
                }
            });
            
        } catch (\Exception $e) {
            throw $e;
        }

        // Two-Factor Authentication routes
        try {
            $twilioService = new \App\Services\TwilioService($this->logger);
            $emailService = new \App\Services\EmailService($this->logger);
            $twoFactorController = new \App\Controllers\TwoFactorController($this->logger, $twilioService, $emailService);
            
            /**
             * @OA\Post(
             *     path="/api/v1/2fa/send-code",
             *     tags={"Two-Factor"},
             *     summary="Send 2FA verification code",
             *     description="Send a verification code via SMS or email for two-factor authentication",
             *     @OA\RequestBody(
             *         required=true,
             *         @OA\JsonContent(
             *             required={"user_id", "method"},
             *             @OA\Property(property="user_id", type="integer", example=47, description="User ID"),
             *             @OA\Property(property="method", type="string", enum={"sms", "email"}, example="sms", description="Verification method")
             *         )
             *     ),
             *     @OA\Response(
             *         response=200,
             *         description="Code sent successfully",
             *         @OA\JsonContent(
             *             @OA\Property(property="success", type="boolean", example=true),
             *             @OA\Property(property="message", type="string", example="Verification code sent successfully"),
             *             @OA\Property(property="method", type="string", example="sms"),
             *             @OA\Property(property="expires_in", type="integer", example=600, description="Code expiration time in seconds")
             *         )
             *     ),
             *     @OA\Response(
             *         response=400,
             *         description="Bad request",
             *         @OA\JsonContent(
             *             @OA\Property(property="success", type="boolean", example=false),
             *             @OA\Property(property="error", type="string", example="Invalid user ID or method")
             *         )
             *     ),
             *     @OA\Response(
             *         response=500,
             *         description="Internal server error",
             *         @OA\JsonContent(
             *             @OA\Property(property="success", type="boolean", example=false),
             *             @OA\Property(property="error", type="string", example="Failed to send verification code")
             *         )
             *     )
             * )
             */
            Flight::route('POST /api/v1/2fa/send-code', [$twoFactorController, 'sendCode']);
            
            /**
             * @OA\Post(
             *     path="/api/v1/2fa/verify-code",
             *     tags={"Two-Factor"},
             *     summary="Verify 2FA code",
             *     description="Verify the two-factor authentication code sent via SMS or email",
             *     @OA\RequestBody(
             *         required=true,
             *         @OA\JsonContent(
             *             required={"user_id", "code", "method"},
             *             @OA\Property(property="user_id", type="integer", example=47, description="User ID"),
             *             @OA\Property(property="code", type="string", example="123456", description="6-digit verification code"),
             *             @OA\Property(property="method", type="string", enum={"sms", "email"}, example="sms", description="Verification method")
             *         )
             *     ),
             *     @OA\Response(
             *         response=200,
             *         description="Code verified successfully",
             *         @OA\JsonContent(
             *             @OA\Property(property="success", type="boolean", example=true),
             *             @OA\Property(property="message", type="string", example="Verification code verified successfully"),
             *             @OA\Property(property="verified", type="boolean", example=true)
             *         )
             *     ),
             *     @OA\Response(
             *         response=400,
             *         description="Invalid code or expired",
             *         @OA\JsonContent(
             *             @OA\Property(property="success", type="boolean", example=false),
             *             @OA\Property(property="error", type="string", example="Invalid or expired verification code")
             *         )
             *     ),
             *     @OA\Response(
             *         response=500,
             *         description="Internal server error",
             *         @OA\JsonContent(
             *             @OA\Property(property="success", type="boolean", example=false),
             *             @OA\Property(property="error", type="string", example="Failed to verify code")
             *         )
             *     )
             * )
             */
            Flight::route('POST /api/v1/2fa/verify-code', [$twoFactorController, 'verifyCode']);
            
            /**
             * @OA\Post(
             *     path="/api/v1/2fa/toggle",
             *     tags={"Two-Factor"},
             *     summary="Toggle 2FA for user",
             *     description="Enable or disable two-factor authentication for the authenticated user",
             *     security={{"bearerAuth": {}}},
             *     @OA\RequestBody(
             *         required=true,
             *         @OA\JsonContent(
             *             required={"enabled"},
             *             @OA\Property(property="enabled", type="boolean", example=true, description="Enable or disable 2FA")
             *         )
             *     ),
             *     @OA\Response(
             *         response=200,
             *         description="2FA status updated successfully",
             *         @OA\JsonContent(
             *             @OA\Property(property="success", type="boolean", example=true),
             *             @OA\Property(property="message", type="string", example="Two-factor authentication enabled"),
             *             @OA\Property(property="two_factor_enabled", type="boolean", example=true)
             *         )
             *     ),
             *     @OA\Response(
             *         response=401,
             *         description="Unauthorized",
             *         @OA\JsonContent(
             *             @OA\Property(property="success", type="boolean", example=false),
             *             @OA\Property(property="error", type="string", example="Unauthorized")
             *         )
             *     ),
             *     @OA\Response(
             *         response=500,
             *         description="Internal server error",
             *         @OA\JsonContent(
             *             @OA\Property(property="success", type="boolean", example=false),
             *             @OA\Property(property="error", type="string", example="Failed to update 2FA status")
             *         )
             *     )
             * )
             */
            Flight::route('POST /api/v1/2fa/toggle', function() use ($twoFactorController, $authMiddleware) {
                if ($authMiddleware->handle()) {
                    $twoFactorController->toggle2FA();
                }
            });
            
            // Legacy 2FA routes (deprecated - use /api/v1/2fa/* instead)
            /**
             * @OA\Post(
             *     path="/2fa/send-code",
             *     tags={"Two-Factor"},
             *     summary="Send 2FA verification code (Legacy)",
             *     description="Legacy endpoint for sending verification code. Use /api/v1/2fa/send-code instead.",
             *     deprecated=true,
             *     @OA\RequestBody(
             *         required=true,
             *         @OA\JsonContent(
             *             required={"user_id", "method"},
             *             @OA\Property(property="user_id", type="integer", example=47, description="User ID"),
             *             @OA\Property(property="method", type="string", enum={"sms", "email"}, example="sms", description="Verification method")
             *         )
             *     ),
             *     @OA\Response(
             *         response=200,
             *         description="Code sent successfully",
             *         @OA\JsonContent(
             *             @OA\Property(property="success", type="boolean", example=true),
             *             @OA\Property(property="message", type="string", example="Verification code sent successfully")
             *         )
             *     )
             * )
             */
            Flight::route('POST /2fa/send-code', [$twoFactorController, 'sendCode']);
            
            /**
             * @OA\Post(
             *     path="/2fa/verify-code",
             *     tags={"Two-Factor"},
             *     summary="Verify 2FA code (Legacy)",
             *     description="Legacy endpoint for verifying code. Use /api/v1/2fa/verify-code instead.",
             *     deprecated=true,
             *     @OA\RequestBody(
             *         required=true,
             *         @OA\JsonContent(
             *             required={"user_id", "code", "method"},
             *             @OA\Property(property="user_id", type="integer", example=47, description="User ID"),
             *             @OA\Property(property="code", type="string", example="123456", description="6-digit verification code"),
             *             @OA\Property(property="method", type="string", enum={"sms", "email"}, example="sms", description="Verification method")
             *         )
             *     ),
             *     @OA\Response(
             *         response=200,
             *         description="Code verified successfully",
             *         @OA\JsonContent(
             *             @OA\Property(property="success", type="boolean", example=true),
             *             @OA\Property(property="message", type="string", example="Verification code verified successfully")
             *         )
             *     )
             * )
             */
            Flight::route('POST /2fa/verify-code', [$twoFactorController, 'verifyCode']);
            
            /**
             * @OA\Post(
             *     path="/2fa/toggle",
             *     tags={"Two-Factor"},
             *     summary="Toggle 2FA for user (Legacy)",
             *     description="Legacy endpoint for toggling 2FA. Use /api/v1/2fa/toggle instead.",
             *     deprecated=true,
             *     @OA\RequestBody(
             *         required=true,
             *         @OA\JsonContent(
             *             required={"enabled"},
             *             @OA\Property(property="enabled", type="boolean", example=true, description="Enable or disable 2FA")
             *         )
             *     ),
             *     @OA\Response(
             *         response=200,
             *         description="2FA status updated successfully",
             *         @OA\JsonContent(
             *             @OA\Property(property="success", type="boolean", example=true),
             *             @OA\Property(property="message", type="string", example="Two-factor authentication enabled")
             *         )
             *     )
             * )
             */
            Flight::route('POST /2fa/toggle', function() use ($twoFactorController, $authMiddleware) {
                if ($authMiddleware->handle()) {
                    $twoFactorController->toggle2FA();
                }
            });
            
        } catch (\Exception $e) {
            throw $e;
        }

        // Patient routes removed - no longer needed

        // Driver routes v1 (protected)
        Flight::route('GET /api/v1/drivers', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $driverController = new \App\Controllers\DriverController($this->logger);
                $driverController->getDrivers();
            }
        });
        
        Flight::route('GET /api/v1/drivers/@id', function($id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $driverController = new \App\Controllers\DriverController($this->logger);
                $driverController->getDriver($id);
            }
        });
        
        Flight::route('POST /api/v1/drivers', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $driverController = new \App\Controllers\DriverController($this->logger);
                $driverController->createDriver();
            }
        });
        
        Flight::route('PUT /api/v1/drivers/@id', function($id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $driverController = new \App\Controllers\DriverController($this->logger);
                $driverController->updateDriver($id);
            }
        });
        
        Flight::route('DELETE /api/v1/drivers/@id', function($id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $driverController = new \App\Controllers\DriverController($this->logger);
                $driverController->deleteDriver($id);
            }
        });

        // Task templates routes (GET: any authenticated; POST/PUT/DELETE: admin, project_manager)
        $taskTemplateController = new \App\Controllers\TaskTemplateController($this->logger);
        Flight::route('GET /api/v1/task-templates', function() use ($authMiddleware, $taskTemplateController) {
            if ($authMiddleware->handle()) {
                $taskTemplateController->index();
            }
        });
        Flight::route('GET /api/v1/task-templates/@id', function($id) use ($authMiddleware, $taskTemplateController) {
            if ($authMiddleware->handle()) {
                $taskTemplateController->get((int) $id);
            }
        });
        Flight::route('POST /api/v1/task-templates', function() use ($authMiddleware, $taskTemplateController) {
            if ($authMiddleware->handle()) {
                $taskTemplateController->create();
            }
        });
        Flight::route('PUT /api/v1/task-templates/@id', function($id) use ($authMiddleware, $taskTemplateController) {
            if ($authMiddleware->handle()) {
                $taskTemplateController->update((int) $id);
            }
        });
        Flight::route('DELETE /api/v1/task-templates/@id', function($id) use ($authMiddleware, $taskTemplateController) {
            if ($authMiddleware->handle()) {
                $taskTemplateController->delete((int) $id);
            }
        });

        // Pharmacy routes v1 (protected)
        Flight::route('GET /api/v1/pharmacies', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $pharmacyController = new \App\Controllers\PharmacyController($this->logger);
                $pharmacyController->getPharmacies();
            }
        });
        
        Flight::route('GET /api/v1/pharmacies/@id', function($id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $pharmacyController = new \App\Controllers\PharmacyController($this->logger);
                $pharmacyController->getPharmacy($id);
            }
        });
        
        Flight::route('POST /api/v1/pharmacies', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $pharmacyController = new \App\Controllers\PharmacyController($this->logger);
                $pharmacyController->createPharmacy();
            }
        });
        
        Flight::route('PUT /api/v1/pharmacies/@id', function($id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $pharmacyController = new \App\Controllers\PharmacyController($this->logger);
                $pharmacyController->updatePharmacy($id);
            }
        });
        
        Flight::route('DELETE /api/v1/pharmacies/@id', function($id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $pharmacyController = new \App\Controllers\PharmacyController($this->logger);
                $pharmacyController->deletePharmacy($id);
            }
        });

        // Pharmacist routes v1 (protected)
        Flight::route('GET /api/v1/pharmacists', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $pharmacistController = new \App\Controllers\PharmacistController($this->logger);
                $pharmacistController->getPharmacists();
            }
        });
        
        Flight::route('GET /api/v1/pharmacists/@id', function($id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $pharmacistController = new \App\Controllers\PharmacistController($this->logger);
                $pharmacistController->getPharmacist($id);
            }
        });
        
        Flight::route('POST /api/v1/pharmacists', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $pharmacistController = new \App\Controllers\PharmacistController($this->logger);
                $pharmacistController->createPharmacist();
            }
        });
        
        Flight::route('PUT /api/v1/pharmacists/@id', function($id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $pharmacistController = new \App\Controllers\PharmacistController($this->logger);
                $pharmacistController->updatePharmacist($id);
            }
        });
        
        Flight::route('DELETE /api/v1/pharmacists/@id', function($id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $pharmacistController = new \App\Controllers\PharmacistController($this->logger);
                $pharmacistController->deletePharmacist($id);
            }
        });

        // Physician routes v1 (protected)
        Flight::route('GET /api/v1/physicians', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $physicianController = new \App\Controllers\PhysicianController($this->logger);
                $physicianController->getPhysicians();
            }
        });
        
        Flight::route('GET /api/v1/physicians/@id', function($id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $physicianController = new \App\Controllers\PhysicianController($this->logger);
                $physicianController->getPhysician($id);
            }
        });
        
        Flight::route('POST /api/v1/physicians', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $physicianController = new \App\Controllers\PhysicianController($this->logger);
                $physicianController->createPhysician();
            }
        });
        
        Flight::route('PUT /api/v1/physicians/@id', function($id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $physicianController = new \App\Controllers\PhysicianController($this->logger);
                $physicianController->updatePhysician($id);
            }
        });
        
        Flight::route('DELETE /api/v1/physicians/@id', function($id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $physicianController = new \App\Controllers\PhysicianController($this->logger);
                $physicianController->deletePhysician($id);
            }
        });

        // Medical Clinic routes v1 (protected)
        Flight::route('GET /api/v1/medical-clinics', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $medicalClinicController = new \App\Controllers\MedicalClinicController($this->logger);
                $medicalClinicController->getMedicalClinics();
            }
        });
        
        Flight::route('GET /api/v1/medical-clinics/@id', function($id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $medicalClinicController = new \App\Controllers\MedicalClinicController($this->logger);
                $medicalClinicController->getMedicalClinic($id);
            }
        });
        
        Flight::route('POST /api/v1/medical-clinics', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $medicalClinicController = new \App\Controllers\MedicalClinicController($this->logger);
                $medicalClinicController->createMedicalClinic();
            }
        });
        
        Flight::route('PUT /api/v1/medical-clinics/@id', function($id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $medicalClinicController = new \App\Controllers\MedicalClinicController($this->logger);
                $medicalClinicController->updateMedicalClinic($id);
            }
        });
        
        Flight::route('DELETE /api/v1/medical-clinics/@id', function($id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $medicalClinicController = new \App\Controllers\MedicalClinicController($this->logger);
                $medicalClinicController->deleteMedicalClinic($id);
            }
        });

        Flight::route('GET /api/v1/sendgrid/dynamic-templates', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $controller = new \App\Controllers\ClientCommunicationController(
                    $this->logger,
                    new \App\Services\TwilioService($this->logger),
                    new \App\Services\EmailService($this->logger),
                    new \App\Services\HumbleFaxService($this->logger),
                );
                $controller->listDynamicTemplates();
            }
        });

        // Client outbound communications (SMS / email / fax)
        Flight::route('POST /api/v1/clients/@type/@id/send-sms', function($type, $id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $controller = new \App\Controllers\ClientCommunicationController(
                    $this->logger,
                    new \App\Services\TwilioService($this->logger),
                    new \App\Services\EmailService($this->logger),
                    new \App\Services\HumbleFaxService($this->logger),
                );
                $controller->sendSms((string) $type, (int) $id);
            }
        });

        Flight::route('POST /api/v1/clients/@type/@id/send-email', function($type, $id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $controller = new \App\Controllers\ClientCommunicationController(
                    $this->logger,
                    new \App\Services\TwilioService($this->logger),
                    new \App\Services\EmailService($this->logger),
                    new \App\Services\HumbleFaxService($this->logger),
                );
                $controller->sendEmail((string) $type, (int) $id);
            }
        });

        Flight::route('POST /api/v1/clients/@type/@id/send-fax', function($type, $id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $controller = new \App\Controllers\ClientCommunicationController(
                    $this->logger,
                    new \App\Services\TwilioService($this->logger),
                    new \App\Services\EmailService($this->logger),
                    new \App\Services\HumbleFaxService($this->logger),
                );
                $controller->sendFax((string) $type, (int) $id);
            }
        });

        Flight::route('POST /api/v1/clients/@type/send-sms/bulk', function($type) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $controller = new \App\Controllers\ClientCommunicationController(
                    $this->logger,
                    new \App\Services\TwilioService($this->logger),
                    new \App\Services\EmailService($this->logger),
                    new \App\Services\HumbleFaxService($this->logger),
                );
                $controller->sendBulkSms((string) $type);
            }
        });

        Flight::route('POST /api/v1/clients/@type/send-email/bulk', function($type) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $controller = new \App\Controllers\ClientCommunicationController(
                    $this->logger,
                    new \App\Services\TwilioService($this->logger),
                    new \App\Services\EmailService($this->logger),
                    new \App\Services\HumbleFaxService($this->logger),
                );
                $controller->sendBulkEmail((string) $type);
            }
        });

        // SMS meeting slot invites (PM schedules call; client replies 1/2/3)
        Flight::route('GET /api/v1/meeting-invite/day-schedule', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $controller = new \App\Controllers\SmsMeetingInviteController(
                    $this->logger,
                    new \App\Services\SmsMeetingInviteService(
                        $this->logger,
                        new \App\Services\TwilioService($this->logger),
                    ),
                );
                $controller->suggestedSlots();
            }
        });

        Flight::route('POST /api/v1/clients/@type/@id/send-meeting-invite', function($type, $id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $controller = new \App\Controllers\SmsMeetingInviteController(
                    $this->logger,
                    new \App\Services\SmsMeetingInviteService(
                        $this->logger,
                        new \App\Services\TwilioService($this->logger),
                    ),
                );
                $controller->send((string) $type, (int) $id);
            }
        });

        Flight::route('GET /api/v1/clients/@type/@id/meeting-invite/latest', function($type, $id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $controller = new \App\Controllers\SmsMeetingInviteController(
                    $this->logger,
                    new \App\Services\SmsMeetingInviteService(
                        $this->logger,
                        new \App\Services\TwilioService($this->logger),
                    ),
                );
                $controller->latest((string) $type, (int) $id);
            }
        });

        // Twilio inbound SMS webhook (no JWT — Twilio signature validation)
        Flight::route('POST /api/v1/twilio/sms/inbound', function() {
            $controller = new \App\Controllers\TwilioSmsWebhookController(
                $this->logger,
                new \App\Services\SmsMeetingInviteService(
                    $this->logger,
                    new \App\Services\TwilioService($this->logger),
                ),
                new \App\Services\TwilioService($this->logger),
            );
            $controller->inboundSms();
        });

        // Role routes v1 (protected)
        Flight::route('GET /api/v1/roles', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $roleController = new \App\Controllers\RoleController($this->logger);
                $roleController->getRoles();
            }
        });

        // Geography routes v1 (protected)
        Flight::route('GET /api/v1/geography/countries-regions', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $geographyController = new \App\Controllers\GeographyController($this->logger);
                $geographyController->getCountriesAndRegions();
            }
        });
        
        Flight::route('GET /api/v1/geography/countries', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $geographyController = new \App\Controllers\GeographyController($this->logger);
                $geographyController->getCountries();
            }
        });
        
        Flight::route('GET /api/v1/geography/countries/@countryCode/regions', function($countryCode) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $geographyController = new \App\Controllers\GeographyController($this->logger);
                $geographyController->getRegionsByCountry($countryCode);
            }
        });

        // Worker management routes v1 (protected)
        Flight::route('GET /api/v1/workers', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $workerController = new \App\Controllers\WorkerController($this->logger);
                $workerController->getWorkers();
            }
        });
        
        Flight::route('POST /api/v1/workers/invite', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $workerController = new \App\Controllers\WorkerController($this->logger);
                $workerController->sendInvitation();
            }
        });
        
        Flight::route('GET /api/v1/workers/email-providers', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $workerController = new \App\Controllers\WorkerController($this->logger);
                $workerController->getEmailProviders();
            }
        });

        // Registration routes v1 (public - no auth required)
        Flight::route('GET /api/v1/registration/validate/@token', function($token) {
            $registrationController = new \App\Controllers\RegistrationController($this->logger);
            $registrationController->validateToken($token);
        });
        
        Flight::route('POST /api/v1/registration/complete', function() {
            $registrationController = new \App\Controllers\RegistrationController($this->logger);
            $registrationController->completeRegistration();
        });

        // Projects routes
        Flight::route('GET /api/v1/projects', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $projectController = new \App\Controllers\ProjectController($this->logger);
                $projectController->getProjects();
            }
        });

        Flight::route('GET /api/v1/projects/@id', function($id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $projectController = new \App\Controllers\ProjectController($this->logger);
                $projectController->getProject((int)$id);
            }
        });

        Flight::route('POST /api/v1/projects', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $projectController = new \App\Controllers\ProjectController($this->logger);
                $projectController->createProject();
            }
        });

        Flight::route('PUT /api/v1/projects/@id', function($id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $projectController = new \App\Controllers\ProjectController($this->logger);
                $projectController->updateProject((int)$id);
            }
        });

        Flight::route('DELETE /api/v1/projects/@id', function($id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $projectController = new \App\Controllers\ProjectController($this->logger);
                $projectController->deleteProject((int)$id);
            }
        });

        // Clients routes
        Flight::route('GET /api/v1/clients/@clientTable', function($clientTable) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $clientController = new \App\Controllers\ClientController($this->logger);
                $clientController->searchClients($clientTable);
            }
        });

        Flight::route('GET /api/v1/clients/@clientTable/@clientId', function($clientTable, $clientId) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $clientController = new \App\Controllers\ClientController($this->logger);
                $clientController->getClientById($clientTable, (int)$clientId);
            }
        });

        // Tasks routes
        Flight::route('GET /api/v1/projects/@project_id/tasks', function($project_id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $taskController = new \App\Controllers\TaskController($this->logger);
                $taskController->getTasks((int)$project_id);
            }
        });

        // Специальные маршруты задач (должны быть ПЕРЕД общими маршрутами с @task_id)
        Flight::route('GET /api/v1/projects/@project_id/tasks/check-bounds', function($project_id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $taskController = new \App\Controllers\TaskController($this->logger);
                $taskController->checkTaskBounds((int)$project_id);
            }
        });

        Flight::route('GET /api/v1/projects/@project_id/tasks/stats', function($project_id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $taskController = new \App\Controllers\TaskController($this->logger);
                $taskController->getTaskStats((int)$project_id);
            }
        });

        Flight::route('PUT /api/v1/projects/@project_id/tasks/reorder', function($project_id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $taskController = new \App\Controllers\TaskController($this->logger);
                $taskController->reorderTasks((int)$project_id);
            }
        });

        Flight::route('PUT /api/v1/projects/@project_id/tasks/normalize-order', function($project_id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $taskController = new \App\Controllers\TaskController($this->logger);
                $taskController->normalizeTaskOrder((int)$project_id);
            }
        });

        // Маршруты для зависимостей
        Flight::route('POST /api/v1/projects/@project_id/dependencies', function($project_id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $taskController = new \App\Controllers\TaskController($this->logger);
                $taskController->createDependency((int)$project_id);
            }
        });

        Flight::route('PUT /api/v1/dependencies/@dependency_id', function($dependency_id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $taskController = new \App\Controllers\TaskController($this->logger);
                $taskController->updateDependency((int)$dependency_id);
            }
        });

        Flight::route('DELETE /api/v1/dependencies/@dependency_id', function($dependency_id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $taskController = new \App\Controllers\TaskController($this->logger);
                $taskController->deleteDependency((int)$dependency_id);
            }
        });

        Flight::route('GET /api/v1/projects/@project_id/dependencies', function($project_id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $taskController = new \App\Controllers\TaskController($this->logger);
                $taskController->getProjectDependencies((int)$project_id);
            }
        });

        Flight::route('GET /api/v1/tasks/@task_id/dependencies', function($task_id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $taskController = new \App\Controllers\TaskController($this->logger);
                $taskController->getTaskDependencies((int)$task_id);
            }
        });

        // Общие маршруты задач
        Flight::route('GET /api/v1/projects/@project_id/tasks/@task_id', function($project_id, $task_id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $taskController = new \App\Controllers\TaskController($this->logger);
                $taskController->getTask((int)$project_id, (int)$task_id);
            }
        });

        Flight::route('POST /api/v1/projects/@project_id/tasks', function($project_id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $taskController = new \App\Controllers\TaskController($this->logger);
                $taskController->createTask((int)$project_id);
            }
        });

        Flight::route('PUT /api/v1/projects/@project_id/tasks/@task_id', function($project_id, $task_id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $taskController = new \App\Controllers\TaskController($this->logger);
                $taskController->updateTask((int)$project_id, (int)$task_id);
            }
        });

        Flight::route('POST /api/v1/projects/@project_id/tasks/@task_id/submit', function($project_id, $task_id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $taskController = new \App\Controllers\TaskController($this->logger);
                $taskController->submitTask((int)$project_id, (int)$task_id);
            }
        });

        $taskFieldPhotoController = new TaskFieldPhotoController($this->logger);
        Flight::route('GET /api/v1/projects/@project_id/tasks/@task_id/field-photos', function($project_id, $task_id) use ($authMiddleware, $taskFieldPhotoController) {
            if ($authMiddleware->handle()) {
                $taskFieldPhotoController->index((int) $project_id, (int) $task_id);
            }
        });
        Flight::route('POST /api/v1/projects/@project_id/tasks/@task_id/field-photos', function($project_id, $task_id) use ($authMiddleware, $taskFieldPhotoController) {
            if ($authMiddleware->handle()) {
                $taskFieldPhotoController->upload((int) $project_id, (int) $task_id);
            }
        });
        Flight::route('GET /api/v1/projects/@project_id/tasks/@task_id/field-photos/@photo_id/download', function($project_id, $task_id, $photo_id) use ($authMiddleware, $taskFieldPhotoController) {
            if ($authMiddleware->handle()) {
                $taskFieldPhotoController->download((int) $project_id, (int) $task_id, (int) $photo_id);
            }
        });
        Flight::route('DELETE /api/v1/projects/@project_id/tasks/@task_id/field-photos/@photo_id', function($project_id, $task_id, $photo_id) use ($authMiddleware, $taskFieldPhotoController) {
            if ($authMiddleware->handle()) {
                $taskFieldPhotoController->delete((int) $project_id, (int) $task_id, (int) $photo_id);
            }
        });

        Flight::route('DELETE /api/v1/projects/@project_id/tasks/@task_id', function($project_id, $task_id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $taskController = new \App\Controllers\TaskController($this->logger);
                $taskController->deleteTask((int)$project_id, (int)$task_id);
            }
        });

        Flight::route('GET /api/v1/tasks/@task_id/available-workers', function($task_id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $taskController = new \App\Controllers\TaskController($this->logger);
                $taskController->getAvailableWorkers((int)$task_id);
            }
        });

        // Task team members (including invited people)
        Flight::route('GET /api/v1/projects/@project_id/tasks/@task_id/team', function($project_id, $task_id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $taskController = new \App\Controllers\TaskController($this->logger);
                $taskController->getTaskTeam((int)$project_id, (int)$task_id);
            }
        });

        // Plans (folders/files)
        Flight::route('GET /api/v1/plan/folders/tree', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $planController = new \App\Controllers\PlanController($this->logger);
                $planController->getFolderTree();
            }
        });

        Flight::route('POST /api/v1/plan/folders', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $planController = new \App\Controllers\PlanController($this->logger);
                $planController->createFolder();
            }
        });

        Flight::route('DELETE /api/v1/plan/folders/@folderId', function($folderId) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $planController = new \App\Controllers\PlanController($this->logger);
                $planController->deleteFolder((int)$folderId);
            }
        });

        Flight::route('GET /api/v1/plan/folders/@folderId/content', function($folderId) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $planController = new \App\Controllers\PlanController($this->logger);
                $planController->getFolderContent((int)$folderId);
            }
        });

        Flight::route('POST /api/v1/plan/files/upload', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $planController = new \App\Controllers\PlanController($this->logger);
                $planController->uploadFile();
            }
        });

        Flight::route('DELETE /api/v1/plan/files/@fileId', function($fileId) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $planController = new \App\Controllers\PlanController($this->logger);
                $planController->deleteFile((int)$fileId);
            }
        });

        Flight::route('GET /api/v1/plan/files/@fileId/download', function($fileId) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $planController = new \App\Controllers\PlanController($this->logger);
                $planController->downloadFile((int)$fileId);
            }
        });

        // File copy/move operations
        Flight::route('PUT /api/v1/plan/files/@fileId/move', function($fileId) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $planController = new \App\Controllers\PlanController($this->logger);
                $planController->moveFile((int)$fileId);
            }
        });

        Flight::route('POST /api/v1/plan/files/@fileId/copy', function($fileId) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $planController = new \App\Controllers\PlanController($this->logger);
                $planController->copyFile((int)$fileId);
            }
        });

        // Folder copy/move operations
        Flight::route('PUT /api/v1/plan/folders/@folderId/move', function($folderId) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $planController = new \App\Controllers\PlanController($this->logger);
                $planController->moveFolder((int)$folderId);
            }
        });

        Flight::route('POST /api/v1/plan/folders/@folderId/copy', function($folderId) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $planController = new \App\Controllers\PlanController($this->logger);
                $planController->copyFolder((int)$folderId);
            }
        });

        // File and folder rename operations
        Flight::route('PUT /api/v1/plan/files/@fileId/rename', function($fileId) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $planController = new \App\Controllers\PlanController($this->logger);
                $planController->renameFile((int)$fileId);
            }
        });

        Flight::route('PUT /api/v1/plan/folders/@folderId/rename', function($folderId) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $planController = new \App\Controllers\PlanController($this->logger);
                $planController->renameFolder((int)$folderId);
            }
        });

        // File description update
        Flight::route('PUT /api/v1/plan/files/@fileId/description', function($fileId) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $planController = new \App\Controllers\PlanController($this->logger);
                $planController->updateFileDescription((int)$fileId);
            }
        });

        // Project team routes
        Flight::route('GET /api/v1/projects/@project_id/team', function($project_id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $teamController = new \App\Controllers\ProjectTeamController($this->logger);
                Flight::json($teamController->getTeamMembers((int)$project_id));
            }
        });

        Flight::route('POST /api/v1/projects/@project_id/team', function($project_id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $teamController = new \App\Controllers\ProjectTeamController($this->logger);
                Flight::json($teamController->addTeamMember((int)$project_id));
            }
        });

        Flight::route('PUT /api/v1/projects/@project_id/team/@team_member_id', function($project_id, $team_member_id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $teamController = new \App\Controllers\ProjectTeamController($this->logger);
                Flight::json($teamController->updateTeamMember((int)$project_id, (int)$team_member_id));
            }
        });

        Flight::route('DELETE /api/v1/projects/@project_id/team/@team_member_id', function($project_id, $team_member_id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $teamController = new \App\Controllers\ProjectTeamController($this->logger);
                Flight::json($teamController->removeTeamMember((int)$project_id, (int)$team_member_id));
            }
        });

        $scheduleWeekController = new ScheduleWeekController($this->logger);
        Flight::route('GET /api/v1/projects/@project_id/schedule-weeks', function($project_id) use ($authMiddleware, $scheduleWeekController) {
            if ($authMiddleware->handle()) {
                $scheduleWeekController->getWeek((int) $project_id);
            }
        });
        Flight::route('POST /api/v1/projects/@project_id/schedule-weeks', function($project_id) use ($authMiddleware, $scheduleWeekController) {
            if ($authMiddleware->handle()) {
                $scheduleWeekController->ensureDraftWeek((int) $project_id);
            }
        });
        Flight::route('PUT /api/v1/projects/@project_id/schedule-weeks/@week_id/entries', function($project_id, $week_id) use ($authMiddleware, $scheduleWeekController) {
            if ($authMiddleware->handle()) {
                $scheduleWeekController->replaceEntries((int) $project_id, (int) $week_id);
            }
        });
        Flight::route('POST /api/v1/projects/@project_id/schedule-weeks/@week_id/publish', function($project_id, $week_id) use ($authMiddleware, $scheduleWeekController) {
            if ($authMiddleware->handle()) {
                $scheduleWeekController->publishWeek((int) $project_id, (int) $week_id);
            }
        });
        Flight::route('POST /api/v1/projects/@project_id/schedule-weeks/@week_id/reopen-as-draft', function($project_id, $week_id) use ($authMiddleware, $scheduleWeekController) {
            if ($authMiddleware->handle()) {
                $scheduleWeekController->reopenAsDraft((int) $project_id, (int) $week_id);
            }
        });

        $calendarController = new CalendarController($this->logger);
        Flight::route('GET /api/v1/calendar/events', function() use ($authMiddleware, $calendarController) {
            if ($authMiddleware->handle()) {
                $calendarController->listGlobal();
            }
        });
        Flight::route('GET /api/v1/calendar/availability', function() use ($authMiddleware, $calendarController) {
            if ($authMiddleware->handle()) {
                $calendarController->checkAvailability();
            }
        });
        Flight::route('POST /api/v1/calendar/events', function() use ($authMiddleware, $calendarController) {
            if ($authMiddleware->handle()) {
                $calendarController->createGlobal();
            }
        });
        Flight::route('PUT /api/v1/calendar/events/@event_id', function($event_id) use ($authMiddleware, $calendarController) {
            if ($authMiddleware->handle()) {
                $calendarController->updateGlobal((int) $event_id);
            }
        });
        Flight::route('DELETE /api/v1/calendar/events/@event_id', function($event_id) use ($authMiddleware, $calendarController) {
            if ($authMiddleware->handle()) {
                $calendarController->deleteGlobal((int) $event_id);
            }
        });
        Flight::route('GET /api/v1/projects/@project_id/calendar/events', function($project_id) use ($authMiddleware, $calendarController) {
            if ($authMiddleware->handle()) {
                $calendarController->listForProject((int) $project_id);
            }
        });
        Flight::route('POST /api/v1/projects/@project_id/calendar/events', function($project_id) use ($authMiddleware, $calendarController) {
            if ($authMiddleware->handle()) {
                $calendarController->createForProject((int) $project_id);
            }
        });
        Flight::route('PUT /api/v1/projects/@project_id/calendar/events/@event_id', function($project_id, $event_id) use ($authMiddleware, $calendarController) {
            if ($authMiddleware->handle()) {
                $calendarController->updateForProject((int) $project_id, (int) $event_id);
            }
        });
        Flight::route('DELETE /api/v1/projects/@project_id/calendar/events/@event_id', function($project_id, $event_id) use ($authMiddleware, $calendarController) {
            if ($authMiddleware->handle()) {
                $calendarController->deleteForProject((int) $project_id, (int) $event_id);
            }
        });

        $scheduleEntryMessageController = new ScheduleEntryMessageController($this->logger);
        $scheduleEntryDocumentController = new ScheduleEntryDocumentController($this->logger);
        Flight::route('GET /api/v1/projects/@project_id/schedule-entries/@schedule_entry_id/messages', function($project_id, $schedule_entry_id) use ($authMiddleware, $scheduleEntryMessageController) {
            if ($authMiddleware->handle()) {
                $scheduleEntryMessageController->index((int) $project_id, (int) $schedule_entry_id);
            }
        });
        Flight::route('POST /api/v1/projects/@project_id/schedule-entries/@schedule_entry_id/messages', function($project_id, $schedule_entry_id) use ($authMiddleware, $scheduleEntryMessageController) {
            if ($authMiddleware->handle()) {
                $scheduleEntryMessageController->create((int) $project_id, (int) $schedule_entry_id);
            }
        });
        // Alias (same handler): some clients use worker-task-schedules in the path
        Flight::route('GET /api/v1/projects/@project_id/worker-task-schedules/@schedule_entry_id/messages', function($project_id, $schedule_entry_id) use ($authMiddleware, $scheduleEntryMessageController) {
            if ($authMiddleware->handle()) {
                $scheduleEntryMessageController->index((int) $project_id, (int) $schedule_entry_id);
            }
        });
        Flight::route('POST /api/v1/projects/@project_id/worker-task-schedules/@schedule_entry_id/messages', function($project_id, $schedule_entry_id) use ($authMiddleware, $scheduleEntryMessageController) {
            if ($authMiddleware->handle()) {
                $scheduleEntryMessageController->create((int) $project_id, (int) $schedule_entry_id);
            }
        });
        Flight::route('GET /api/v1/projects/@project_id/schedule-entries/@schedule_entry_id/documents', function($project_id, $schedule_entry_id) use ($authMiddleware, $scheduleEntryDocumentController) {
            if ($authMiddleware->handle()) {
                $scheduleEntryDocumentController->index((int) $project_id, (int) $schedule_entry_id);
            }
        });
        Flight::route('POST /api/v1/projects/@project_id/schedule-entries/@schedule_entry_id/documents', function($project_id, $schedule_entry_id) use ($authMiddleware, $scheduleEntryDocumentController) {
            if ($authMiddleware->handle()) {
                $scheduleEntryDocumentController->upload((int) $project_id, (int) $schedule_entry_id);
            }
        });
        Flight::route('GET /api/v1/projects/@project_id/schedule-entries/@schedule_entry_id/documents/@document_id/download', function($project_id, $schedule_entry_id, $document_id) use ($authMiddleware, $scheduleEntryDocumentController) {
            if ($authMiddleware->handle()) {
                $scheduleEntryDocumentController->download((int) $project_id, (int) $schedule_entry_id, (int) $document_id);
            }
        });
        Flight::route('DELETE /api/v1/projects/@project_id/schedule-entries/@schedule_entry_id/documents/@document_id', function($project_id, $schedule_entry_id, $document_id) use ($authMiddleware, $scheduleEntryDocumentController) {
            if ($authMiddleware->handle()) {
                $scheduleEntryDocumentController->delete((int) $project_id, (int) $schedule_entry_id, (int) $document_id);
            }
        });
        Flight::route('PATCH /api/v1/projects/@project_id/schedule-entries/@schedule_entry_id/documents/@document_id', function($project_id, $schedule_entry_id, $document_id) use ($authMiddleware, $scheduleEntryDocumentController) {
            if ($authMiddleware->handle()) {
                $scheduleEntryDocumentController->updateDisplayName((int) $project_id, (int) $schedule_entry_id, (int) $document_id);
            }
        });
        Flight::route('PATCH /api/v1/projects/@project_id/schedule-entries/@schedule_entry_id/documents/@document_id/rename', function($project_id, $schedule_entry_id, $document_id) use ($authMiddleware, $scheduleEntryDocumentController) {
            if ($authMiddleware->handle()) {
                $scheduleEntryDocumentController->updateDisplayName((int) $project_id, (int) $schedule_entry_id, (int) $document_id);
            }
        });
        // Alias routes for worker contour (same handlers)
        Flight::route('GET /api/v1/projects/@project_id/worker-task-schedules/@schedule_entry_id/documents', function($project_id, $schedule_entry_id) use ($authMiddleware, $scheduleEntryDocumentController) {
            if ($authMiddleware->handle()) {
                $scheduleEntryDocumentController->index((int) $project_id, (int) $schedule_entry_id);
            }
        });
        Flight::route('POST /api/v1/projects/@project_id/worker-task-schedules/@schedule_entry_id/documents', function($project_id, $schedule_entry_id) use ($authMiddleware, $scheduleEntryDocumentController) {
            if ($authMiddleware->handle()) {
                $scheduleEntryDocumentController->upload((int) $project_id, (int) $schedule_entry_id);
            }
        });
        Flight::route('GET /api/v1/projects/@project_id/worker-task-schedules/@schedule_entry_id/documents/@document_id/download', function($project_id, $schedule_entry_id, $document_id) use ($authMiddleware, $scheduleEntryDocumentController) {
            if ($authMiddleware->handle()) {
                $scheduleEntryDocumentController->download((int) $project_id, (int) $schedule_entry_id, (int) $document_id);
            }
        });
        Flight::route('DELETE /api/v1/projects/@project_id/worker-task-schedules/@schedule_entry_id/documents/@document_id', function($project_id, $schedule_entry_id, $document_id) use ($authMiddleware, $scheduleEntryDocumentController) {
            if ($authMiddleware->handle()) {
                $scheduleEntryDocumentController->delete((int) $project_id, (int) $schedule_entry_id, (int) $document_id);
            }
        });
        Flight::route('PATCH /api/v1/projects/@project_id/worker-task-schedules/@schedule_entry_id/documents/@document_id', function($project_id, $schedule_entry_id, $document_id) use ($authMiddleware, $scheduleEntryDocumentController) {
            if ($authMiddleware->handle()) {
                $scheduleEntryDocumentController->updateDisplayName((int) $project_id, (int) $schedule_entry_id, (int) $document_id);
            }
        });
        Flight::route('PATCH /api/v1/projects/@project_id/worker-task-schedules/@schedule_entry_id/documents/@document_id/rename', function($project_id, $schedule_entry_id, $document_id) use ($authMiddleware, $scheduleEntryDocumentController) {
            if ($authMiddleware->handle()) {
                $scheduleEntryDocumentController->updateDisplayName((int) $project_id, (int) $schedule_entry_id, (int) $document_id);
            }
        });

        // Event logs routes
        $eventLogController = new EventLogController($this->logger);
        
        // Language routes
        $languageController = new LanguageController($this->logger);
        
        // Event rules routes
        Flight::route('GET /api/v1/event-rules', [$eventLogController, 'getEventRules']);
        
        Flight::route('GET /api/v1/event-logs', [$eventLogController, 'getEventLogs']);
        Flight::route('GET /api/v1/event-logs/@id', [$eventLogController, 'getEventLog']);
        Flight::route('POST /api/v1/event-logs', [$eventLogController, 'createEventLog']);
        Flight::route('GET /api/v1/event-logs/outbox/pending', [$eventLogController, 'getPendingOutboxEvents']);
        Flight::route('PUT /api/v1/event-logs/outbox/@id/status', [$eventLogController, 'updateOutboxEventStatus']);

        // N8N Integration routes
        $n8nController = new N8nIntegrationController($this->logger);
        
        // Manual trigger webhook (for button clicks, time range changes)
        Flight::route('POST /api/v1/n8n/webhook/manual-trigger', [$n8nController, 'manualTriggerWebhook']);
        
        // Scheduled data collection (for automated reports)
        Flight::route('GET /api/v1/n8n/scheduled/data-collection', [$n8nController, 'scheduledDataCollection']);
        
        // Workflow status tracking
        Flight::route('GET /api/v1/n8n/workflow/status', [$n8nController, 'getWorkflowStatus']);

        // Language routes
        Flight::route('GET /api/v1/languages', [$languageController, 'getLanguages']);
        Flight::route('POST /api/v1/languages', function() use ($languageController, $authMiddleware) {
            if ($authMiddleware->handle()) {
                $languageController->createLanguage();
            }
        });
        Flight::route('PUT /api/v1/languages/@languageId', function($languageId) use ($languageController, $authMiddleware) {
            if ($authMiddleware->handle()) {
                $languageController->updateLanguage($languageId);
            }
        });

        // Worker language routes
        Flight::route('GET /api/v1/workers/@workerId/languages', [$languageController, 'getWorkerLanguages']);
        Flight::route('POST /api/v1/workers/@workerId/languages', function($workerId) use ($languageController, $authMiddleware) {
            if ($authMiddleware->handle()) {
                $languageController->addWorkerLanguage((int)$workerId);
            }
        });
        Flight::route('PUT /api/v1/workers/@workerId/languages/@languageId', function($workerId, $languageId) use ($languageController, $authMiddleware) {
            if ($authMiddleware->handle()) {
                $languageController->updateWorkerLanguage((int)$workerId, (int)$languageId);
            }
        });
        Flight::route('DELETE /api/v1/workers/@workerId/languages/@languageId', function($workerId, $languageId) use ($languageController, $authMiddleware) {
            if ($authMiddleware->handle()) {
                $languageController->removeWorkerLanguage((int)$workerId, (int)$languageId);
            }
        });

        // Маршруты для обработки событий и уведомлений
        Flight::route('POST /api/v1/events/process', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $eventController = new \App\Controllers\EventProcessingController($this->logger);
                $eventController->processEvents();
            }
        });

        Flight::route('POST /api/v1/reports/daily', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $eventController = new \App\Controllers\EventProcessingController($this->logger);
                $eventController->generateDailyReport();
            }
        });

        Flight::route('GET /api/v1/events/stats', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $eventController = new \App\Controllers\EventProcessingController($this->logger);
                $eventController->getEventStats();
            }
        });

        // Маршруты для управления правилами событий
        Flight::route('GET /api/v1/admin/event-rules/conditions', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $rulesController = new \App\Controllers\EventRulesController($this->logger);
                $rulesController->getAvailableConditions();
            }
        });

        Flight::route('GET /api/v1/admin/event-rules/actions', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $rulesController = new \App\Controllers\EventRulesController($this->logger);
                $rulesController->getAvailableActions();
            }
        });

        Flight::route('GET /api/v1/admin/event-rules', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $rulesController = new \App\Controllers\EventRulesController($this->logger);
                $rulesController->getAllRules();
            }
        });

        Flight::route('GET /api/v1/admin/event-rules/@event_type', function($event_type) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $rulesController = new \App\Controllers\EventRulesController($this->logger);
                $rulesController->getRule($event_type);
            }
        });

        Flight::route('POST /api/v1/admin/event-rules', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $rulesController = new \App\Controllers\EventRulesController($this->logger);
                $rulesController->createRule();
            }
        });

        Flight::route('PUT /api/v1/admin/event-rules/@event_type', function($event_type) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $rulesController = new \App\Controllers\EventRulesController($this->logger);
                $rulesController->updateRule($event_type);
            }
        });

        Flight::route('DELETE /api/v1/admin/event-rules/@event_type', function($event_type) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $rulesController = new \App\Controllers\EventRulesController($this->logger);
                $rulesController->deleteRule($event_type);
            }
        });

        // Маршруты для управления шаблонами сообщений
        Flight::route('GET /api/v1/admin/message-templates', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $templatesController = new MessageTemplatesController($this->database, $this->logger);
                $templatesController->getAllTemplates();
            }
        });

        Flight::route('GET /api/v1/admin/message-templates/@id', function($id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $templatesController = new MessageTemplatesController($this->database, $this->logger);
                $templatesController->getTemplate((int)$id);
            }
        });

        Flight::route('POST /api/v1/admin/message-templates', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $templatesController = new MessageTemplatesController($this->database, $this->logger);
                $templatesController->createTemplate();
            }
        });

        Flight::route('PUT /api/v1/admin/message-templates/@id', function($id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $templatesController = new MessageTemplatesController($this->database, $this->logger);
                $templatesController->updateTemplate((int)$id);
            }
        });

        Flight::route('DELETE /api/v1/admin/message-templates/@id', function($id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $templatesController = new MessageTemplatesController($this->database, $this->logger);
                $templatesController->deleteTemplate((int)$id);
            }
        });

        Flight::route('GET /api/v1/admin/message-templates/by-event/@event_type', function($event_type) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $templatesController = new MessageTemplatesController($this->database, $this->logger);
                $templatesController->getTemplatesByEvent($event_type);
            }
        });
    }
}
