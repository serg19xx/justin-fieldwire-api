<?php

namespace App\Routes;

use App\Controllers\HealthController;
use App\Controllers\DatabaseController;
use App\Controllers\AuthController;
use Flight;
use Monolog\Logger;

class ApiRoutes
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->register();
    }

    public function register(): void
    {
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
                                'profile_get' => 'GET /api/v1/profile',
                                'profile_update' => 'PUT /api/v1/profile',
                                'profile_avatar' => 'POST /api/v1/profile/avatar',
                                'profile_2fa_enable' => 'POST /api/v1/profile/2fa/enable',
                                'profile_2fa_disable' => 'POST /api/v1/profile/2fa/disable',
                                '2fa_toggle' => 'POST /api/v1/2fa/toggle'
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
        
        // Legacy auth route for backward compatibility
        Flight::route('POST /auth/login', [new AuthController($this->logger), 'login']);

        // Profile management routes (protected)
        try {
            $twilioService = new \App\Services\TwilioService($this->logger);
            $emailService = new \App\Services\EmailService($this->logger);
            $profileController = new \App\Controllers\ProfileController($this->logger, $twilioService, $emailService);
            $authMiddleware = new \App\Middleware\AuthMiddleware($this->logger);
            
            // Profile routes with auth middleware
            Flight::route('GET /api/v1/profile', function() use ($profileController, $authMiddleware) {
                if ($authMiddleware->handle()) {
                    $profileController->getProfile();
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
            
            // Work status management route
            Flight::route('PUT /api/v1/profile/work-status', function() use ($profileController, $authMiddleware) {
                if ($authMiddleware->handle()) {
                    $profileController->updateWorkStatus();
                }
            });
            
            // 2FA management routes with auth middleware
            Flight::route('POST /api/v1/profile/2fa/enable', function() use ($profileController, $authMiddleware) {
                if ($authMiddleware->handle()) {
                    $profileController->enable2FA();
                }
            });
            
            Flight::route('POST /api/v1/profile/2fa/disable', function() use ($profileController, $authMiddleware) {
                if ($authMiddleware->handle()) {
                    $profileController->disable2FA();
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
            
            Flight::route('POST /api/v1/2fa/send-code', [$twoFactorController, 'sendCode']);
            Flight::route('POST /api/v1/2fa/verify-code', [$twoFactorController, 'verifyCode']);
            Flight::route('POST /api/v1/2fa/enable', [$twoFactorController, 'enable2FA']);
            Flight::route('POST /api/v1/2fa/disable', [$twoFactorController, 'disable2FA']);
            Flight::route('POST /api/v1/2fa/toggle', [$twoFactorController, 'toggle2FA']);
            
            // Legacy 2FA routes
            Flight::route('POST /2fa/send-code', [$twoFactorController, 'sendCode']);
            Flight::route('POST /2fa/verify-code', [$twoFactorController, 'verifyCode']);
            Flight::route('POST /2fa/enable', [$twoFactorController, 'enable2FA']);
            Flight::route('POST /2fa/disable', [$twoFactorController, 'disable2FA']);
            Flight::route('POST /2fa/toggle', [$twoFactorController, 'toggle2FA']);
            
        } catch (\Exception $e) {
            throw $e;
        }
    }
}
