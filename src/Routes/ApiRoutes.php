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
        file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - ApiRoutes constructor called' . PHP_EOL, FILE_APPEND);
        $this->logger = $logger;
        file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - About to call register() method' . PHP_EOL, FILE_APPEND);
        try {
            $this->register();
            file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - register() method completed successfully' . PHP_EOL, FILE_APPEND);
        } catch (\Exception $e) {
            file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - ERROR in register() method: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
            throw $e;
        }
        file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - ApiRoutes constructor completed' . PHP_EOL, FILE_APPEND);
    }

    public function register(): void
    {
        file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - register() method called' . PHP_EOL, FILE_APPEND);
        
        // API v1 routes
        file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - About to call registerV1Routes()' . PHP_EOL, FILE_APPEND);
        try {
            $this->registerV1Routes();
            file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - registerV1Routes() completed' . PHP_EOL, FILE_APPEND);
        } catch (\Exception $e) {
            file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - ERROR in registerV1Routes: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
            throw $e;
        }
        
        // Swagger documentation routes
        file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Registering Swagger routes' . PHP_EOL, FILE_APPEND);
        file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Current directory: ' . __DIR__ . PHP_EOL, FILE_APPEND);
        file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Public directory path: ' . __DIR__ . '/../public/' . PHP_EOL, FILE_APPEND);
        
        Flight::route('GET /swagger.json', function() {
            file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Swagger JSON route called' . PHP_EOL, FILE_APPEND);
            try {
                $filePath = __DIR__ . '/../../public/swagger-simple.php';
                file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Swagger file path: ' . $filePath . PHP_EOL, FILE_APPEND);
                if (!file_exists($filePath)) {
                    throw new \Exception('Swagger file not found: ' . $filePath);
                }
                file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Swagger file exists, loading...' . PHP_EOL, FILE_APPEND);
                require_once $filePath;
            } catch (\Exception $e) {
                file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Swagger JSON error: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
                Flight::json(['error' => 'Failed to load Swagger specification'], 500);
            }
        });

        Flight::route('GET /docs', function() {
            file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Swagger UI /docs route called' . PHP_EOL, FILE_APPEND);
            try {
                $filePath = __DIR__ . '/../../public/swagger-ui-simple.php';
                file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Swagger UI file path: ' . $filePath . PHP_EOL, FILE_APPEND);
                file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Current directory: ' . __DIR__ . PHP_EOL, FILE_APPEND);
                file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - File exists check: ' . (file_exists($filePath) ? 'YES' : 'NO') . PHP_EOL, FILE_APPEND);
                if (!file_exists($filePath)) {
                    throw new \Exception('Swagger UI file not found: ' . $filePath);
                }
                file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Swagger UI file exists, loading...' . PHP_EOL, FILE_APPEND);
                require_once $filePath;
            } catch (\Exception $e) {
                file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Swagger UI error: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
                Flight::json(['error' => 'Failed to load Swagger UI'], 500);
            }
        });


        
        
        file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Swagger routes registered successfully' . PHP_EOL, FILE_APPEND);

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
            file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Creating ProfileController and AuthMiddleware' . PHP_EOL, FILE_APPEND);
            
            $twilioService = new \App\Services\TwilioService($this->logger);
            $emailService = new \App\Services\EmailService($this->logger);
            $profileController = new \App\Controllers\ProfileController($this->logger, $twilioService, $emailService);
            $authMiddleware = new \App\Middleware\AuthMiddleware($this->logger);
            
            file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - ProfileController and AuthMiddleware created successfully' . PHP_EOL, FILE_APPEND);
            
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
                file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Work status route called' . PHP_EOL, FILE_APPEND);
                
                if ($authMiddleware->handle()) {
                    file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Auth middleware passed, calling updateWorkStatus' . PHP_EOL, FILE_APPEND);
                    $profileController->updateWorkStatus();
                } else {
                    file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Auth middleware failed' . PHP_EOL, FILE_APPEND);
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
            file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - ERROR creating ProfileController: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
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
            file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - ERROR creating 2FA controllers: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
            throw $e;
        }
    }
}
