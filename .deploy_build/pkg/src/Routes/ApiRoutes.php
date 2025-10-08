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
use App\Controllers\EventLogController;
use App\Controllers\N8nIntegrationController;
use App\Controllers\LanguageController;
use Flight;
use Monolog\Logger;
use OpenApi\Annotations as OA;

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
                                'auth_logout' => 'POST /api/v1/auth/logout',
                                'auth_check_session' => 'POST /api/v1/auth/check-session',
                                'auth_validate_invitation' => 'GET /api/v1/auth/validate-invitation-token',
                                'auth_change_password' => 'POST /api/v1/auth/change-password',
                                'profile_get' => 'GET /api/v1/profile',
                                'profile_update' => 'PUT /api/v1/profile',
                                'profile_avatar' => 'POST /api/v1/profile/avatar',
                                'profile_2fa_enable' => 'POST /api/v1/profile/2fa/enable',
                                'profile_2fa_disable' => 'POST /api/v1/profile/2fa/disable',
                                '2fa_toggle' => 'POST /api/v1/2fa/toggle',
                                'event_rules' => 'GET /api/v1/event-rules',
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
                                'worker_languages_remove' => 'DELETE /api/v1/workers/{workerId}/languages/{languageId}'
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

        // Tasks routes
        Flight::route('GET /api/v1/projects/@project_id/tasks', function($project_id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $taskController = new \App\Controllers\TaskController($this->logger);
                $taskController->getTasks((int)$project_id);
            }
        });

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

        Flight::route('DELETE /api/v1/projects/@project_id/tasks/@task_id', function($project_id, $task_id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $taskController = new \App\Controllers\TaskController($this->logger);
                $taskController->deleteTask((int)$project_id, (int)$task_id);
            }
        });

        Flight::route('GET /api/v1/projects/@project_id/tasks/check-bounds', function($project_id) use ($authMiddleware) {
            if ($authMiddleware->handle()) {
                $taskController = new \App\Controllers\TaskController($this->logger);
                $taskController->checkTaskBounds((int)$project_id);
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
    }
}
