<?php

namespace App\Routes;

use App\Controllers\HealthController;
use App\Controllers\DatabaseController;
use App\Controllers\AuthController;
use App\Controllers\GeographyController;
use App\Controllers\WorkerController;
use App\Controllers\RegistrationController;
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
        // Add CORS headers for all API routes
        Flight::before('start', function() {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
            header('Access-Control-Allow-Credentials: true');
            
            if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
                http_response_code(200);
                exit();
            }
        });

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
        Flight::route('GET /api/v1/auth/validate-invitation-token', [new AuthController($this->logger), 'validateInvitationToken']);
        Flight::route('POST /api/v1/auth/change-password', [new AuthController($this->logger), 'changePassword']);
        
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

        // Patient routes v1 (protected)
        Flight::route('GET /api/v1/patients', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $patientController = new \App\Controllers\PatientController($this->logger);
                $patientController->getPatients();
            }
        });
        
        Flight::route('GET /api/v1/patients/@id', function($id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $patientController = new \App\Controllers\PatientController($this->logger);
                $patientController->getPatient($id);
            }
        });
        
        Flight::route('GET /api/v1/patients/search', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $patientController = new \App\Controllers\PatientController($this->logger);
                $patientController->getPatient();
            }
        });
        
        Flight::route('POST /api/v1/patients', function() use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $patientController = new \App\Controllers\PatientController($this->logger);
                $patientController->createPatient();
            }
        });
        
        Flight::route('PUT /api/v1/patients/@id', function($id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $patientController = new \App\Controllers\PatientController($this->logger);
                $patientController->updatePatient($id);
            }
        });
        
        Flight::route('DELETE /api/v1/patients/@id', function($id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $patientController = new \App\Controllers\PatientController($this->logger);
                $patientController->deletePatient($id);
            }
        });

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
    }
}
