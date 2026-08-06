<?php

namespace App\Controllers;

use App\Database\Database;
use App\Services\EventLoggingService;
use App\Services\ProjectLifecycleNotificationService;
use App\Services\TaskAuthorizationService;
use Doctrine\DBAL\Exception;
use Flight;
use Monolog\Logger;
use OpenApi\Annotations as OA;

/**
 * @OA\Tag(
 *     name="Projects",
 *     description="Project management endpoints"
 * )
 */
class ProjectController
{
    /** Allowed project status values for POST/PUT /api/v1/projects */
    private const ALLOWED_PROJECT_STATUSES = [
        'Initial Contact Lead',
        'Dead Lead',
        'Waiting On Direction',
        'Actively Looking For A Location',
        'Securing Location',
        'Project Secured',
        'Construction',
        'Completed Project',
    ];
    
    /** Allowed system lifecycle statuses for projects */
    private const ALLOWED_PROJECT_SYS_STATUSES = [
        'Draft',
        'Active',
        'Closing',
        'Suspended',
        'Done',
    ];

    /** Allowed project level values (DB enum) */
    private const ALLOWED_PROJECT_LEVELS = [
        'Basics',
        'Full Service',
        'Medical Nice',
        'High End',
        'Extravagant',
    ];

    /** Allowed clinic model type values */
    private const ALLOWED_CLINIC_MODEL_TYPES = [
        'FFS Solo',
        'FHG',
        'FHO',
        'FHT',
        'Urgent Care FFS',
        'Walk In Clinic FFS',
        'Mix Family Practise & Walk In FFS',
        'Mix Family Practise & Walk In FHG',
        'Other',
        'Specialty Clinic',
    ];

    /** Allowed healthcare services values */
    private const ALLOWED_HEALTHCARE_SERVICES = [
        'Primary Care',
        'Pharmacy',
        'Allied Health',
        'Private Health Services',
        'Dental',
        'Womens Health',
        'Stem Cell',
        'Peptides',
        'PRP',
    ];

    /** Allowed Project Inclusions values */
    private const ALLOWED_PROJECT_INCLUSIONS = [
        "Architectural, Structural & Mechanical",
        "Sprinkler Drawings",
        "Structural Drawings",
        "City Fee's",
        "Key Sets & Keybox",
        "Design & Aesthetic Consultant",
        "Demo Bins",
        "Concrete & Fill Bins",
        "Casual Dump Runs",
        "General Cleaning of Space",
        "Storage Bin",
        "Moving of Equipment & Supplies",
        "Major Equipment Rentals",
        "Block Wall Penetrations",
        "Structural Changes",
        "Sprinkler Install (Supply & Install)",
        "Fire Separation Ceiling or Demising Walls",
        "Fire Alarm, Horns Install",
        "Fire Caulking Materials",
        "Penetrations",
        "HVAC New Units Install",
        "New System",
        "Thermostat",
        "Venting Flashing",
        "Plumbing Fixtures as per Contractor Grade",
        "Electrical Rough In & Finish As Per Drawing",
        "Electrical Decor Finishes",
        "Insulated Partitions",
        "Taping & Patching",
        "TV Inserts",
        "Corner Guards 4'",
        "Corner Guards Full Corner",
        "Network Lines Runs",
        "Network Line Finish",
        "Network Switch Finish",
        "Paint Entire Space",
        "Epoxy Paint",
        "Solid Core Doors With Knockdown Frames",
        "Custom Doors & Frames",
        "Commercial Hardware",
        "Door Stoppers",
        "Barrier Free Bathroom Automation",
        "Entry/Exit Door Automation",
        "Interior Door Automation",
        "Bathroom Door Closers",
        "Door Closers",
        "T-Bar Ceiling & Commercial Tiles",
        "Sound Reduction Tiles",
        "Flooring As Per Drawing With Commercial Finishes",
        "Autoclave Room",
        "Bathroom Vanity",
        "Benches",
        "Charting Room",
        "Diagnostic Room",
        "Doctors Lounge",
        "Exam Rooms",
        "Exercise Room",
        "Hallway Desk",
        "Hallway Storage",
        "Kitchen",
        "Managers Office",
        "Medical Reception",
        "Nursing",
        "Pharmacy",
        "Pharmacy Island",
        "Pharmacy Shelves",
        "Adult Change Table",
        "Baby Change Table",
        "Barrier Free Equipment",
        "Coat Hangers",
        "Door Numbers",
        "Eye Wash Station",
        "Female Hygiene Dispenser",
        "Hand Paper Towel Dispensers",
        "Mirrors",
        "Pictures &/Or Artwork",
        "Soap Dispensers",
        "Toilet Paper Dispenser",
        "Support For Gate",
        "Pharmacy Security Gate",
        "Window Security Gate",
        "Windows Security Gate Install",
        "Install Security System",
        "Install Security Camera System",
        "Baseboard",
        "Commercial Finish Trim",
        "Raised Platform",
        "Recessed TV's",
        "Welcome Mat",
        "Custom Wrapped Doors",
        "Tiled Walls",
        "Skylight Install",
        "Millwork Planter",
        "Custom Wood Wall",
        "Glass Work",
        "Metal Work",
        "Stone Work",
        "Final Commercial Clean",
        "Branding Package",
        "Way Finding",
        "Door Numbers Installed",
        "Install Speaker System",
        "TV Mounts",
        "TV's",
        "Exterior Primary Sign",
        "Windows Signs",
        "Pileon Sign",
    ];

    /** Allowed Long Term Family Medicine team size values */
    private const ALLOWED_LONG_TERM_FM_TEAM_SIZES = [
        'Solo',
        '1-3',
        '4-6',
        '7-10',
        '11-15',
    ];

    private const ALLOWED_HR_VISION_SPECIALTIES = [
        'Anesthesiology', 'Art Therapist', 'Athletic Therapist', 'Audiologist', 'Behaviour Analyst',
        'Cardiology', 'Cardiology Technologist', 'Cardiovascular Perfusionist', 'Child Life Specialist',
        'Chiropodist', 'Clinical Research Professional', 'Cytotechnologist', 'Dental', 'Dental Assistant',
        'Dental Hygienist', 'Dental Technologist', 'Dental Therapist', 'Denturist', 'Dermatology',
        'Diagnostic Medical Sonographer', 'Drama Therapist', 'Electroneurophysiology Technologist',
        'Emergency Medical Responder', 'Emergency Medicine', 'Endocrinology', 'Environmental Health Officer',
        'Exercise Physiologist', 'Family Medicine', 'Gastroenterology', 'General Surgery',
        'Genetic Counsellor', 'Geriatric Medicine', 'Health Information Management Professional',
        'Hearing Instrument Specialist', 'Hematology', 'Histotechnologist', 'Infectious Disease',
        'Internal Medicine', 'Kinesiologist', 'Lactation Consultant',
        'Magnetic Resonance Imaging Technologist', 'Massage Therapist', 'Medical Device Reprocessing Technician',
        'Medical Genetics', 'Medical Laboratory Assistant', 'Medical Laboratory Technologist', 'Music Therapist',
        'Nephrology', 'Neurology', 'Nuclear Medicine', 'Nuclear Medicine Technologist',
        'Nutritionist (regulated in some jurisdictions)', 'Obstetrics and Gynecology', 'Occupational Medicine',
        'Occupational Therapist', 'Operating Department Practitioner', 'Ophthalmic Medical Technologist',
        'Ophthalmology', 'Optician', 'Orthopedic Surgery', 'Orthoptist', 'Orthotist',
        'Orthotist-Prosthetist', 'Otolaryngology', 'Paramedic', "Pathologists' Assistant", 'Pathology',
        'Pediatrics', 'Pedorthist', 'Pharmacist', 'Pharmacy', 'Pharmacy Technician',
        'Physical Medicine and Rehabilitation', 'Physiotherapist', 'Plastic Surgery', 'Podiatrist',
        'Preventive Medicine', 'Prosthetist', 'Psychiatry', 'Psychological Associate', 'Psychologist',
        'Psychotherapist', 'Pulmonary Function Technologist', 'Pulmonology', 'Radiation Therapist',
        'Radiologic Technologist', 'Radiology', 'Recreation Therapist', 'Registered Dietitian',
        'Respiratory Therapist', 'Rheumatology', 'Social Worker', 'Speech-Language Pathologist',
        'Thoracic Surgery', 'Urology',
    ];

    private const ALLOWED_MARKETING_STRATEGIES = [
        'Print Flyer',
        'Digital',
        'Brining a Roster of Patients',
        'Road Signage',
        'Social Media Posting & Ads',
    ];

    /** @var list<string> Operational Hours week days */
    private const OPERATIONAL_HOURS_DAYS = [
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
        'sunday',
    ];

    /** @var list<string> Operational Hours Open/Close select options */
    private const OPERATIONAL_HOURS_TIME_OPTIONS = [
        '24 Hours',
        '6am',
        '7am',
        '8am',
        '9am',
        '10am',
        '11am',
        '12pm',
        '1pm',
        '2pm',
        '3pm',
        '4pm',
        '5pm',
        '6pm',
        '7pm',
        '8pm',
        '9pm',
        '10pm',
    ];

    /** @var array<string, int> Contents of Space calc rows: key => set size (sq/ft) */
    private const CONTENTS_OF_SPACE_CALC_SET_SIZES = [
        'waiting_area' => 24,
        'dedicated_kids_area' => 50,
        'reception_area' => 16,
        'additional_waiting_bay' => 24,
        'nursing_room_office' => 80,
        'triage_room' => 80,
        'baby_area_room' => 60,
        'managers_office' => 80,
        'exam_rooms' => 88,
        'procedure_room' => 168,
        'doctor_lounge_office' => 24,
        'barrier_free_bathroom' => 80,
        'additional_patient_bathrooms' => 32,
        'staff_only_bathrooms' => 32,
        'storage_rooms' => 6,
        'staff_room' => 150,
        'kitchen' => 100,
        'boardroom' => 200,
    ];

    /** @var array<string, list<int>> Contents of Space select rows: key => allowed sq/ft options */
    private const CONTENTS_OF_SPACE_SELECT_OPTIONS = [
        'pharmacy' => [300, 400, 500, 600, 700, 800],
        'specialists_office' => [300, 400, 500, 600, 700, 800],
        'sports_medicine' => [300, 400, 500, 600, 700, 800, 1000, 1200, 1500],
        'allied_health_providers' => [300, 400, 500, 600, 700, 800, 1000, 1200, 1500],
    ];

    private Logger $logger;
    private Database $database;
    private EventLoggingService $eventLoggingService;
    private TaskAuthorizationService $taskAuth;
    private ProjectLifecycleNotificationService $projectLifecycleNotifications;
    private static ?bool $projectForemanColumnExists = null;

    private static ?bool $projectClientsTableExists = null;

    private static ?bool $locationsOfInterestColumnExists = null;

    private static ?bool $clinicModelTypeColumnExists = null;

    private static ?bool $healthcareServicesColumnExists = null;

    private static ?bool $projectInclusionsColumnExists = null;

    private static ?bool $longTermFmTeamSizeColumnExists = null;

    private static ?bool $monthlyBudgetFirstYearColumnExists = null;

    private static ?bool $estClinicalHoursMdsOnSiteColumnExists = null;

    private static ?bool $hrVisionColumnExists = null;

    private static ?bool $operationalHoursColumnExists = null;

    private static ?bool $contentsOfSpaceColumnExists = null;

    private static ?bool $marketingStrategyColumnExists = null;

    /** @var array<string, bool|null> */
    private static array $optionalProjectTextColumnExists = [];

    /** Optional free-text project columns: field => max length */
    private const OPTIONAL_PROJECT_TEXT_FIELDS = [
        'total_doctors' => 100,
        'project_fee_per_doctor' => 100,
        'cost_per_sq_ft' => 100,
        'mark_up' => 100,
        'daily_patient_volumes' => 100,
    ];

    private const ALLOWED_CLIENT_TABLES = ['pharma', 'physician', 'pharmacist', 'medical_clinic'];

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        
        try {
            $this->database = new Database();
            $this->eventLoggingService = new EventLoggingService($this->logger);
            $this->taskAuth = new TaskAuthorizationService();
            $this->projectLifecycleNotifications = new ProjectLifecycleNotificationService($this->logger);
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize ProjectController', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Получить список всех проектов
     * GET /api/v1/projects
     *
     * @OA\Get(
     *     path="/api/v1/projects",
     *     summary="Get all projects",
     *     description="Retrieve a paginated list of all projects",
     *     tags={"Projects"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Page number for pagination",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, default=1)
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         description="Number of items per page",
     *         required=false,
     *         @OA\Schema(type="integer", minimum=1, maximum=100, default=20)
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filter by project status",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="priority",
     *         in="query",
     *         description="Filter by project priority",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Search by project name or address",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="prj_manager",
     *         in="query",
     *         description="Filter by project manager ID",
     *         required=false,
     *         @OA\Schema(type="integer", example=1)
     *     ),
     *     @OA\Parameter(
     *         name="user_id",
     *         in="query",
     *         description="Filter projects where this user is involved (project manager or task assignee)",
     *         required=false,
     *         @OA\Schema(type="integer", example=47)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Projects retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Projects retrieved successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="projects", type="array", @OA\Items(
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="prj_name", type="string", example="Office Building Construction"),
     *                     @OA\Property(property="address", type="string", example="123 Main St, City, State"),
     *                     @OA\Property(property="date_start", type="string", format="date", example="2025-01-01"),
     *                     @OA\Property(property="date_end", type="string", format="date", example="2025-12-31"),
     *                     @OA\Property(property="priority", type="string", example="High"),
     *                     @OA\Property(property="status", type="string", example="Project Secured", description="One of: Initial Contact Lead, Dead Lead, Waiting On Direction, Actively Looking For A Location, Securing Location, Project Secured, Construction, Completed Project"),
     *                     @OA\Property(property="sys_status", type="string", nullable=true, enum={"Draft","Active","Closing","Suspended","Done"}, example="Active"),
     *                     @OA\Property(property="purchase_or_lease", type="string", enum={"Purchase","Lease","Undecided"}, example="Purchase"),
     *                     @OA\Property(property="notes", type="string", nullable=true, example="Additional project notes"),
     *                     @OA\Property(property="client_id", type="integer", nullable=true, example=1),
     *                     @OA\Property(property="client_type", type="string", nullable=true, example="pharmacy"),
     *                     @OA\Property(property="client_table", type="string", enum={"pharma","physician","pharmacist","medical_clinic"}, nullable=true, example="pharma"),
     *                     @OA\Property(property="client_name", type="string", nullable=true, example="Pharmacy Name"),
     *                     @OA\Property(property="client_data", type="object", nullable=true, example={"id":1,"name":"Pharmacy Name","address":"123 Main St"}),
     *                     @OA\Property(property="client2_id", type="integer", nullable=true, example=2),
     *                     @OA\Property(property="client2_type", type="string", nullable=true, example="physician"),
     *                     @OA\Property(property="client2_table", type="string", enum={"pharma","physician","pharmacist","medical_clinic"}, nullable=true, example="physician"),
     *                     @OA\Property(property="client2_name", type="string", nullable=true, example="Second Client Name"),
     *                     @OA\Property(property="client2_data", type="object", nullable=true, example={"id":2,"name":"Second Client","address":"456 Oak St"}),
     *                     @OA\Property(property="prj_manager", type="integer", nullable=true, example=1),
     *                     @OA\Property(property="created_by", type="integer", nullable=true, example=47),
     *                     @OA\Property(property="created_by_name", type="string", nullable=true, example="John Doe"),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time")
     *                 )),
     *                 @OA\Property(property="pagination", type="object",
     *                     @OA\Property(property="current_page", type="integer", example=1),
     *                     @OA\Property(property="per_page", type="integer", example=20),
     *                     @OA\Property(property="total", type="integer", example=100),
     *                     @OA\Property(property="last_page", type="integer", example=5)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized - Invalid or missing token",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=401),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Unauthorized"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     )
     * )
     */
    public function getProjects(): void
    {
        $this->logger->info('ProjectController::getProjects called');
        
        try {
            $request = Flight::request();
            $page = (int)($request->query['page'] ?? 1);
            $limit = min((int)($request->query['limit'] ?? 20), 100);
            $status = $request->query['status'] ?? null;
            $priority = $request->query['priority'] ?? null;
            $search = $request->query['search'] ?? null;
            $prjManager = $request->query['prj_manager'] ?? null;
            $userId = $request->query['user_id'] ?? null;

            $offset = ($page - 1) * $limit;

            $connection = $this->database->getConnection();
            $foremanSelect = $this->projectForemanSelectSql($connection);
            $foremanJoin = $this->projectForemanJoinSql($connection);
            $locationsSelect = $this->locationsOfInterestSelectSql($connection);
            $clinicModelSelect = $this->clinicModelTypeSelectSql($connection);
            $healthcareServicesSelect = $this->healthcareServicesSelectSql($connection);
            $projectInclusionsSelect = $this->projectInclusionsSelectSql($connection);
            $longTermFmTeamSizeSelect = $this->longTermFmTeamSizeSelectSql($connection);
            $monthlyBudgetFirstYearSelect = $this->monthlyBudgetFirstYearSelectSql($connection);
            $estClinicalHoursMdsOnSiteSelect = $this->estClinicalHoursMdsOnSiteSelectSql($connection);
            $hrVisionSelect = $this->hrVisionSelectSql($connection);
            $operationalHoursSelect = $this->operationalHoursSelectSql($connection);
            $contentsOfSpaceSelect = $this->contentsOfSpaceSelectSql($connection);
            $marketingStrategySelect = $this->marketingStrategySelectSql($connection);
            $optionalProjectTextSelect = $this->optionalProjectTextFieldsSelectSql($connection);

            // Базовый SQL запрос
            $sql = "SELECT
                        p.id, p.prj_name, p.address, p.date_start, p.date_end,
                        p.priority, p.status, p.sys_status, p.purchase_or_lease, p.notes, p.client_id, p.client_type, p.client_table, p.client_data, p.client_name,
                        p.client2_id, p.client2_type, p.client2_table, p.client2_data, p.client2_name,
                        p.description, p.area, p.level, p.prj_manager, p.created_by, p.created_at, p.updated_at,
                        u.first_name, u.last_name,
                        creator.first_name as created_by_first_name, creator.last_name as created_by_last_name
                        {$foremanSelect}
                        {$locationsSelect}
                        {$clinicModelSelect}
                        {$healthcareServicesSelect}
                        {$projectInclusionsSelect}
                        {$longTermFmTeamSizeSelect}
                        {$monthlyBudgetFirstYearSelect}
                        {$estClinicalHoursMdsOnSiteSelect}
                        {$hrVisionSelect}
                        {$operationalHoursSelect}
                        {$contentsOfSpaceSelect}
                        {$marketingStrategySelect}
                        {$optionalProjectTextSelect}
                    FROM fw_projects p
                    LEFT JOIN fw_v_users u ON p.prj_manager = u.id
                    LEFT JOIN fw_v_users creator ON p.created_by = creator.id
                    {$foremanJoin}
                    WHERE 1=1";

            $params = [];

            // Фильтр по статусу
            if ($status) {
                $sql .= " AND p.status = ?";
                $params[] = $status;
            }

            // Фильтр по приоритету
            if ($priority) {
                $sql .= " AND p.priority = ?";
                $params[] = $priority;
            }

            // Поиск по названию или адресу
            if ($search) {
                $sql .= " AND (p.prj_name LIKE ? OR p.address LIKE ?)";
                $searchTerm = "%{$search}%";
                $params[] = $searchTerm;
                $params[] = $searchTerm;
            }

            // Фильтр по менеджеру проекта
            if ($prjManager && $prjManager !== '0') {
                $sql .= " AND p.prj_manager = ?";
                $params[] = (int)$prjManager;
            }

            // Фильтр по вовлеченности пользователя в проект
            if ($userId !== null && $userId !== '' && is_numeric($userId)) {
                $sql .= " AND (
                    p.prj_manager = ?
                    OR EXISTS (
                        SELECT 1
                        FROM fw_prj_team_members tm
                        WHERE tm.project_id = p.id
                          AND tm.user_id = ?
                    )
                )";
                $params[] = (int)$userId;
                $params[] = (int)$userId;
            }

            // Подсчет общего количества
            $countSql = "SELECT COUNT(*) as total FROM fw_projects p WHERE 1=1";
            $countParams = [];
            
            if ($status) {
                $countSql .= " AND p.status = ?";
                $countParams[] = $status;
            }
            
            if ($priority) {
                $countSql .= " AND p.priority = ?";
                $countParams[] = $priority;
            }
            
            if ($search) {
                $countSql .= " AND (p.prj_name LIKE ? OR p.address LIKE ?)";
                $searchTerm = "%{$search}%";
                $countParams[] = $searchTerm;
                $countParams[] = $searchTerm;
            }
            
            if ($prjManager && $prjManager !== '0') {
                $countSql .= " AND p.prj_manager = ?";
                $countParams[] = (int)$prjManager;
            }

            if ($userId !== null && $userId !== '' && is_numeric($userId)) {
                $countSql .= " AND (
                    p.prj_manager = ?
                    OR EXISTS (
                        SELECT 1
                        FROM fw_prj_team_members tm
                        WHERE tm.project_id = p.id
                          AND tm.user_id = ?
                    )
                )";
                $countParams[] = (int)$userId;
                $countParams[] = (int)$userId;
            }

            $connection = $this->database->getConnection();
            $countResult = $connection->executeQuery($countSql, $countParams);
            $total = $countResult->fetchOne();

            // Добавляем сортировку и пагинацию
            $sql .= " ORDER BY p.created_at DESC LIMIT {$limit} OFFSET {$offset}";

            $result = $connection->executeQuery($sql, $params);
            $projects = $result->fetchAllAssociative();

            $projectIds = array_map(static fn($p) => (int) $p['id'], $projects);
            $additionalByProject = $this->loadAdditionalClientsByProjectIds($connection, $projectIds);

            // Форматируем данные
            $formattedProjects = array_map(function($project) use ($additionalByProject) {
                $clientData = $this->parseClientData($project['client_data'] ?? null);
                $client2Data = $this->parseClientData($project['client2_data'] ?? null);
                $projectId = (int) $project['id'];
                $additionalClients = $additionalByProject[$projectId] ?? [];
                if ($additionalClients === []) {
                    $additionalClients = $this->additionalClientsFromLegacyClient2($project);
                }
                return $this->appendProjectForemanFields($project, [
                    'id' => $projectId,
                    'prj_name' => $project['prj_name'],
                    'address' => $project['address'],
                    'date_start' => $project['date_start'],
                    'date_end' => $project['date_end'],
                    'priority' => $project['priority'],
                    'status' => $project['status'],
                    'sys_status' => $project['sys_status'] ?? null,
                    'purchase_or_lease' => $project['purchase_or_lease'],
                    'notes' => $project['notes'] ?? null,
                    'locations_of_interest' => $this->parseLocationsOfInterest($project['locations_of_interest'] ?? null),
                    'client_id' => $project['client_id'] ? (int)$project['client_id'] : null,
                    'client_type' => $project['client_type'] ?? null,
                    'client_table' => $project['client_table'] ?? null,
                    'client_name' => $this->getClientNameWithFallback($project, $clientData),
                    'client_data' => $clientData,
                    'client2_id' => $project['client2_id'] ? (int)$project['client2_id'] : null,
                    'client2_type' => $project['client2_type'] ?? null,
                    'client2_table' => $project['client2_table'] ?? null,
                    'client2_name' => $this->getClient2NameWithFallback($project, $client2Data),
                    'client2_data' => $client2Data,
                    'additional_clients' => $additionalClients,
                    'description' => $project['description'] ?? null,
                    'area' => isset($project['area']) && $project['area'] !== null ? (int)$project['area'] : null,
                    'level' => $project['level'] ?? null,
                    'clinic_model_type' => $project['clinic_model_type'] ?? null,
                    'healthcare_services' => $this->parseHealthcareServices($project['healthcare_services'] ?? null),
                    'project_inclusions' => $this->parseProjectInclusions($project['project_inclusions'] ?? null),
                    'long_term_fm_team_size' => $project['long_term_fm_team_size'] ?? null,
                    'monthly_budget_first_year' => $project['monthly_budget_first_year'] ?? null,
                    'est_clinical_hours_mds_on_site' => $project['est_clinical_hours_mds_on_site'] ?? null,
                    'hr_vision' => $this->parseHrVision($project['hr_vision'] ?? null),
                    'operational_hours' => $this->parseOperationalHours($project['operational_hours'] ?? null),
                    'contents_of_space' => $this->parseContentsOfSpace($project['contents_of_space'] ?? null),
                    'marketing_strategy' => $this->parseMarketingStrategy($project['marketing_strategy'] ?? null),
                    'total_doctors' => $project['total_doctors'] ?? null,
                    'project_fee_per_doctor' => $project['project_fee_per_doctor'] ?? null,
                    'cost_per_sq_ft' => $project['cost_per_sq_ft'] ?? null,
                    'mark_up' => $project['mark_up'] ?? null,
                    'daily_patient_volumes' => $project['daily_patient_volumes'] ?? null,
                    'prj_manager' => $project['prj_manager'] ? (int)$project['prj_manager'] : null,
                    'created_by' => $project['created_by'] ? (int)$project['created_by'] : null,
                    'created_by_name' => $project['created_by_first_name'] && $project['created_by_last_name']
                        ? $project['created_by_first_name'] . ' ' . $project['created_by_last_name']
                        : null,
                    'manager_name' => $project['first_name'] && $project['last_name']
                        ? $project['first_name'] . ' ' . $project['last_name']
                        : null,
                    'created_at' => $project['created_at'],
                    'updated_at' => $project['updated_at']
                ]);
            }, $projects);

            $lastPage = ceil($total / $limit);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Projects retrieved successfully',
                'data' => [
                    'projects' => $formattedProjects,
                    'pagination' => [
                        'current_page' => $page,
                        'per_page' => $limit,
                        'total' => (int)$total,
                        'last_page' => $lastPage
                    ]
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to retrieve projects', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'query_params' => [
                    'page' => $page,
                    'limit' => $limit,
                    'status' => $status,
                    'priority' => $priority,
                    'search' => $search,
                    'prj_manager' => $prjManager,
                    'user_id' => $userId
                ]
            ]);

            // Проверяем, не связана ли ошибка с отсутствующими полями
            $errorMessage = $e->getMessage();
            if (strpos($errorMessage, 'Unknown column') !== false) {
                $this->logger->warning('Possible missing database columns. Please run migration script: scripts/add-project-client-fields.sql');
            }

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve projects: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Получить проект по ID
     * GET /api/v1/projects/{id}
     *
     * @OA\Get(
     *     path="/api/v1/projects/{id}",
     *     summary="Get project by ID",
     *     description="Retrieve a specific project by its ID",
     *     tags={"Projects"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Project ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Project retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Project retrieved successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="project", type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="prj_name", type="string", example="Office Building Construction"),
     *                     @OA\Property(property="address", type="string", example="123 Main St, City, State"),
     *                     @OA\Property(property="date_start", type="string", format="date", example="2025-01-01"),
     *                     @OA\Property(property="date_end", type="string", format="date", example="2025-12-31"),
     *                     @OA\Property(property="priority", type="string", example="High"),
     *                     @OA\Property(property="status", type="string", example="Project Secured", description="One of: Initial Contact Lead, Dead Lead, Waiting On Direction, Actively Looking For A Location, Securing Location, Project Secured, Construction, Completed Project"),
     *                     @OA\Property(property="sys_status", type="string", nullable=true, enum={"Draft","Active","Closing","Suspended","Done"}, example="Active"),
     *                     @OA\Property(property="purchase_or_lease", type="string", enum={"Purchase","Lease","Undecided"}, example="Purchase"),
     *                     @OA\Property(property="notes", type="string", nullable=true, example="Additional project notes"),
     *                     @OA\Property(property="client_id", type="integer", nullable=true, example=1),
     *                     @OA\Property(property="client_type", type="string", nullable=true, example="pharmacy"),
     *                     @OA\Property(property="client_table", type="string", enum={"pharma","physician","pharmacist","medical_clinic"}, nullable=true, example="pharma"),
     *                     @OA\Property(property="client_name", type="string", nullable=true, example="Pharmacy Name"),
     *                     @OA\Property(property="client_data", type="object", nullable=true, example={"id":1,"name":"Pharmacy Name","address":"123 Main St"}),
     *                     @OA\Property(property="client2_id", type="integer", nullable=true, example=2),
     *                     @OA\Property(property="client2_type", type="string", nullable=true, example="physician"),
     *                     @OA\Property(property="client2_table", type="string", enum={"pharma","physician","pharmacist","medical_clinic"}, nullable=true, example="physician"),
     *                     @OA\Property(property="client2_name", type="string", nullable=true, example="Second Client Name"),
     *                     @OA\Property(property="client2_data", type="object", nullable=true, example={"id":2,"name":"Second Client","address":"456 Oak St"}),
     *                     @OA\Property(property="prj_manager", type="integer", nullable=true, example=1),
     *                     @OA\Property(property="created_by", type="integer", nullable=true, example=47),
     *                     @OA\Property(property="created_by_name", type="string", nullable=true, example="John Doe"),
     *                     @OA\Property(property="manager_name", type="string", nullable=true, example="John Doe"),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Project not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=404),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Project not found"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     )
     * )
     */
    public function getProject(int $id): void
    {
        $this->logger->info('ProjectController::getProject called', ['id' => $id]);
        
        try {
            $connection = $this->database->getConnection();
            $foremanSelect = $this->projectForemanSelectSql($connection);
            $foremanJoin = $this->projectForemanJoinSql($connection);
            $locationsSelect = $this->locationsOfInterestSelectSql($connection);
            $clinicModelSelect = $this->clinicModelTypeSelectSql($connection);
            $healthcareServicesSelect = $this->healthcareServicesSelectSql($connection);
            $projectInclusionsSelect = $this->projectInclusionsSelectSql($connection);
            $longTermFmTeamSizeSelect = $this->longTermFmTeamSizeSelectSql($connection);
            $monthlyBudgetFirstYearSelect = $this->monthlyBudgetFirstYearSelectSql($connection);
            $estClinicalHoursMdsOnSiteSelect = $this->estClinicalHoursMdsOnSiteSelectSql($connection);
            $hrVisionSelect = $this->hrVisionSelectSql($connection);
            $operationalHoursSelect = $this->operationalHoursSelectSql($connection);
            $contentsOfSpaceSelect = $this->contentsOfSpaceSelectSql($connection);
            $marketingStrategySelect = $this->marketingStrategySelectSql($connection);
            $optionalProjectTextSelect = $this->optionalProjectTextFieldsSelectSql($connection);
            
            $sql = "SELECT
                        p.id, p.prj_name, p.address, p.date_start, p.date_end,
                        p.priority, p.status, p.sys_status, p.purchase_or_lease, p.notes, p.client_id, p.client_type, p.client_table, p.client_data, p.client_name,
                        p.client2_id, p.client2_type, p.client2_table, p.client2_data, p.client2_name,
                        p.description, p.area, p.level, p.prj_manager, p.created_by, p.created_at, p.updated_at,
                        u.first_name, u.last_name,
                        creator.first_name as created_by_first_name, creator.last_name as created_by_last_name
                        {$foremanSelect}
                        {$locationsSelect}
                        {$clinicModelSelect}
                        {$healthcareServicesSelect}
                        {$projectInclusionsSelect}
                        {$longTermFmTeamSizeSelect}
                        {$monthlyBudgetFirstYearSelect}
                        {$estClinicalHoursMdsOnSiteSelect}
                        {$hrVisionSelect}
                        {$operationalHoursSelect}
                        {$contentsOfSpaceSelect}
                        {$marketingStrategySelect}
                        {$optionalProjectTextSelect}
                    FROM fw_projects p
                    LEFT JOIN fw_v_users u ON p.prj_manager = u.id
                    LEFT JOIN fw_v_users creator ON p.created_by = creator.id
                    {$foremanJoin}
                    WHERE p.id = ?";
            
            $result = $connection->executeQuery($sql, [$id]);
            $project = $result->fetchAssociative();

            if (!$project) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Project not found',
                    'data' => null
                ], 404);
                return;
            }

            $clientData = $this->parseClientData($project['client_data'] ?? null);
            $client2Data = $this->parseClientData($project['client2_data'] ?? null);
            $projectId = (int) $project['id'];
            $additionalClients = $this->loadAdditionalClientsByProjectIds($connection, [$projectId])[$projectId] ?? [];
            if ($additionalClients === []) {
                $additionalClients = $this->additionalClientsFromLegacyClient2($project);
            }
            
            $formattedProject = $this->appendProjectForemanFields($project, [
                'id' => $projectId,
                'prj_name' => $project['prj_name'],
                'address' => $project['address'],
                'date_start' => $project['date_start'],
                'date_end' => $project['date_end'],
                'priority' => $project['priority'],
                'status' => $project['status'],
                'sys_status' => $project['sys_status'] ?? null,
                'purchase_or_lease' => $project['purchase_or_lease'],
                'notes' => $project['notes'] ?? null,
                'locations_of_interest' => $this->parseLocationsOfInterest($project['locations_of_interest'] ?? null),
                'client_id' => $project['client_id'] ? (int)$project['client_id'] : null,
                'client_type' => $project['client_type'] ?? null,
                'client_table' => $project['client_table'] ?? null,
                'client_name' => $this->getClientNameWithFallback($project, $clientData),
                'client_data' => $clientData,
                'client2_id' => $project['client2_id'] ? (int)$project['client2_id'] : null,
                'client2_type' => $project['client2_type'] ?? null,
                'client2_table' => $project['client2_table'] ?? null,
                'client2_name' => $this->getClient2NameWithFallback($project, $client2Data),
                'client2_data' => $client2Data,
                'additional_clients' => $additionalClients,
                'description' => $project['description'] ?? null,
                'area' => isset($project['area']) && $project['area'] !== null ? (int)$project['area'] : null,
                'level' => $project['level'] ?? null,
                'clinic_model_type' => $project['clinic_model_type'] ?? null,
                'healthcare_services' => $this->parseHealthcareServices($project['healthcare_services'] ?? null),
                'project_inclusions' => $this->parseProjectInclusions($project['project_inclusions'] ?? null),
                'long_term_fm_team_size' => $project['long_term_fm_team_size'] ?? null,
                'monthly_budget_first_year' => $project['monthly_budget_first_year'] ?? null,
                'est_clinical_hours_mds_on_site' => $project['est_clinical_hours_mds_on_site'] ?? null,
                'hr_vision' => $this->parseHrVision($project['hr_vision'] ?? null),
                'operational_hours' => $this->parseOperationalHours($project['operational_hours'] ?? null),
                'contents_of_space' => $this->parseContentsOfSpace($project['contents_of_space'] ?? null),
                'marketing_strategy' => $this->parseMarketingStrategy($project['marketing_strategy'] ?? null),
                    'total_doctors' => $project['total_doctors'] ?? null,
                    'project_fee_per_doctor' => $project['project_fee_per_doctor'] ?? null,
                    'cost_per_sq_ft' => $project['cost_per_sq_ft'] ?? null,
                    'mark_up' => $project['mark_up'] ?? null,
                    'daily_patient_volumes' => $project['daily_patient_volumes'] ?? null,
                'prj_manager' => $project['prj_manager'] ? (int)$project['prj_manager'] : null,
                'created_by' => $project['created_by'] ? (int)$project['created_by'] : null,
                'created_by_name' => $project['created_by_first_name'] && $project['created_by_last_name']
                    ? $project['created_by_first_name'] . ' ' . $project['created_by_last_name']
                    : null,
                'manager_name' => $project['first_name'] && $project['last_name']
                    ? $project['first_name'] . ' ' . $project['last_name']
                    : null,
                'created_at' => $project['created_at'],
                'updated_at' => $project['updated_at']
            ]);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Project retrieved successfully',
                'data' => [
                    'project' => $formattedProject
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to retrieve project', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Проверяем, не связана ли ошибка с отсутствующими полями
            $errorMessage = $e->getMessage();
            if (strpos($errorMessage, 'Unknown column') !== false) {
                $this->logger->warning('Possible missing database columns. Please run migration script: scripts/add-project-client-fields.sql', [
                    'project_id' => $id
                ]);
            }

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to retrieve project: ' . $e->getMessage(),
                'data' => null
            ], 500);
        }
    }

    /**
     * Создать новый проект
     * POST /api/v1/projects
     *
     * @OA\Post(
     *     path="/api/v1/projects",
     *     summary="Create new project",
     *     description="Create a new project",
     *     tags={"Projects"},
     *     security={{"bearerAuth": {}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"prj_name", "address", "created_by"},
     *             @OA\Property(property="prj_name", type="string", example="Office Building Construction"),
     *             @OA\Property(property="address", type="string", example="123 Main St, City, State"),
     *             @OA\Property(property="date_start", type="string", format="date", nullable=true, example="2025-01-01"),
     *             @OA\Property(property="date_end", type="string", format="date", nullable=true, example="2025-12-31"),
     *             @OA\Property(property="priority", type="string", example="High"),
     *             @OA\Property(property="status", type="string", example="Project Secured", description="One of: Initial Contact Lead, Dead Lead, Waiting On Direction, Actively Looking For A Location, Securing Location, Project Secured, Construction, Completed Project"),
     *             @OA\Property(property="sys_status", type="string", nullable=true, enum={"Draft","Active","Closing","Suspended","Done"}, example="Draft", description="System lifecycle status used by app logic"),
     *             @OA\Property(property="purchase_or_lease", type="string", enum={"Purchase","Lease","Undecided"}, example="Purchase"),
     *             @OA\Property(property="notes", type="string", nullable=true, example="Additional project notes"),
     *             @OA\Property(property="client_id", type="integer", nullable=true, example=1),
     *             @OA\Property(property="client_type", type="string", nullable=true, example="pharmacy"),
     *             @OA\Property(property="client_table", type="string", enum={"pharma","physician","pharmacist","medical_clinic"}, nullable=true, example="pharma"),
     *             @OA\Property(property="client_name", type="string", nullable=true, example="Pharmacy Name"),
     *             @OA\Property(property="client_data", type="object", nullable=true, example={"id":1,"name":"Pharmacy Name","address":"123 Main St"}),
     *             @OA\Property(property="client2_id", type="integer", nullable=true, example=2, description="Optional second client ID"),
     *             @OA\Property(property="client2_type", type="string", nullable=true, example="physician"),
     *             @OA\Property(property="client2_table", type="string", enum={"pharma","physician","pharmacist","medical_clinic"}, nullable=true, example="physician"),
     *             @OA\Property(property="client2_name", type="string", nullable=true, example="Second Client Name"),
     *             @OA\Property(property="client2_data", type="object", nullable=true, example={"id":2,"name":"Second Client","address":"456 Oak St"}),
     *             @OA\Property(property="prj_manager", type="integer", example=1),
     *             @OA\Property(property="created_by", type="integer", example=47)
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Project created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Project created successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="project", type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="prj_name", type="string", example="Office Building Construction"),
     *                     @OA\Property(property="address", type="string", example="123 Main St, City, State"),
     *                     @OA\Property(property="date_start", type="string", format="date", example="2025-01-01"),
     *                     @OA\Property(property="date_end", type="string", format="date", example="2025-12-31"),
     *                     @OA\Property(property="priority", type="string", example="High"),
     *                     @OA\Property(property="status", type="string", example="Project Secured", description="One of: Initial Contact Lead, Dead Lead, Waiting On Direction, Actively Looking For A Location, Securing Location, Project Secured, Construction, Completed Project"),
     *                     @OA\Property(property="sys_status", type="string", nullable=true, enum={"Draft","Active","Closing","Suspended","Done"}, example="Draft"),
     *                     @OA\Property(property="purchase_or_lease", type="string", enum={"Purchase","Lease","Undecided"}, example="Purchase"),
     *                     @OA\Property(property="notes", type="string", nullable=true, example="Additional project notes"),
     *                     @OA\Property(property="client_id", type="integer", nullable=true, example=1),
     *                     @OA\Property(property="client_type", type="string", nullable=true, example="pharmacy"),
     *                     @OA\Property(property="client_table", type="string", enum={"pharma","physician","pharmacist","medical_clinic"}, nullable=true, example="pharma"),
     *                     @OA\Property(property="client_name", type="string", nullable=true, example="Pharmacy Name"),
     *                     @OA\Property(property="client_data", type="object", nullable=true, example={"id":1,"name":"Pharmacy Name","address":"123 Main St"}),
     *                     @OA\Property(property="client2_id", type="integer", nullable=true, example=2),
     *                     @OA\Property(property="client2_type", type="string", nullable=true, example="physician"),
     *                     @OA\Property(property="client2_table", type="string", enum={"pharma","physician","pharmacist","medical_clinic"}, nullable=true, example="physician"),
     *                     @OA\Property(property="client2_name", type="string", nullable=true, example="Second Client Name"),
     *                     @OA\Property(property="client2_data", type="object", nullable=true, example={"id":2,"name":"Second Client","address":"456 Oak St"}),
     *                     @OA\Property(property="prj_manager", type="integer", nullable=true, example=1),
     *                     @OA\Property(property="created_by", type="integer", nullable=true, example=47),
     *                     @OA\Property(property="created_by_name", type="string", nullable=true, example="John Doe"),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Validation error",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=400),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Validation failed"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     )
     * )
     */
    public function createProject(): void
    {
        $this->logger->info('ProjectController::createProject called');
        
        try {
            $request = Flight::request();
            $data = json_decode($request->getBody(), true);
            $data = $this->normalizeProjectSysStatusInput($data);

            // Валидация данных
            $validation = $this->validateProjectData($data);
            if (!$validation['valid']) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => $validation['message'],
                    'data' => null
                ], 400);
                return;
            }

            $connection = $this->database->getConnection();
            
            // Получаем имя клиента если указаны client_id и client_table
            $clientName = null;
            if (!empty($data['client_id']) && !empty($data['client_table'])) {
                $clientName = $this->getClientName($data['client_table'], (int)$data['client_id']);
            }

            $primaryClientId = !empty($data['client_id']) ? (int) $data['client_id'] : null;
            $primaryClientTable = !empty($data['client_table']) ? (string) $data['client_table'] : null;
            $normalizedAdditional = [];
            if (array_key_exists('additional_clients', $data) && is_array($data['additional_clients'])) {
                $normalizedAdditional = $this->normalizeAdditionalClientsPayload(
                    $data['additional_clients'],
                    $primaryClientId,
                    $primaryClientTable
                );
                $client2Mirror = $this->client2FieldsFromAdditional($normalizedAdditional);
                $data['client2_id'] = $client2Mirror['client2_id'];
                $data['client2_type'] = $client2Mirror['client2_type'];
                $data['client2_table'] = $client2Mirror['client2_table'];
                $data['client2_data'] = $client2Mirror['client2_data'];
                $client2Name = $client2Mirror['client2_name'];
            } else {
                $client2Name = null;
                if (!empty($data['client2_id']) && !empty($data['client2_table'])) {
                    $client2Name = $this->getClientName($data['client2_table'], (int)$data['client2_id']);
                }
            }
            
            $sysStatus = array_key_exists('sys_status', $data) ? $data['sys_status'] : 'Draft';

            $insertColumns = 'prj_name, address, date_start, date_end, priority, status, sys_status, purchase_or_lease, notes, client_id, client_type, client_table, client_data, client_name, client2_id, client2_type, client2_table, client2_data, client2_name, area, level, prj_manager, created_by, description';
            $insertPlaceholders = '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?';
            $params = [
                $data['prj_name'],
                isset($data['address']) && is_string($data['address']) && trim($data['address']) !== ''
                    ? trim($data['address'])
                    : null,
                $data['date_start'] ?? null,
                $data['date_end'] ?? null,
                $data['priority'] ?? null,
                $data['status'] ?? null,
                $sysStatus,
                $data['purchase_or_lease'] ?? 'Purchase',
                $data['notes'] ?? null,
                $data['client_id'] ?? null,
                $data['client_type'] ?? null,
                $data['client_table'] ?? null,
                $this->encodeClientData($data['client_data'] ?? null),
                $clientName,
                $data['client2_id'] ?? null,
                $data['client2_type'] ?? null,
                $data['client2_table'] ?? null,
                $this->encodeClientData($data['client2_data'] ?? null),
                $client2Name,
                isset($data['area']) && $data['area'] !== null ? (int)$data['area'] : null,
                $data['level'] ?? null,
                $data['prj_manager'] ?? null,
                $data['created_by'] ?? null,
                $data['description'] ?? null,
            ];

            if ($this->projectForemanColumnPresent($connection)) {
                $insertColumns .= ', project_foreman_id';
                $insertPlaceholders .= ', ?';
                $rawForemanId = $data['project_foreman_id'] ?? null;
                $params[] = ($rawForemanId !== null && $rawForemanId !== '' && (int) $rawForemanId > 0)
                    ? (int) $rawForemanId
                    : null;
            }

            if ($this->locationsOfInterestColumnPresent($connection)) {
                $insertColumns .= ', locations_of_interest';
                $insertPlaceholders .= ', ?';
                $params[] = $this->encodeLocationsOfInterest(
                    array_key_exists('locations_of_interest', $data)
                        ? $this->normalizeLocationsOfInterestInput($data['locations_of_interest'])
                        : null
                );
            }

            if ($this->clinicModelTypeColumnPresent($connection)) {
                $insertColumns .= ', clinic_model_type';
                $insertPlaceholders .= ', ?';
                $params[] = !empty($data['clinic_model_type']) ? (string) $data['clinic_model_type'] : null;
            }

            if ($this->healthcareServicesColumnPresent($connection)) {
                $insertColumns .= ', healthcare_services';
                $insertPlaceholders .= ', ?';
                $params[] = $this->encodeHealthcareServices(
                    array_key_exists('healthcare_services', $data)
                        ? $this->normalizeHealthcareServicesInput($data['healthcare_services'])
                        : null
                );
            }

            if ($this->projectInclusionsColumnPresent($connection)) {
                $insertColumns .= ', project_inclusions';
                $insertPlaceholders .= ', ?';
                $params[] = $this->encodeProjectInclusions(
                    array_key_exists('project_inclusions', $data)
                        ? $this->normalizeProjectInclusionsInput($data['project_inclusions'])
                        : null
                );
            }

            if ($this->longTermFmTeamSizeColumnPresent($connection)) {
                $insertColumns .= ', long_term_fm_team_size';
                $insertPlaceholders .= ', ?';
                $params[] = !empty($data['long_term_fm_team_size']) ? (string) $data['long_term_fm_team_size'] : null;
            }

            if ($this->monthlyBudgetFirstYearColumnPresent($connection)) {
                $insertColumns .= ', monthly_budget_first_year';
                $insertPlaceholders .= ', ?';
                $params[] = isset($data['monthly_budget_first_year']) && trim((string) $data['monthly_budget_first_year']) !== ''
                    ? trim((string) $data['monthly_budget_first_year'])
                    : null;
            }

            if ($this->estClinicalHoursMdsOnSiteColumnPresent($connection)) {
                $insertColumns .= ', est_clinical_hours_mds_on_site';
                $insertPlaceholders .= ', ?';
                $params[] = isset($data['est_clinical_hours_mds_on_site']) && trim((string) $data['est_clinical_hours_mds_on_site']) !== ''
                    ? trim((string) $data['est_clinical_hours_mds_on_site'])
                    : null;
            }

            if ($this->hrVisionColumnPresent($connection)) {
                $insertColumns .= ', hr_vision';
                $insertPlaceholders .= ', ?';
                $params[] = $this->encodeHrVision(
                    array_key_exists('hr_vision', $data)
                        ? $this->normalizeHrVisionInput($data['hr_vision'])
                        : null
                );
            }

            if ($this->operationalHoursColumnPresent($connection)) {
                $insertColumns .= ', operational_hours';
                $insertPlaceholders .= ', ?';
                $params[] = $this->encodeOperationalHours(
                    array_key_exists('operational_hours', $data)
                        ? $this->normalizeOperationalHoursInput($data['operational_hours'])
                        : $this->normalizeOperationalHoursInput(null)
                );
            }

            if ($this->contentsOfSpaceColumnPresent($connection)) {
                $insertColumns .= ', contents_of_space';
                $insertPlaceholders .= ', ?';
                $params[] = $this->encodeContentsOfSpace(
                    array_key_exists('contents_of_space', $data)
                        ? $this->normalizeContentsOfSpaceInput($data['contents_of_space'])
                        : $this->normalizeContentsOfSpaceInput(null)
                );
            }

            if ($this->marketingStrategyColumnPresent($connection)) {
                $insertColumns .= ', marketing_strategy';
                $insertPlaceholders .= ', ?';
                $params[] = $this->encodeMarketingStrategy(
                    array_key_exists('marketing_strategy', $data)
                        ? $this->normalizeMarketingStrategyInput($data['marketing_strategy'])
                        : null
                );
            }

            foreach (self::OPTIONAL_PROJECT_TEXT_FIELDS as $field => $_max) {
                if (!$this->optionalProjectTextColumnPresent($connection, $field)) {
                    continue;
                }
                $insertColumns .= ", {$field}";
                $insertPlaceholders .= ', ?';
                $params[] = $this->normalizeOptionalProjectTextValue($data[$field] ?? null);
            }

            $sql = "INSERT INTO fw_projects ({$insertColumns}) VALUES ({$insertPlaceholders})";

            $connection->executeStatement($sql, $params);
            $projectId = (int) $connection->lastInsertId();

            $this->refreshProjectGeocode(
                $connection,
                $projectId,
                isset($data['address']) && is_string($data['address']) ? trim($data['address']) : null
            );

            if (!array_key_exists('additional_clients', $data) && !empty($data['client2_id']) && !empty($data['client2_table'])) {
                $normalizedAdditional = $this->normalizeAdditionalClientsPayload(
                    [[
                        'client_id' => (int) $data['client2_id'],
                        'client_type' => $data['client2_type'] ?? null,
                        'client_table' => $data['client2_table'],
                        'client_data' => $data['client2_data'] ?? null,
                        'client_name' => $client2Name,
                    ]],
                    $primaryClientId,
                    $primaryClientTable
                );
            }

            $this->syncProjectClients(
                $connection,
                $projectId,
                $primaryClientId,
                isset($data['client_type']) ? (string) $data['client_type'] : null,
                $primaryClientTable,
                $data['client_data'] ?? null,
                $clientName,
                $normalizedAdditional
            );

            // Получаем созданный проект с информацией о создателе
            $result = $connection->executeQuery(
                "SELECT p.*, creator.first_name as created_by_first_name, creator.last_name as created_by_last_name
                 FROM fw_projects p
                 LEFT JOIN fw_v_users creator ON p.created_by = creator.id
                 WHERE p.id = ?",
                [$projectId]
            );
            $project = $result->fetchAssociative();

            // Копируем стандартную структуру папок из проекта-образца (project_id = 0) в новый проект
            $this->logger->info('About to copy default folder structure', ['project_id' => $projectId]);
            $this->copyDefaultFolderStructure($projectId, $connection);
            $this->logger->info('Finished copying default folder structure', ['project_id' => $projectId]);

            // Логируем событие создания проекта
            $this->logProjectCreationEvent($project, $data);

            $additionalClients = $this->loadAdditionalClientsByProjectIds($connection, [$projectId])[$projectId] ?? [];
            if ($additionalClients === []) {
                $additionalClients = $this->additionalClientsFromLegacyClient2($project);
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Project created successfully',
                'data' => [
                    'project' => [
                        'id' => (int)$project['id'],
                        'prj_name' => $project['prj_name'],
                        'address' => $project['address'],
                        'date_start' => $project['date_start'],
                        'date_end' => $project['date_end'],
                        'priority' => $project['priority'],
                        'status' => $project['status'],
                        'sys_status' => $project['sys_status'] ?? null,
                        'purchase_or_lease' => $project['purchase_or_lease'],
                        'notes' => $project['notes'] ?? null,
                        'locations_of_interest' => $this->parseLocationsOfInterest($project['locations_of_interest'] ?? null),
                        'client_id' => $project['client_id'] ? (int)$project['client_id'] : null,
                        'client_type' => $project['client_type'] ?? null,
                        'client_table' => $project['client_table'] ?? null,
                        'client_name' => $this->getClientNameWithFallback($project, $this->parseClientData($project['client_data'] ?? null)),
                        'client_data' => $this->parseClientData($project['client_data'] ?? null),
                        'client2_id' => $project['client2_id'] ? (int)$project['client2_id'] : null,
                        'client2_type' => $project['client2_type'] ?? null,
                        'client2_table' => $project['client2_table'] ?? null,
                        'client2_name' => $this->getClient2NameWithFallback($project, $this->parseClientData($project['client2_data'] ?? null)),
                        'client2_data' => $this->parseClientData($project['client2_data'] ?? null),
                        'additional_clients' => $additionalClients,
                        'description' => $project['description'] ?? null,
                        'area' => isset($project['area']) && $project['area'] !== null ? (int)$project['area'] : null,
                        'level' => $project['level'] ?? null,
                        'clinic_model_type' => $project['clinic_model_type'] ?? null,
                        'healthcare_services' => $this->parseHealthcareServices($project['healthcare_services'] ?? null),
                        'project_inclusions' => $this->parseProjectInclusions($project['project_inclusions'] ?? null),
                        'long_term_fm_team_size' => $project['long_term_fm_team_size'] ?? null,
                        'monthly_budget_first_year' => $project['monthly_budget_first_year'] ?? null,
                        'est_clinical_hours_mds_on_site' => $project['est_clinical_hours_mds_on_site'] ?? null,
                        'hr_vision' => $this->parseHrVision($project['hr_vision'] ?? null),
                        'operational_hours' => $this->parseOperationalHours($project['operational_hours'] ?? null),
                        'contents_of_space' => $this->parseContentsOfSpace($project['contents_of_space'] ?? null),
                        'marketing_strategy' => $this->parseMarketingStrategy($project['marketing_strategy'] ?? null),
                    'total_doctors' => $project['total_doctors'] ?? null,
                    'project_fee_per_doctor' => $project['project_fee_per_doctor'] ?? null,
                    'cost_per_sq_ft' => $project['cost_per_sq_ft'] ?? null,
                    'mark_up' => $project['mark_up'] ?? null,
                    'daily_patient_volumes' => $project['daily_patient_volumes'] ?? null,
                        'prj_manager' => $project['prj_manager'] ? (int)$project['prj_manager'] : null,
                        'created_by' => $project['created_by'] ? (int)$project['created_by'] : null,
                        'created_by_name' => $project['created_by_first_name'] && $project['created_by_last_name']
                            ? $project['created_by_first_name'] . ' ' . $project['created_by_last_name']
                            : null,
                        'created_at' => $project['created_at'],
                        'updated_at' => $project['updated_at']
                    ]
                ]
            ], 201);

        } catch (Exception $e) {
            $this->logger->error('Failed to create project', [
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to create project',
                'data' => null
            ], 500);
        }
    }

    /**
     * Обновить проект
     * PUT /api/v1/projects/{id}
     *
     * @OA\Put(
     *     path="/api/v1/projects/{id}",
     *     summary="Update project",
     *     description="Update an existing project",
     *     tags={"Projects"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Project ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="prj_name", type="string", example="Office Building Construction"),
     *             @OA\Property(property="address", type="string", example="123 Main St, City, State"),
     *             @OA\Property(property="date_start", type="string", format="date", example="2025-01-01"),
     *             @OA\Property(property="date_end", type="string", format="date", example="2025-12-31"),
     *             @OA\Property(property="priority", type="string", example="High"),
     *             @OA\Property(property="status", type="string", example="Project Secured", description="One of: Initial Contact Lead, Dead Lead, Waiting On Direction, Actively Looking For A Location, Securing Location, Project Secured, Construction, Completed Project"),
     *             @OA\Property(property="sys_status", type="string", nullable=true, enum={"Draft","Active","Closing","Suspended","Done"}, example="Active", description="System lifecycle status used by app logic"),
     *             @OA\Property(property="purchase_or_lease", type="string", enum={"Purchase","Lease","Undecided"}, example="Purchase"),
     *             @OA\Property(property="notes", type="string", nullable=true, example="Additional project notes"),
     *             @OA\Property(property="client_id", type="integer", nullable=true, example=1),
     *             @OA\Property(property="client_type", type="string", nullable=true, example="pharmacy"),
     *             @OA\Property(property="client_table", type="string", enum={"pharma","physician","pharmacist","medical_clinic"}, nullable=true, example="pharma"),
     *             @OA\Property(property="client_name", type="string", nullable=true, example="Pharmacy Name"),
     *             @OA\Property(property="client_data", type="object", nullable=true, example={"id":1,"name":"Pharmacy Name","address":"123 Main St"}),
     *             @OA\Property(property="client2_id", type="integer", nullable=true, example=2),
     *             @OA\Property(property="client2_type", type="string", nullable=true, example="physician"),
     *             @OA\Property(property="client2_table", type="string", enum={"pharma","physician","pharmacist","medical_clinic"}, nullable=true, example="physician"),
     *             @OA\Property(property="client2_name", type="string", nullable=true, example="Second Client Name"),
     *             @OA\Property(property="client2_data", type="object", nullable=true, example={"id":2,"name":"Second Client","address":"456 Oak St"}),
     *             @OA\Property(property="prj_manager", type="integer", example=1),
     *             @OA\Property(property="created_by", type="integer", example=47)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Project updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Project updated successfully"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="project", type="object",
     *                     @OA\Property(property="id", type="integer", example=1),
     *                     @OA\Property(property="prj_name", type="string", example="Office Building Construction"),
     *                     @OA\Property(property="address", type="string", example="123 Main St, City, State"),
     *                     @OA\Property(property="date_start", type="string", format="date", example="2025-01-01"),
     *                     @OA\Property(property="date_end", type="string", format="date", example="2025-12-31"),
     *                     @OA\Property(property="priority", type="string", example="High"),
     *                     @OA\Property(property="status", type="string", example="Project Secured", description="One of: Initial Contact Lead, Dead Lead, Waiting On Direction, Actively Looking For A Location, Securing Location, Project Secured, Construction, Completed Project"),
     *                     @OA\Property(property="sys_status", type="string", nullable=true, enum={"Draft","Active","Closing","Suspended","Done"}, example="Active"),
     *                     @OA\Property(property="prj_manager", type="integer", nullable=true, example=1),
     *                     @OA\Property(property="created_by", type="integer", nullable=true, example=47),
     *                     @OA\Property(property="created_by_name", type="string", nullable=true, example="John Doe"),
     *                     @OA\Property(property="created_at", type="string", format="date-time"),
     *                     @OA\Property(property="updated_at", type="string", format="date-time")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Project not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=404),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Project not found"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     )
     * )
     */
    public function updateProject(int $id): void
    {
        $this->logger->info('ProjectController::updateProject called', ['id' => $id]);
        
        try {
            $request = Flight::request();
            $data = json_decode($request->getBody(), true);
            $data = $this->normalizeProjectSysStatusInput($data);

            $connection = $this->database->getConnection();
            
            // Проверяем, существует ли проект
            $checkResult = $connection->executeQuery(
                "SELECT id FROM fw_projects WHERE id = ?",
                [$id]
            );
            
            if (!$checkResult->fetchOne()) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Project not found',
                    'data' => null
                ], 404);
                return;
            }

            // Валидация данных
            $validation = $this->validateProjectData($data, false);
            if (!$validation['valid']) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => $validation['message'],
                    'data' => null
                ], 400);
                return;
            }

            // Получаем текущие данные проекта перед обновлением для логирования
            $beforeResult = $connection->executeQuery(
                'SELECT id, prj_name, address, date_start, date_end, priority, status, sys_status, purchase_or_lease, notes, client_id, client_type, client_table, client_data, client_name, client2_id, client2_type, client2_table, client2_data, client2_name, description, area, level, prj_manager, created_by, created_at, updated_at
                 FROM fw_projects WHERE id = ?',
                [$id]
            );
            $beforeData = $beforeResult->fetchAssociative();

            // Строим SQL запрос для обновления
            $updateFields = [];
            $params = [];

            if (isset($data['prj_name'])) {
                $updateFields[] = "prj_name = ?";
                $params[] = $data['prj_name'];
            }
            if (isset($data['address'])) {
                $updateFields[] = "address = ?";
                $params[] = $data['address'];
            }
            // date_start и date_end допускают null (partial update)
            if (array_key_exists('date_start', $data)) {
                $updateFields[] = "date_start = ?";
                $params[] = $data['date_start'];
            }
            if (array_key_exists('date_end', $data)) {
                $updateFields[] = "date_end = ?";
                $params[] = $data['date_end'];
            }
            if (isset($data['priority'])) {
                $updateFields[] = "priority = ?";
                $params[] = $data['priority'];
            }
            if (isset($data['status'])) {
                $updateFields[] = "status = ?";
                $params[] = $data['status'];
            }
            if (array_key_exists('sys_status', $data)) {
                $updateFields[] = "sys_status = ?";
                $params[] = $data['sys_status'];
            }
            if (isset($data['purchase_or_lease'])) {
                $updateFields[] = "purchase_or_lease = ?";
                $params[] = $data['purchase_or_lease'];
            }
            if (isset($data['notes'])) {
                $updateFields[] = "notes = ?";
                $params[] = $data['notes'];
            }
            // Поля клиента обновляются даже если они null (для очистки клиента)
            if (array_key_exists('client_id', $data)) {
                $updateFields[] = "client_id = ?";
                $params[] = $data['client_id'];
            }
            if (array_key_exists('client_type', $data)) {
                $updateFields[] = "client_type = ?";
                $params[] = $data['client_type'];
            }
            if (array_key_exists('client_table', $data)) {
                $updateFields[] = "client_table = ?";
                $params[] = $data['client_table'];
            }
            if (array_key_exists('client_data', $data)) {
                $updateFields[] = "client_data = ?";
                $encodedClientData = $this->encodeClientData($data['client_data']);
                $params[] = $encodedClientData;
                
                // Логируем что сохраняется в client_data для диагностики
                $this->logger->debug('Saving client_data to project', [
                    'project_id' => $id,
                    'client_id' => $data['client_id'] ?? null,
                    'client_table' => $data['client_table'] ?? null,
                    'client_data_raw' => $data['client_data'],
                    'client_data_encoded' => $encodedClientData,
                    'client_data_keys' => is_array($data['client_data']) ? array_keys($data['client_data']) : 'not_array'
                ]);
            }
            
            // Обновляем client_name если изменились client_id или client_table
            if (array_key_exists('client_id', $data) || array_key_exists('client_table', $data)) {
                $clientId = array_key_exists('client_id', $data) ? $data['client_id'] : null;
                $clientTable = array_key_exists('client_table', $data) ? $data['client_table'] : null;
                
                // Если client_id или client_table были удалены (null), очищаем client_name
                if (!$clientId || !$clientTable) {
                    $updateFields[] = "client_name = ?";
                    $params[] = null;
                } else {
                    // Получаем имя клиента из соответствующей таблицы
                    $clientName = $this->getClientName($clientTable, (int)$clientId);
                    $updateFields[] = "client_name = ?";
                    $params[] = $clientName;
                }
            }
            $hasAdditionalClientsPayload =
                array_key_exists('additional_clients', $data) && is_array($data['additional_clients']);

            // When additional_clients is provided it is authoritative for client2_* mirror.
            if (!$hasAdditionalClientsPayload) {
                if (array_key_exists('client2_id', $data)) {
                    $updateFields[] = "client2_id = ?";
                    $params[] = $data['client2_id'];
                }
                if (array_key_exists('client2_type', $data)) {
                    $updateFields[] = "client2_type = ?";
                    $params[] = $data['client2_type'];
                }
                if (array_key_exists('client2_table', $data)) {
                    $updateFields[] = "client2_table = ?";
                    $params[] = $data['client2_table'];
                }
                if (array_key_exists('client2_data', $data)) {
                    $updateFields[] = "client2_data = ?";
                    $params[] = $this->encodeClientData($data['client2_data']);
                }
                if (array_key_exists('client2_id', $data) || array_key_exists('client2_table', $data)) {
                    $client2Id = array_key_exists('client2_id', $data) ? $data['client2_id'] : null;
                    $client2Table = array_key_exists('client2_table', $data) ? $data['client2_table'] : null;
                    if (!$client2Id || !$client2Table) {
                        $updateFields[] = "client2_name = ?";
                        $params[] = null;
                    } else {
                        $client2Name = $this->getClientName($client2Table, (int)$client2Id);
                        $updateFields[] = "client2_name = ?";
                        $params[] = $client2Name;
                    }
                }
            }

            $normalizedAdditionalForSync = null;
            if ($hasAdditionalClientsPayload) {
                $primaryClientIdForSync = array_key_exists('client_id', $data)
                    ? ($data['client_id'] ? (int) $data['client_id'] : null)
                    : (!empty($beforeData['client_id']) ? (int) $beforeData['client_id'] : null);
                $primaryClientTableForSync = array_key_exists('client_table', $data)
                    ? ($data['client_table'] ? (string) $data['client_table'] : null)
                    : (!empty($beforeData['client_table']) ? (string) $beforeData['client_table'] : null);

                $normalizedAdditionalForSync = $this->normalizeAdditionalClientsPayload(
                    $data['additional_clients'],
                    $primaryClientIdForSync,
                    $primaryClientTableForSync
                );
                $client2Mirror = $this->client2FieldsFromAdditional($normalizedAdditionalForSync);

                // Replace client2_* with first additional (or clear) when additional_clients is authoritative.
                $updateFields[] = 'client2_id = ?';
                $params[] = $client2Mirror['client2_id'];
                $updateFields[] = 'client2_type = ?';
                $params[] = $client2Mirror['client2_type'];
                $updateFields[] = 'client2_table = ?';
                $params[] = $client2Mirror['client2_table'];
                $updateFields[] = 'client2_data = ?';
                $params[] = $this->encodeClientData($client2Mirror['client2_data']);
                $updateFields[] = 'client2_name = ?';
                $params[] = $client2Mirror['client2_name'];
            }

            if (isset($data['description'])) {
                $updateFields[] = "description = ?";
                $params[] = $data['description'];
            }
            if (isset($data['prj_manager'])) {
                $updateFields[] = "prj_manager = ?";
                $params[] = $data['prj_manager'];
            }
            if (array_key_exists('project_foreman_id', $data) && $this->projectForemanColumnPresent($connection)) {
                $updateFields[] = 'project_foreman_id = ?';
                $params[] = $data['project_foreman_id'] ? (int) $data['project_foreman_id'] : null;
            }
            if (isset($data['created_by'])) {
                $updateFields[] = "created_by = ?";
                $params[] = $data['created_by'];
            }
            if (array_key_exists('area', $data)) {
                $updateFields[] = "area = ?";
                $params[] = $data['area'] !== null ? (int)$data['area'] : null;
            }
            if (array_key_exists('level', $data)) {
                $updateFields[] = "level = ?";
                $params[] = $data['level'];
            }
            if (
                array_key_exists('clinic_model_type', $data)
                && $this->clinicModelTypeColumnPresent($connection)
            ) {
                $updateFields[] = 'clinic_model_type = ?';
                $params[] = !empty($data['clinic_model_type']) ? (string) $data['clinic_model_type'] : null;
            }
            if (
                array_key_exists('healthcare_services', $data)
                && $this->healthcareServicesColumnPresent($connection)
            ) {
                $updateFields[] = 'healthcare_services = ?';
                $params[] = $this->encodeHealthcareServices(
                    $this->normalizeHealthcareServicesInput($data['healthcare_services'])
                );
            }
            if (
                array_key_exists('project_inclusions', $data)
                && $this->projectInclusionsColumnPresent($connection)
            ) {
                $updateFields[] = 'project_inclusions = ?';
                $params[] = $this->encodeProjectInclusions(
                    $this->normalizeProjectInclusionsInput($data['project_inclusions'])
                );
            }
            if (
                array_key_exists('long_term_fm_team_size', $data)
                && $this->longTermFmTeamSizeColumnPresent($connection)
            ) {
                $updateFields[] = 'long_term_fm_team_size = ?';
                $params[] = !empty($data['long_term_fm_team_size']) ? (string) $data['long_term_fm_team_size'] : null;
            }
            if (
                array_key_exists('monthly_budget_first_year', $data)
                && $this->monthlyBudgetFirstYearColumnPresent($connection)
            ) {
                $updateFields[] = 'monthly_budget_first_year = ?';
                $params[] = isset($data['monthly_budget_first_year']) && trim((string) $data['monthly_budget_first_year']) !== ''
                    ? trim((string) $data['monthly_budget_first_year'])
                    : null;
            }
            if (
                array_key_exists('est_clinical_hours_mds_on_site', $data)
                && $this->estClinicalHoursMdsOnSiteColumnPresent($connection)
            ) {
                $updateFields[] = 'est_clinical_hours_mds_on_site = ?';
                $params[] = isset($data['est_clinical_hours_mds_on_site']) && trim((string) $data['est_clinical_hours_mds_on_site']) !== ''
                    ? trim((string) $data['est_clinical_hours_mds_on_site'])
                    : null;
            }
            if (
                array_key_exists('locations_of_interest', $data)
                && $this->locationsOfInterestColumnPresent($connection)
            ) {
                $updateFields[] = 'locations_of_interest = ?';
                $params[] = $this->encodeLocationsOfInterest(
                    $this->normalizeLocationsOfInterestInput($data['locations_of_interest'])
                );
            }
            if (
                array_key_exists('hr_vision', $data)
                && $this->hrVisionColumnPresent($connection)
            ) {
                $updateFields[] = 'hr_vision = ?';
                $params[] = $this->encodeHrVision(
                    $this->normalizeHrVisionInput($data['hr_vision'])
                );
            }
            if (
                array_key_exists('operational_hours', $data)
                && $this->operationalHoursColumnPresent($connection)
            ) {
                $updateFields[] = 'operational_hours = ?';
                $params[] = $this->encodeOperationalHours(
                    $this->normalizeOperationalHoursInput($data['operational_hours'])
                );
            }
            if (
                array_key_exists('contents_of_space', $data)
                && $this->contentsOfSpaceColumnPresent($connection)
            ) {
                $updateFields[] = 'contents_of_space = ?';
                $params[] = $this->encodeContentsOfSpace(
                    $this->normalizeContentsOfSpaceInput($data['contents_of_space'])
                );
            }
            if (
                array_key_exists('marketing_strategy', $data)
                && $this->marketingStrategyColumnPresent($connection)
            ) {
                $updateFields[] = 'marketing_strategy = ?';
                $params[] = $this->encodeMarketingStrategy(
                    $this->normalizeMarketingStrategyInput($data['marketing_strategy'])
                );
            }

            foreach (self::OPTIONAL_PROJECT_TEXT_FIELDS as $field => $_max) {
                if (
                    !array_key_exists($field, $data)
                    || !$this->optionalProjectTextColumnPresent($connection, $field)
                ) {
                    continue;
                }
                $updateFields[] = "{$field} = ?";
                $params[] = $this->normalizeOptionalProjectTextValue($data[$field]);
            }

            if (empty($updateFields)) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'No fields to update',
                    'data' => null
                ], 400);
                return;
            }

            $updateFields[] = "updated_at = NOW()";
            $params[] = $id;

            $sql = "UPDATE fw_projects SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $connection->executeStatement($sql, $params);

            if (isset($data['address']) && $this->projectGeoColumnsPresent($connection)) {
                $addr = is_string($data['address']) ? trim($data['address']) : '';
                $this->refreshProjectGeocode($connection, $id, $addr !== '' ? $addr : null);
            }

            // Keep fw_project_clients in sync when primary and/or additional clients change.
            $shouldSyncProjectClients =
                $normalizedAdditionalForSync !== null
                || array_key_exists('client_id', $data)
                || array_key_exists('client_table', $data)
                || array_key_exists('client_type', $data)
                || array_key_exists('client_data', $data)
                || array_key_exists('client2_id', $data)
                || array_key_exists('client2_table', $data);

            if ($shouldSyncProjectClients && $this->projectClientsTablePresent($connection)) {
                $updatedRow = $connection->executeQuery(
                    'SELECT client_id, client_type, client_table, client_data, client_name,
                            client2_id, client2_type, client2_table, client2_data, client2_name
                     FROM fw_projects WHERE id = ?',
                    [$id]
                )->fetchAssociative() ?: [];

                if ($normalizedAdditionalForSync !== null) {
                    $additionalToSync = $normalizedAdditionalForSync;
                } else {
                    $existingAdditional = $this->loadAdditionalClientsByProjectIds($connection, [$id])[$id] ?? [];
                    if ($existingAdditional === [] && !empty($updatedRow['client2_id'])) {
                        $existingAdditional = $this->additionalClientsFromLegacyClient2($updatedRow);
                    }
                    $additionalToSync = $this->normalizeAdditionalClientsPayload(
                        $existingAdditional,
                        !empty($updatedRow['client_id']) ? (int) $updatedRow['client_id'] : null,
                        !empty($updatedRow['client_table']) ? (string) $updatedRow['client_table'] : null
                    );
                }

                $this->syncProjectClients(
                    $connection,
                    $id,
                    !empty($updatedRow['client_id']) ? (int) $updatedRow['client_id'] : null,
                    $updatedRow['client_type'] ?? null,
                    !empty($updatedRow['client_table']) ? (string) $updatedRow['client_table'] : null,
                    $this->parseClientData($updatedRow['client_data'] ?? null),
                    $updatedRow['client_name'] ?? null,
                    $additionalToSync
                );
            }

            $propagateTaskForeman = !empty($data['update_task_foreman_on_all_tasks']);
            $foremanIdForPropagation = null;
            if (array_key_exists('project_foreman_id', $data) && $data['project_foreman_id']) {
                $foremanIdForPropagation = (int) $data['project_foreman_id'];
            } elseif ($propagateTaskForeman) {
                $foremanRow = $connection->executeQuery(
                    'SELECT project_foreman_id FROM fw_projects WHERE id = ?',
                    [$id]
                )->fetchAssociative();
                if ($foremanRow && $foremanRow['project_foreman_id']) {
                    $foremanIdForPropagation = (int) $foremanRow['project_foreman_id'];
                }
            }
            if (
                $propagateTaskForeman
                && $foremanIdForPropagation
                && $this->projectForemanColumnPresent($connection)
            ) {
                $this->propagateProjectForemanToTaskLeads($connection, $id, $foremanIdForPropagation);
            }

            // Получаем обновленный проект с информацией о создателе
            $result = $connection->executeQuery(
                "SELECT p.*, creator.first_name as created_by_first_name, creator.last_name as created_by_last_name
                 FROM fw_projects p
                 LEFT JOIN fw_v_users creator ON p.created_by = creator.id
                 WHERE p.id = ?",
                [$id]
            );
            $project = $result->fetchAssociative();

            // Логируем событие обновления проекта
            try {
                $user = Flight::get('current_user');
                $actorId = $user['id'] ?? $beforeData['created_by'] ?? null;
                
                $afterData = [
                    'id' => (int)$project['id'],
                    'prj_name' => $project['prj_name'],
                    'address' => $project['address'],
                    'date_start' => $project['date_start'],
                    'date_end' => $project['date_end'],
                    'priority' => $project['priority'],
                    'status' => $project['status'],
                    'sys_status' => $project['sys_status'] ?? null,
                    'purchase_or_lease' => $project['purchase_or_lease'],
                    'notes' => $project['notes'] ?? null,
                    'locations_of_interest' => $this->parseLocationsOfInterest($project['locations_of_interest'] ?? null),
                    'hr_vision' => $this->parseHrVision($project['hr_vision'] ?? null),
                    'operational_hours' => $this->parseOperationalHours($project['operational_hours'] ?? null),
                    'contents_of_space' => $this->parseContentsOfSpace($project['contents_of_space'] ?? null),
                    'marketing_strategy' => $this->parseMarketingStrategy($project['marketing_strategy'] ?? null),
                    'total_doctors' => $project['total_doctors'] ?? null,
                    'project_fee_per_doctor' => $project['project_fee_per_doctor'] ?? null,
                    'cost_per_sq_ft' => $project['cost_per_sq_ft'] ?? null,
                    'mark_up' => $project['mark_up'] ?? null,
                    'daily_patient_volumes' => $project['daily_patient_volumes'] ?? null,
                    'client_id' => $project['client_id'] ? (int)$project['client_id'] : null,
                    'client_type' => $project['client_type'] ?? null,
                    'client_table' => $project['client_table'] ?? null,
                    'client_name' => $this->getClientNameWithFallback($project, $this->parseClientData($project['client_data'] ?? null)),
                    'client_data' => $this->parseClientData($project['client_data'] ?? null),
                    'client2_id' => $project['client2_id'] ? (int)$project['client2_id'] : null,
                    'client2_type' => $project['client2_type'] ?? null,
                    'client2_table' => $project['client2_table'] ?? null,
                    'client2_name' => $this->getClient2NameWithFallback($project, $this->parseClientData($project['client2_data'] ?? null)),
                    'client2_data' => $this->parseClientData($project['client2_data'] ?? null),
                    'description' => $project['description'] ?? null,
                    'prj_manager' => $project['prj_manager'] ? (int)$project['prj_manager'] : null,
                    'created_by' => $project['created_by'] ? (int)$project['created_by'] : null,
                    'updated_at' => $project['updated_at']
                ];

                $this->eventLoggingService->logSimple(
                    entityType: 'project',
                    entityId: $id,
                    eventType: 'PROJECT_UPDATED',
                    afterData: $afterData,
                    options: [
                        'actor_type' => 'user',
                        'actor_id' => $actorId,
                        'before_data' => [
                            'id' => (int)$beforeData['id'],
                            'prj_name' => $beforeData['prj_name'],
                            'address' => $beforeData['address'],
                            'date_start' => $beforeData['date_start'],
                            'date_end' => $beforeData['date_end'],
                            'priority' => $beforeData['priority'],
                            'status' => $beforeData['status'],
                            'sys_status' => $beforeData['sys_status'] ?? null,
                            'purchase_or_lease' => $beforeData['purchase_or_lease'],
                            'notes' => $beforeData['notes'] ?? null,
                            'client_id' => $beforeData['client_id'] ? (int)$beforeData['client_id'] : null,
                            'client_type' => $beforeData['client_type'] ?? null,
                            'client_table' => $beforeData['client_table'] ?? null,
                            'client_name' => $beforeData['client_name'] ?? null,
                            'client_data' => $this->parseClientData($beforeData['client_data'] ?? null),
                            'client2_id' => $beforeData['client2_id'] ? (int)$beforeData['client2_id'] : null,
                            'client2_type' => $beforeData['client2_type'] ?? null,
                            'client2_table' => $beforeData['client2_table'] ?? null,
                            'client2_name' => $beforeData['client2_name'] ?? null,
                            'client2_data' => $this->parseClientData($beforeData['client2_data'] ?? null),
                            'description' => $beforeData['description'] ?? null,
                            'area' => isset($beforeData['area']) && $beforeData['area'] !== null ? (int)$beforeData['area'] : null,
                            'level' => $beforeData['level'] ?? null,
                            'clinic_model_type' => $beforeData['clinic_model_type'] ?? null,
                            'healthcare_services' => $this->parseHealthcareServices($beforeData['healthcare_services'] ?? null),
                            'project_inclusions' => $this->parseProjectInclusions($beforeData['project_inclusions'] ?? null),
                            'long_term_fm_team_size' => $beforeData['long_term_fm_team_size'] ?? null,
                            'monthly_budget_first_year' => $beforeData['monthly_budget_first_year'] ?? null,
                            'est_clinical_hours_mds_on_site' => $beforeData['est_clinical_hours_mds_on_site'] ?? null,
                            'hr_vision' => $this->parseHrVision($beforeData['hr_vision'] ?? null),
                            'operational_hours' => $this->parseOperationalHours($beforeData['operational_hours'] ?? null),
                            'contents_of_space' => $this->parseContentsOfSpace($beforeData['contents_of_space'] ?? null),
                            'marketing_strategy' => $this->parseMarketingStrategy($beforeData['marketing_strategy'] ?? null),
                            'total_doctors' => $beforeData['total_doctors'] ?? null,
                            'project_fee_per_doctor' => $beforeData['project_fee_per_doctor'] ?? null,
                            'cost_per_sq_ft' => $beforeData['cost_per_sq_ft'] ?? null,
                            'mark_up' => $beforeData['mark_up'] ?? null,
                            'daily_patient_volumes' => $beforeData['daily_patient_volumes'] ?? null,
                            'prj_manager' => $beforeData['prj_manager'] ? (int)$beforeData['prj_manager'] : null,
                            'created_by' => $beforeData['created_by'] ? (int)$beforeData['created_by'] : null
                        ],
                        'changed_fields' => array_keys($data),
                        'comment' => 'Project updated',
                        'ip' => $this->getClientIp(),
                        'user_agent' => $this->getUserAgent(),
                        'severity' => 'important'
                    ]
                );

                // Если изменился статус, логируем отдельное событие
                if (isset($data['status']) && $beforeData['status'] !== $project['status']) {
                    $this->eventLoggingService->logSimple(
                        entityType: 'project',
                        entityId: $id,
                        eventType: 'PROJECT_STATUS_CHANGED',
                        afterData: [
                            'status' => $project['status'],
                            'previous_status' => $beforeData['status'],
                            'project_id' => $id,
                            'project_name' => $project['prj_name']
                        ],
                        options: [
                            'actor_type' => 'user',
                            'actor_id' => $actorId,
                            'before_data' => ['status' => $beforeData['status']],
                            'changed_fields' => ['status'],
                            'comment' => "Project status changed from '{$beforeData['status']}' to '{$project['status']}'",
                            'ip' => $this->getClientIp(),
                            'user_agent' => $this->getUserAgent(),
                            'severity' => 'important'
                        ]
                    );
                }

                // Lifecycle Active ↔ Inactive (sys_status). Draft/pre-active transitions stay silent.
                if (
                    array_key_exists('sys_status', $data)
                    && ($beforeData['sys_status'] ?? null) !== ($project['sys_status'] ?? null)
                ) {
                    $this->eventLoggingService->logSimple(
                        entityType: 'project',
                        entityId: $id,
                        eventType: 'PROJECT_SYS_STATUS_CHANGED',
                        afterData: [
                            'sys_status' => $project['sys_status'] ?? null,
                            'previous_sys_status' => $beforeData['sys_status'] ?? null,
                            'project_id' => $id,
                            'project_name' => $project['prj_name'] ?? null,
                        ],
                        options: [
                            'actor_type' => 'user',
                            'actor_id' => $actorId,
                            'before_data' => ['sys_status' => $beforeData['sys_status'] ?? null],
                            'changed_fields' => ['sys_status'],
                            'comment' => sprintf(
                                "Project lifecycle changed from '%s' to '%s'",
                                (string) ($beforeData['sys_status'] ?? 'Draft'),
                                (string) ($project['sys_status'] ?? 'Draft')
                            ),
                            'ip' => $this->getClientIp(),
                            'user_agent' => $this->getUserAgent(),
                            'severity' => 'important',
                        ]
                    );

                    $this->projectLifecycleNotifications->notifyIfLifecycleChanged(
                        $id,
                        $project,
                        isset($beforeData['sys_status']) ? (string) $beforeData['sys_status'] : null,
                        isset($project['sys_status']) ? (string) $project['sys_status'] : null,
                        $actorId,
                    );
                }
            } catch (\Exception $e) {
                $this->logger->warning('Failed to log project update event', [
                    'error' => $e->getMessage(),
                    'project_id' => $id
                ]);
            }

            $additionalClients = $this->loadAdditionalClientsByProjectIds($connection, [$id])[$id] ?? [];
            if ($additionalClients === []) {
                $additionalClients = $this->additionalClientsFromLegacyClient2($project);
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Project updated successfully',
                'data' => [
                    'project' => [
                        'id' => (int)$project['id'],
                        'prj_name' => $project['prj_name'],
                        'address' => $project['address'],
                        'date_start' => $project['date_start'],
                        'date_end' => $project['date_end'],
                        'priority' => $project['priority'],
                        'status' => $project['status'],
                        'sys_status' => $project['sys_status'] ?? null,
                        'purchase_or_lease' => $project['purchase_or_lease'],
                        'notes' => $project['notes'] ?? null,
                        'locations_of_interest' => $this->parseLocationsOfInterest($project['locations_of_interest'] ?? null),
                        'client_id' => $project['client_id'] ? (int)$project['client_id'] : null,
                        'client_type' => $project['client_type'] ?? null,
                        'client_table' => $project['client_table'] ?? null,
                        'client_name' => $this->getClientNameWithFallback($project, $this->parseClientData($project['client_data'] ?? null)),
                        'client_data' => $this->parseClientData($project['client_data'] ?? null),
                        'client2_id' => $project['client2_id'] ? (int)$project['client2_id'] : null,
                        'client2_type' => $project['client2_type'] ?? null,
                        'client2_table' => $project['client2_table'] ?? null,
                        'client2_name' => $this->getClient2NameWithFallback($project, $this->parseClientData($project['client2_data'] ?? null)),
                        'client2_data' => $this->parseClientData($project['client2_data'] ?? null),
                        'additional_clients' => $additionalClients,
                        'description' => $project['description'] ?? null,
                        'area' => isset($project['area']) && $project['area'] !== null ? (int)$project['area'] : null,
                        'level' => $project['level'] ?? null,
                        'clinic_model_type' => $project['clinic_model_type'] ?? null,
                        'healthcare_services' => $this->parseHealthcareServices($project['healthcare_services'] ?? null),
                        'project_inclusions' => $this->parseProjectInclusions($project['project_inclusions'] ?? null),
                        'long_term_fm_team_size' => $project['long_term_fm_team_size'] ?? null,
                        'monthly_budget_first_year' => $project['monthly_budget_first_year'] ?? null,
                        'est_clinical_hours_mds_on_site' => $project['est_clinical_hours_mds_on_site'] ?? null,
                        'hr_vision' => $this->parseHrVision($project['hr_vision'] ?? null),
                        'operational_hours' => $this->parseOperationalHours($project['operational_hours'] ?? null),
                        'contents_of_space' => $this->parseContentsOfSpace($project['contents_of_space'] ?? null),
                        'marketing_strategy' => $this->parseMarketingStrategy($project['marketing_strategy'] ?? null),
                    'total_doctors' => $project['total_doctors'] ?? null,
                    'project_fee_per_doctor' => $project['project_fee_per_doctor'] ?? null,
                    'cost_per_sq_ft' => $project['cost_per_sq_ft'] ?? null,
                    'mark_up' => $project['mark_up'] ?? null,
                    'daily_patient_volumes' => $project['daily_patient_volumes'] ?? null,
                        'prj_manager' => $project['prj_manager'] ? (int)$project['prj_manager'] : null,
                        'project_foreman_id' => isset($project['project_foreman_id']) && $project['project_foreman_id']
                            ? (int) $project['project_foreman_id']
                            : null,
                        'created_by' => $project['created_by'] ? (int)$project['created_by'] : null,
                        'created_by_name' => $project['created_by_first_name'] && $project['created_by_last_name']
                            ? $project['created_by_first_name'] . ' ' . $project['created_by_last_name']
                            : null,
                        'created_at' => $project['created_at'],
                        'updated_at' => $project['updated_at']
                    ]
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to update project', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to update project',
                'data' => null
            ], 500);
        }
    }

    /**
     * Удалить проект
     * DELETE /api/v1/projects/{id}
     *
     * @OA\Delete(
     *     path="/api/v1/projects/{id}",
     *     summary="Delete project",
     *     description="Delete a project by ID",
     *     tags={"Projects"},
     *     security={{"bearerAuth": {}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Project ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Project deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Project deleted successfully"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Project not found",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=404),
     *             @OA\Property(property="status", type="string", example="error"),
     *             @OA\Property(property="message", type="string", example="Project not found"),
     *             @OA\Property(property="data", type="null")
     *         )
     *     )
     * )
     */
    public function deleteProject(int $id): void
    {
        $this->logger->info('ProjectController::deleteProject called', ['id' => $id]);
        
        try{
            $connection = $this->database->getConnection();
            
            // Получаем данные проекта перед удалением для логирования
            $projectResult = $connection->executeQuery(
                "SELECT id, prj_name, address, date_start, date_end, priority, status, prj_manager, created_by, created_at, updated_at
                 FROM fw_projects WHERE id = ?",
                [$id]
            );
            $projectData = $projectResult->fetchAssociative();
            
            if (!$projectData) {
                Flight::json([
                    'error_code' => 404,
                    'status' => 'error',
                    'message' => 'Project not found',
                    'data' => null
                ], 404);
                return;
            }

            // Удаляем проект
            $connection->executeStatement(
                "DELETE FROM fw_projects WHERE id = ?",
                [$id]
            );

            // Логируем событие удаления проекта
            try {
                $user = Flight::get('current_user');
                $actorId = $user['id'] ?? $projectData['created_by'] ?? null;

                $this->eventLoggingService->logSimple(
                    entityType: 'project',
                    entityId: $id,
                    eventType: 'PROJECT_DELETED',
                    afterData: [
                        'id' => (int)$projectData['id'],
                        'prj_name' => $projectData['prj_name'],
                        'status' => $projectData['status'],
                        'deleted_at' => date('c')
                    ],
                    options: [
                        'actor_type' => 'user',
                        'actor_id' => $actorId,
                        'before_data' => [
                            'id' => (int)$projectData['id'],
                            'prj_name' => $projectData['prj_name'],
                            'address' => $projectData['address'],
                            'date_start' => $projectData['date_start'],
                            'date_end' => $projectData['date_end'],
                            'priority' => $projectData['priority'],
                            'status' => $projectData['status'],
                            'prj_manager' => $projectData['prj_manager'] ? (int)$projectData['prj_manager'] : null,
                            'created_by' => $projectData['created_by'] ? (int)$projectData['created_by'] : null
                        ],
                        'changed_fields' => ['deleted'],
                        'comment' => "Project '{$projectData['prj_name']}' deleted",
                        'ip' => $this->getClientIp(),
                        'user_agent' => $this->getUserAgent(),
                        'severity' => 'important'
                    ]
                );
            } catch (\Exception $e) {
                $this->logger->warning('Failed to log project deletion event', [
                    'error' => $e->getMessage(),
                    'project_id' => $id
                ]);
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Project deleted successfully',
                'data' => null
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to delete project', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            Flight::json([
                'error_code' => 500,
                'status' => 'error',
                'message' => 'Failed to delete project',
                'data' => null
            ], 500);
        }
    }

    /**
     * Валидация данных проекта
     */
    private function validateProjectData(array $data, bool $isCreate = true): array
    {
        // date_start and date_end are optional (nullable) - project dates can be derived from tasks
        // address is optional — may be unknown at create time
        $requiredFields = ['prj_name', 'created_by'];
        
        if ($isCreate) {
            foreach ($requiredFields as $field) {
                $val = $data[$field] ?? null;
                if ($val === null || (is_string($val) && trim($val) === '')) {
                    return [
                        'valid' => false,
                        'message' => "Field '{$field}' is required"
                    ];
                }
            }
        }

        try {
            $connection = $this->database->getConnection();
            if ($this->projectForemanColumnPresent($connection) && array_key_exists('project_foreman_id', $data)) {
                $foremanId = $data['project_foreman_id'];
                // Optional: empty/null is allowed (foreman may be unknown at create time)
                if ($foremanId !== null && $foremanId !== '' && (!is_numeric($foremanId) || (int) $foremanId <= 0)) {
                    return [
                        'valid' => false,
                        'message' => "Field 'project_foreman_id' must be a positive user id or empty",
                    ];
                }
            }
        } catch (\Exception $e) {
            // ignore — validation continues without foreman column check
        }

        // Валидация длины полей
        if (isset($data['prj_name']) && strlen($data['prj_name']) > 150) {
            return [
                'valid' => false,
                'message' => 'Project name must not exceed 150 characters'
            ];
        }

        if (isset($data['address']) && strlen($data['address']) > 250) {
            return [
                'valid' => false,
                'message' => 'Address must not exceed 250 characters'
            ];
        }

        if (isset($data['priority']) && strlen($data['priority']) > 100) {
            return [
                'valid' => false,
                'message' => 'Priority must not exceed 100 characters'
            ];
        }

        if (isset($data['status'])) {
            if (strlen($data['status']) > 100) {
                return [
                    'valid' => false,
                    'message' => 'Status must not exceed 100 characters'
                ];
            }
            if (!in_array($data['status'], self::ALLOWED_PROJECT_STATUSES, true)) {
                return [
                    'valid' => false,
                    'message' => 'Invalid status. Allowed: ' . implode(', ', self::ALLOWED_PROJECT_STATUSES)
                ];
            }
        }
        
        if (array_key_exists('sys_status', $data)) {
            if ($data['sys_status'] !== null && $data['sys_status'] !== '') {
                if (!is_string($data['sys_status'])) {
                    return [
                        'valid' => false,
                        'message' => 'sys_status must be a string or null'
                    ];
                }
                if (!in_array($data['sys_status'], self::ALLOWED_PROJECT_SYS_STATUSES, true)) {
                    return [
                        'valid' => false,
                        'message' => 'Invalid sys_status. Allowed: ' . implode(', ', self::ALLOWED_PROJECT_SYS_STATUSES)
                    ];
                }
            } elseif ($data['sys_status'] === '') {
                return [
                    'valid' => false,
                    'message' => 'sys_status cannot be an empty string'
                ];
            }
        }

        if (isset($data['purchase_or_lease']) && !in_array($data['purchase_or_lease'], ['Purchase', 'Lease', 'Undecided'], true)) {
            return [
                'valid' => false,
                'message' => 'purchase_or_lease must be Purchase, Lease, or Undecided'
            ];
        }

        if (array_key_exists('locations_of_interest', $data) && $data['locations_of_interest'] !== null) {
            if (!is_array($data['locations_of_interest'])) {
                return [
                    'valid' => false,
                    'message' => 'locations_of_interest must be an array of FSA codes or null',
                ];
            }
            foreach ($data['locations_of_interest'] as $code) {
                if (!is_string($code) || !preg_match('/^[A-Za-z]\d[A-Za-z]$/', $code)) {
                    return [
                        'valid' => false,
                        'message' => 'Each locations_of_interest item must be a 3-character FSA code (e.g. K1A)',
                    ];
                }
            }
        }

        if (array_key_exists('hr_vision', $data) && $data['hr_vision'] !== null) {
            if (!is_array($data['hr_vision'])) {
                return [
                    'valid' => false,
                    'message' => 'hr_vision must be an array of specialties or null',
                ];
            }
            foreach ($data['hr_vision'] as $specialty) {
                if (!is_string($specialty) || !in_array($specialty, self::ALLOWED_HR_VISION_SPECIALTIES, true)) {
                    return [
                        'valid' => false,
                        'message' => 'Each hr_vision item must be an allowed specialty',
                    ];
                }
            }
        }

        if (array_key_exists('marketing_strategy', $data) && $data['marketing_strategy'] !== null) {
            if (!is_array($data['marketing_strategy'])) {
                return [
                    'valid' => false,
                    'message' => 'marketing_strategy must be an array of channels or null',
                ];
            }
            foreach ($data['marketing_strategy'] as $strategy) {
                if (!is_string($strategy) || !in_array($strategy, self::ALLOWED_MARKETING_STRATEGIES, true)) {
                    return [
                        'valid' => false,
                        'message' => 'Each marketing_strategy item must be an allowed channel',
                    ];
                }
            }
        }

        // area: non-negative integer or null
        if (array_key_exists('area', $data) && $data['area'] !== null) {
            if (!is_numeric($data['area']) || (int)$data['area'] < 0) {
                return [
                    'valid' => false,
                    'message' => 'area must be a non-negative integer or null'
                ];
            }
        }

        // level: one of allowed enum values or null
        if (array_key_exists('level', $data) && $data['level'] !== null && $data['level'] !== '') {
            if (!in_array($data['level'], self::ALLOWED_PROJECT_LEVELS, true)) {
                return [
                    'valid' => false,
                    'message' => 'Invalid level. Allowed: ' . implode(', ', self::ALLOWED_PROJECT_LEVELS)
                ];
            }
        }

        if (array_key_exists('clinic_model_type', $data) && $data['clinic_model_type'] !== null && $data['clinic_model_type'] !== '') {
            if (!in_array($data['clinic_model_type'], self::ALLOWED_CLINIC_MODEL_TYPES, true)) {
                return [
                    'valid' => false,
                    'message' => 'Invalid clinic_model_type. Allowed: ' . implode(', ', self::ALLOWED_CLINIC_MODEL_TYPES),
                ];
            }
        }

        if (array_key_exists('healthcare_services', $data) && $data['healthcare_services'] !== null) {
            if (!is_array($data['healthcare_services'])) {
                return [
                    'valid' => false,
                    'message' => 'healthcare_services must be an array of services or null',
                ];
            }
            foreach ($data['healthcare_services'] as $service) {
                if (!is_string($service) || !in_array($service, self::ALLOWED_HEALTHCARE_SERVICES, true)) {
                    return [
                        'valid' => false,
                        'message' => 'Each healthcare_services item must be an allowed service',
                    ];
                }
            }
        }

        if (array_key_exists('project_inclusions', $data) && $data['project_inclusions'] !== null) {
            if (!is_array($data['project_inclusions'])) {
                return [
                    'valid' => false,
                    'message' => 'project_inclusions must be an array of inclusions or null',
                ];
            }
            foreach ($data['project_inclusions'] as $inclusion) {
                if (!is_string($inclusion) || !in_array($inclusion, self::ALLOWED_PROJECT_INCLUSIONS, true)) {
                    return [
                        'valid' => false,
                        'message' => 'Each project_inclusions item must be an allowed inclusion',
                    ];
                }
            }
        }

        if (array_key_exists('long_term_fm_team_size', $data) && $data['long_term_fm_team_size'] !== null && $data['long_term_fm_team_size'] !== '') {
            if (!in_array($data['long_term_fm_team_size'], self::ALLOWED_LONG_TERM_FM_TEAM_SIZES, true)) {
                return [
                    'valid' => false,
                    'message' => 'Invalid long_term_fm_team_size. Allowed: ' . implode(', ', self::ALLOWED_LONG_TERM_FM_TEAM_SIZES),
                ];
            }
        }

        if (array_key_exists('monthly_budget_first_year', $data) && $data['monthly_budget_first_year'] !== null && $data['monthly_budget_first_year'] !== '') {
            if (!is_string($data['monthly_budget_first_year']) && !is_numeric($data['monthly_budget_first_year'])) {
                return [
                    'valid' => false,
                    'message' => 'monthly_budget_first_year must be a string or null',
                ];
            }
            if (strlen(trim((string) $data['monthly_budget_first_year'])) > 100) {
                return [
                    'valid' => false,
                    'message' => 'monthly_budget_first_year must not exceed 100 characters',
                ];
            }
        }

        foreach (self::OPTIONAL_PROJECT_TEXT_FIELDS as $field => $maxLength) {
            if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
                continue;
            }
            if (!is_string($data[$field]) && !is_numeric($data[$field])) {
                return [
                    'valid' => false,
                    'message' => "{$field} must be a string or null",
                ];
            }
            if (strlen(trim((string) $data[$field])) > $maxLength) {
                return [
                    'valid' => false,
                    'message' => "{$field} must not exceed {$maxLength} characters",
                ];
            }
        }

        if (array_key_exists('est_clinical_hours_mds_on_site', $data) && $data['est_clinical_hours_mds_on_site'] !== null && $data['est_clinical_hours_mds_on_site'] !== '') {
            if (!is_string($data['est_clinical_hours_mds_on_site']) && !is_numeric($data['est_clinical_hours_mds_on_site'])) {
                return [
                    'valid' => false,
                    'message' => 'est_clinical_hours_mds_on_site must be a string or null',
                ];
            }
            if (strlen(trim((string) $data['est_clinical_hours_mds_on_site'])) > 100) {
                return [
                    'valid' => false,
                    'message' => 'est_clinical_hours_mds_on_site must not exceed 100 characters',
                ];
            }
        }

        if (array_key_exists('operational_hours', $data) && $data['operational_hours'] !== null) {
            if (!is_array($data['operational_hours'])) {
                return [
                    'valid' => false,
                    'message' => 'operational_hours must be an object with days or null',
                ];
            }
            $normalizedHours = $this->normalizeOperationalHoursInput($data['operational_hours']);
            if ($normalizedHours === null) {
                return [
                    'valid' => false,
                    'message' => 'operational_hours is invalid',
                ];
            }
            $hoursOrderError = $this->validateOperationalHoursOpenBeforeClose($normalizedHours);
            if ($hoursOrderError !== null) {
                return [
                    'valid' => false,
                    'message' => $hoursOrderError,
                ];
            }
        }

        if (array_key_exists('contents_of_space', $data) && $data['contents_of_space'] !== null) {
            if (!is_array($data['contents_of_space'])) {
                return [
                    'valid' => false,
                    'message' => 'contents_of_space must be an object with rows or null',
                ];
            }
            $normalizedContents = $this->normalizeContentsOfSpaceInput($data['contents_of_space']);
            if ($normalizedContents === null) {
                return [
                    'valid' => false,
                    'message' => 'contents_of_space is invalid',
                ];
            }
        }

        if (isset($data['notes']) && strlen($data['notes']) > 1000) {
            return [
                'valid' => false,
                'message' => 'Notes must not exceed 1000 characters'
            ];
        }

        // Валидация client_id (может быть null или положительное целое число)
        if (isset($data['client_id']) && $data['client_id'] !== null) {
            if (!is_numeric($data['client_id']) || $data['client_id'] <= 0) {
                return [
                    'valid' => false,
                    'message' => 'client_id must be a positive integer or null'
                ];
            }
        }

        // Валидация client_table (может быть null или одно из допустимых значений)
        if (isset($data['client_table']) && $data['client_table'] !== null) {
            if (!in_array($data['client_table'], ['pharma', 'physician', 'pharmacist', 'medical_clinic'], true)) {
                return [
                    'valid' => false,
                    'message' => 'client_table must be one of: pharma, physician, pharmacist, medical_clinic or null'
                ];
            }
        }

        // Валидация client_data (должен быть валидным JSON или массивом)
        if (isset($data['client_data']) && $data['client_data'] !== null) {
            if (is_string($data['client_data'])) {
                $decoded = json_decode($data['client_data'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return [
                        'valid' => false,
                        'message' => 'client_data must be valid JSON'
                    ];
                }
            } elseif (!is_array($data['client_data']) && !is_object($data['client_data'])) {
                return [
                    'valid' => false,
                    'message' => 'client_data must be a valid JSON object or array'
                ];
            }
        }

        // Валидация client2_* (все поля необязательные; проверка только при передаче)
        if (isset($data['client2_id']) && $data['client2_id'] !== null) {
            if (!is_numeric($data['client2_id']) || $data['client2_id'] <= 0) {
                return [
                    'valid' => false,
                    'message' => 'client2_id must be a positive integer or null'
                ];
            }
        }
        if (isset($data['client2_table']) && $data['client2_table'] !== null) {
            if (!in_array($data['client2_table'], ['pharma', 'physician', 'pharmacist', 'medical_clinic'], true)) {
                return [
                    'valid' => false,
                    'message' => 'client2_table must be one of: pharma, physician, pharmacist, medical_clinic or null'
                ];
            }
        }
        if (isset($data['client2_data']) && $data['client2_data'] !== null) {
            if (is_string($data['client2_data'])) {
                $decoded = json_decode($data['client2_data'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return [
                        'valid' => false,
                        'message' => 'client2_data must be valid JSON'
                    ];
                }
            } elseif (!is_array($data['client2_data']) && !is_object($data['client2_data'])) {
                return [
                    'valid' => false,
                    'message' => 'client2_data must be a valid JSON object or array'
                ];
            }
        }

        // Валидация дат (date_start и date_end могут быть null)
        if (isset($data['date_start']) && $data['date_start'] !== null && $data['date_start'] !== '') {
            if (!is_string($data['date_start']) || !$this->isValidDate($data['date_start'])) {
                return [
                    'valid' => false,
                    'message' => 'Invalid date_start format. Use YYYY-MM-DD or null'
                ];
            }
        }

        if (isset($data['date_end']) && $data['date_end'] !== null && $data['date_end'] !== '') {
            if (!is_string($data['date_end']) || !$this->isValidDate($data['date_end'])) {
                return [
                    'valid' => false,
                    'message' => 'Invalid date_end format. Use YYYY-MM-DD or null'
                ];
            }
        }

        // Проверка, что дата окончания не раньше даты начала (только если обе заданы)
        if (!empty($data['date_start']) && !empty($data['date_end'])) {
            if (strtotime($data['date_end']) < strtotime($data['date_start'])) {
                return [
                    'valid' => false,
                    'message' => 'End date must be after start date'
                ];
            }
        }

        // Валидация created_by
        if (isset($data['created_by']) && (!is_numeric($data['created_by']) || $data['created_by'] <= 0)) {
            return [
                'valid' => false,
                'message' => 'created_by must be a positive integer'
            ];
        }

        // Валидация ID менеджера
        if (isset($data['prj_manager']) && !is_numeric($data['prj_manager'])) {
            return [
                'valid' => false,
                'message' => 'Project manager ID must be numeric'
            ];
        }

        return ['valid' => true];
    }

    private function normalizeProjectSysStatusInput(array $data): array
    {
        if (!array_key_exists('sys_status', $data)) {
            return $data;
        }

        if ($data['sys_status'] === null || !is_string($data['sys_status'])) {
            return $data;
        }

        $normalized = strtolower(trim($data['sys_status']));
        $map = [
            'draft' => 'Draft',
            'active' => 'Active',
            'closing' => 'Closing',
            'suspended' => 'Suspended',
            'done' => 'Done',
        ];

        if (isset($map[$normalized])) {
            $data['sys_status'] = $map[$normalized];
        }

        return $data;
    }

    /**
     * Проверка валидности даты
     */
    private function isValidDate(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    /**
     * Проверка авторизации
     */
    private function checkAuth(): bool
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            Flight::json([
                'error_code' => 401,
                'status' => 'error',
                'message' => 'Authorization header required',
                'data' => null
            ], 401);
            return false;
        }

        $token = substr($authHeader, 7);
        
        try {
            // Правильная JWT проверка
            $parts = explode('.', $token);
            
            if (count($parts) !== 3) {
                Flight::json([
                    'error_code' => 401,
                    'status' => 'error',
                    'message' => 'Invalid token format',
                    'data' => null
                ], 401);
                return false;
            }

            [$base64Header, $base64Payload, $base64Signature] = $parts;

            // Декодируем payload
            $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $base64Payload)), true);

            if (!$payload || !isset($payload['user_id']) || !isset($payload['exp'])) {
                Flight::json([
                    'error_code' => 401,
                    'status' => 'error',
                    'message' => 'Invalid token',
                    'data' => null
                ], 401);
                return false;
            }

            // Проверяем подпись
            $secret = $_ENV['JWT_SECRET'] ?? 'your-secret-key-change-in-production';
            $expectedSignature = hash_hmac('sha256', $base64Header . '.' . $base64Payload, $secret, true);
            $expectedBase64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($expectedSignature));

            if (!hash_equals($expectedBase64Signature, $base64Signature)) {
                Flight::json([
                    'error_code' => 401,
                    'status' => 'error',
                    'message' => 'Invalid token signature',
                    'data' => null
                ], 401);
                return false;
            }

            // Проверяем срок действия
            if ($payload['exp'] < time()) {
                Flight::json([
                    'error_code' => 401,
                    'status' => 'error',
                    'message' => 'Token expired',
                    'data' => null
                ], 401);
                return false;
            }
            
            return true;
        } catch (\Exception $e) {
            Flight::json([
                'error_code' => 401,
                'status' => 'error',
                'message' => 'Invalid token',
                'data' => null
            ], 401);
            return false;
        }
    }

    /**
     * Логирует событие создания проекта
     */
    private function logProjectCreationEvent(array $project, array $requestData): void
    {
        try {
            // Определяем тип актора (админ или менеджер)
            $actorType = 'user';
            $actorId = $project['created_by'];
            
            // Подготавливаем данные для логирования
            $afterData = [
                'id' => (int)$project['id'],
                'prj_name' => $project['prj_name'],
                'address' => $project['address'],
                'date_start' => $project['date_start'],
                'date_end' => $project['date_end'],
                'priority' => $project['priority'],
                'status' => $project['status'],
                'purchase_or_lease' => $project['purchase_or_lease'],
                'notes' => $project['notes'] ?? null,
                'client_id' => $project['client_id'] ? (int)$project['client_id'] : null,
                'client_type' => $project['client_type'] ?? null,
                'client_table' => $project['client_table'] ?? null,
                'client_name' => $this->getClientNameWithFallback($project, $this->parseClientData($project['client_data'] ?? null)),
                'client_data' => $this->parseClientData($project['client_data'] ?? null),
                'client2_id' => $project['client2_id'] ? (int)$project['client2_id'] : null,
                'client2_type' => $project['client2_type'] ?? null,
                'client2_table' => $project['client2_table'] ?? null,
                'client2_name' => $this->getClient2NameWithFallback($project, $this->parseClientData($project['client2_data'] ?? null)),
                'client2_data' => $this->parseClientData($project['client2_data'] ?? null),
                'description' => $project['description'] ?? null,
                'prj_manager' => $project['prj_manager'] ? (int)$project['prj_manager'] : null,
                'created_by' => $project['created_by'] ? (int)$project['created_by'] : null,
                'created_at' => $project['created_at'],
                'updated_at' => $project['updated_at']
            ];

            $changedFields = array_keys($requestData);
            
            // Логируем событие
            $this->eventLoggingService->logEvent(
                'project',
                (int)$project['id'],
                'PROJECT_CREATED',
                [],
                $afterData,
                $changedFields,
                [
                    'actor_type' => $actorType,
                    'actor_id' => $actorId,
                    'comment' => 'Project created via API',
                    'ip' => $this->getClientIp(),
                    'user_agent' => $this->getUserAgent()
                ]
            );

            $this->logger->info('Project creation event logged', [
                'project_id' => $project['id'],
                'created_by' => $project['created_by'],
                'prj_manager' => $project['prj_manager']
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to log project creation event', [
                'project_id' => $project['id'] ?? null,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Получает IP адрес клиента
     */
    private function getClientIp(): ?string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                return trim($ips[0]);
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? null;
    }

    /**
     * Получает User Agent клиента
     */
    private function getUserAgent(): ?string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? null;
    }

    /**
     * Копирует стандартную структуру папок из проекта-образца (project_id = 0) в новый проект
     */
    private function copyDefaultFolderStructure(int $newProjectId, $connection): void
    {
        $this->logger->info('copyDefaultFolderStructure method called', [
            'new_project_id' => $newProjectId,
            'connection_type' => get_class($connection)
        ]);
        
        try {
            $this->logger->info('Copying default folder structure to new project', [
                'new_project_id' => $newProjectId
            ]);

            // Получаем все папки из проекта-образца (project_id = 0)
            $this->logger->info('Executing query to get default folders', [
                'sql' => 'SELECT id, name, parent_id, created_at, updated_at FROM fw_plan_folders WHERE project_id = 0 ORDER BY parent_id ASC, id ASC'
            ]);
            try {
                $templateFolders = $connection->executeQuery(
                "SELECT id, name, parent_id, created_at, updated_at 
                     FROM fw_plan_folders 
                     WHERE project_id = 0 
                     ORDER BY parent_id ASC, id ASC"
                )->fetchAllAssociative();
            } catch (\Throwable $e) {
                $this->logger->error('Query to get default folders failed', [
                    'error' => $e->getMessage()
                ]);
                return;
            }

            $this->logger->info('Query executed, found folders', [
                'folder_count' => count($templateFolders),
                'folders' => $templateFolders
            ]);

            if (empty($templateFolders)) {
                $this->logger->info('No default folders found to copy');
                return;
            }

            // Создаем маппинг старых ID на новые ID
            $idMapping = [];
            
            // Первый проход: вставляем все папки с правильным parent_id
            foreach ($templateFolders as $folder) {
                $oldId = (int)$folder['id'];
                $oldParentId = (int)$folder['parent_id'];
                
                // Определяем parent_id для новой папки
                $newParentId = null;
                if ($oldParentId == 0) {
                    // Корневые папки остаются корневыми (parent_id = NULL)
                    $newParentId = null;
                } else {
                    // Вложенные папки пока ставим parent_id = new_project_id (временно)
                    $newParentId = $newProjectId;
                }
                
                // Вставляем папку с новым project_id
                try {
                    $connection->executeStatement(
                        "INSERT INTO fw_plan_folders (name, parent_id, project_id, created_at, updated_at, edited) 
                         VALUES (?, ?, ?, ?, ?, 0)",
                        [
                            $folder['name'],
                            $newParentId,
                            $newProjectId,
                            $folder['created_at'],
                            $folder['updated_at']
                        ]
                    );
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to insert default folder', [
                        'new_project_id' => $newProjectId,
                        'folder' => $folder,
                        'error' => $e->getMessage()
                    ]);
                    continue;
                }
                
                $newId = $connection->lastInsertId();
                $idMapping[$oldId] = $newId;
            }

            // Второй проход: обновляем parent_id для вложенных папок
            foreach ($templateFolders as $folder) {
                $oldId = (int)$folder['id'];
                $oldParentId = (int)$folder['parent_id'];
                
                if ($oldParentId > 0 && isset($idMapping[$oldParentId])) {
                    // Если это вложенная папка и мы знаем новый ID родительской папки
                    $newId = $idMapping[$oldId];
                    $newParentId = $idMapping[$oldParentId];
                    
                    try {
                        $connection->executeStatement(
                            "UPDATE fw_plan_folders 
                             SET parent_id = ? 
                             WHERE id = ?",
                            [$newParentId, $newId]
                        );
                    } catch (\Throwable $e) {
                        $this->logger->error('Failed to update parent_id for folder', [
                            'folder_id' => $newId,
                            'new_parent_id' => $newParentId,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            $this->logger->info('Default folder structure copied successfully', [
                'new_project_id' => $newProjectId,
                'folders_copied' => count($templateFolders),
                'id_mapping' => $idMapping
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to copy default folder structure', [
                'new_project_id' => $newProjectId,
                'error' => $e->getMessage()
            ]);
            // Не прерываем создание проекта, если копирование папок не удалось
        }
    }

    /**
     * Безопасная обработка поля client_data из БД
     * Обрабатывает случаи когда поле может быть JSON строкой, уже декодированным массивом, или NULL
     * 
     * @param mixed $clientData Значение client_data из БД
     * @return array|null Декодированный массив или null
     */
    private function parseClientData($clientData): ?array
    {
        // Если null или пустая строка
        if ($clientData === null || $clientData === '') {
            return null;
        }

        // Если уже массив - возвращаем как есть
        if (is_array($clientData)) {
            return $clientData;
        }

        // Если не строка - возвращаем null
        if (!is_string($clientData)) {
            $this->logger->warning('Unexpected client_data type', [
                'type' => gettype($clientData),
                'value' => $clientData
            ]);
            return null;
        }

        // Пытаемся декодировать JSON
        $decoded = json_decode($clientData, true);
        
        // Проверяем ошибки декодирования
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('Failed to decode client_data JSON', [
                'error' => json_last_error_msg(),
                'raw_value' => substr($clientData, 0, 200) // Логируем первые 200 символов
            ]);
            return null;
        }

        // Логируем что возвращается из client_data для диагностики
        if (is_array($decoded)) {
            $this->logger->debug('Parsed client_data from DB', [
                'keys' => array_keys($decoded),
                'keys_count' => count($decoded),
                'sample' => array_slice($decoded, 0, 3, true) // Первые 3 элемента для примера
            ]);
        }

        return $decoded;
    }

    /**
     * Безопасное кодирование client_data для сохранения в БД
     * Обрабатывает случаи когда значение может быть уже строкой JSON, массивом, или NULL
     * 
     * @param mixed $clientData Значение client_data для сохранения
     * @return string|null JSON строка или null
     */
    private function encodeClientData($clientData): ?string
    {
        // Если null - возвращаем null
        if ($clientData === null) {
            return null;
        }

        // Если уже строка - проверяем, является ли она валидным JSON
        if (is_string($clientData)) {
            // Пытаемся декодировать, чтобы проверить валидность
            $decoded = json_decode($clientData, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                // Это валидный JSON - возвращаем как есть
                return $clientData;
            } else {
                // Не валидный JSON - логируем предупреждение и кодируем как строку
                $this->logger->warning('client_data is string but not valid JSON, encoding as string value', [
                    'raw_value' => substr($clientData, 0, 200)
                ]);
                return json_encode($clientData);
            }
        }

        // Если массив или объект - кодируем в JSON
        if (is_array($clientData) || is_object($clientData)) {
            $encoded = json_encode($clientData);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error('Failed to encode client_data to JSON', [
                    'error' => json_last_error_msg()
                ]);
                return null;
            }
            return $encoded;
        }

        // Для других типов - кодируем значение
        $encoded = json_encode($clientData);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('Unexpected client_data type for encoding', [
                'type' => gettype($clientData)
            ]);
            return null;
        }
        return $encoded;
    }

    /**
     * Получить имя клиента из соответствующей таблицы
     * 
     * @param string|null $clientTable Тип таблицы клиента
     * @param int|null $clientId ID клиента
     * @return string|null Имя клиента или null
     */
    private function getClientName(?string $clientTable, ?int $clientId): ?string
    {
        if (!$clientTable || !$clientId) {
            return null;
        }

        // Маппинг типов таблиц на их реальные имена в БД и поля name
        $clientTables = [
            'pharma' => [
                'table' => 'pharma',
                'name_field' => 'operName'
            ],
            'physician' => [
                'table' => 'physician',
                'name_field' => 'fullName'
            ],
            'pharmacist' => [
                'table' => 'pharmacist',
                'name_field' => 'fullName'
            ],
            'medical_clinic' => [
                'table' => 'medical_clinic',
                'name_field' => 'clinicName'
            ]
        ];

        if (!isset($clientTables[$clientTable])) {
            $this->logger->warning('Unknown client_table type', [
                'client_table' => $clientTable,
                'client_id' => $clientId
            ]);
            return null;
        }

        try {
            $connection = $this->database->getConnection();
            $tableConfig = $clientTables[$clientTable];
            
            $sql = "SELECT {$tableConfig['name_field']} as name 
                    FROM {$tableConfig['table']} 
                    WHERE id = ? 
                    LIMIT 1";
            
            $result = $connection->executeQuery($sql, [$clientId]);
            $client = $result->fetchAssociative();
            
            return $client['name'] ?? null;
        } catch (Exception $e) {
            $this->logger->error('Failed to get client name', [
                'client_table' => $clientTable,
                'client_id' => $clientId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Получить имя клиента с fallback на client_data
     * 
     * @param array $project Данные проекта из БД
     * @param array|null $clientData Распарсенные данные client_data
     * @return string|null Имя клиента или null
     */
    private function getClientNameWithFallback(array $project, ?array $clientData): ?string
    {
        // Сначала пробуем получить из поля client_name в БД
        $clientName = $project['client_name'] ?? null;
        
        if ($clientName) {
            return $clientName;
        }
        
        // Если client_name не заполнен, пробуем получить из client_data
        if ($clientData && is_array($clientData)) {
            // Пробуем разные поля в зависимости от типа клиента
            // Для pharma - operName
            // Для physician/pharmacist - fullName
            // Для medical_clinic - clinicName
            // Также пробуем общее поле name
            $clientName = $clientData['operName'] 
                ?? $clientData['fullName'] 
                ?? $clientData['clinicName'] 
                ?? $clientData['name'] 
                ?? null;
        }
        
        // Если все еще нет имени, но есть client_id и client_table, пробуем получить из БД
        if (!$clientName && !empty($project['client_id']) && !empty($project['client_table'])) {
            $clientName = $this->getClientName($project['client_table'], (int)$project['client_id']);
        }
        
        return $clientName;
    }

    /**
     * Get client2 display name with fallback (client2_name, client2_data, or lookup by client2_id/client2_table)
     * @param array $project Row with client2_* keys
     * @param array|null $client2Data Parsed client2_data
     * @return string|null
     */
    private function getClient2NameWithFallback(array $project, ?array $client2Data): ?string
    {
        $clientName = $project['client2_name'] ?? null;
        if ($clientName) {
            return $clientName;
        }
        if ($client2Data && is_array($client2Data)) {
            $clientName = $client2Data['operName']
                ?? $client2Data['fullName']
                ?? $client2Data['clinicName']
                ?? $client2Data['name']
                ?? null;
        }
        if (!$clientName && !empty($project['client2_id']) && !empty($project['client2_table'])) {
            $clientName = $this->getClientName($project['client2_table'], (int)$project['client2_id']);
        }
        return $clientName;
    }

    private function projectForemanColumnPresent(\Doctrine\DBAL\Connection $connection): bool
    {
        return $this->taskAuth->projectForemanColumnPresent($connection);
    }

    private function projectGeoColumnsPresent(\Doctrine\DBAL\Connection $connection): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        try {
            $cols = $connection->createSchemaManager()->listTableColumns('fw_projects');
            $cached = isset($cols['latitude']) && isset($cols['longitude']);
        } catch (\Throwable) {
            $cached = false;
        }
        return $cached;
    }

    /**
     * Best-effort geocode of project address into latitude/longitude. Never throws to callers.
     */
    private function refreshProjectGeocode(\Doctrine\DBAL\Connection $connection, int $projectId, ?string $address): void
    {
        if ($projectId <= 0 || !$this->projectGeoColumnsPresent($connection)) {
            return;
        }
        if ($address === null || trim($address) === '') {
            try {
                $connection->executeStatement(
                    'UPDATE fw_projects SET latitude = NULL, longitude = NULL, updated_at = NOW() WHERE id = ?',
                    [$projectId]
                );
            } catch (\Throwable $e) {
                $this->logger->warning('Clear project geo failed', ['project_id' => $projectId, 'error' => $e->getMessage()]);
            }
            return;
        }
        try {
            $geo = (new \App\Services\GeocodeService($this->logger))->geocodeAddress($address);
            if ($geo === null) {
                return;
            }
            $connection->executeStatement(
                'UPDATE fw_projects SET latitude = ?, longitude = ?, updated_at = NOW() WHERE id = ?',
                [$geo['lat'], $geo['lng'], $projectId]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Project geocode failed', [
                'project_id' => $projectId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function projectForemanSelectSql(\Doctrine\DBAL\Connection $connection): string
    {
        if (!$this->projectForemanColumnPresent($connection)) {
            return '';
        }

        return ', p.project_foreman_id, pf.first_name as project_foreman_first_name, pf.last_name as project_foreman_last_name';
    }

    private function projectForemanJoinSql(\Doctrine\DBAL\Connection $connection): string
    {
        if (!$this->projectForemanColumnPresent($connection)) {
            return '';
        }

        return ' LEFT JOIN fw_v_users pf ON p.project_foreman_id = pf.id';
    }

    /**
     * @param array<string, mixed> $project
     * @param array<string, mixed> $formatted
     * @return array<string, mixed>
     */
    private function appendProjectForemanFields(array $project, array $formatted): array
    {
        if (!array_key_exists('project_foreman_id', $project)) {
            return $formatted;
        }

        $formatted['project_foreman_id'] = $project['project_foreman_id']
            ? (int) $project['project_foreman_id']
            : null;
        $formatted['project_foreman_name'] = !empty($project['project_foreman_first_name']) || !empty($project['project_foreman_last_name'])
            ? trim(($project['project_foreman_first_name'] ?? '') . ' ' . ($project['project_foreman_last_name'] ?? ''))
            : null;

        return $formatted;
    }

    private function propagateProjectForemanToTaskLeads(
        \Doctrine\DBAL\Connection $connection,
        int $projectId,
        int $newForemanId,
    ): int {
        $tasks = $connection->executeQuery(
            'SELECT id, milestone FROM fw_prj_tasks WHERE project_id = ?',
            [$projectId]
        )->fetchAllAssociative();

        $updated = 0;
        foreach ($tasks as $task) {
            $taskId = (int) $task['id'];
            $milestone = $task['milestone'] ?? null;
            if ($milestone !== null && $milestone !== '') {
                // Milestones keep their own lead (often PM); do not overwrite.
                continue;
            }

            $assigneeRows = $connection->executeQuery(
                'SELECT id, role_in_project FROM fw_prj_team_members WHERE task_id = ? AND project_id = ?',
                [$taskId, $projectId]
            )->fetchAllAssociative();

            $leadRowId = null;
            foreach ($assigneeRows as $row) {
                if ($this->taskAuth->isTaskLeadProjectRole($row['role_in_project'] ?? null)) {
                    $leadRowId = (int) $row['id'];
                    break;
                }
            }

            if ($leadRowId !== null) {
                $connection->executeStatement(
                    'UPDATE fw_prj_team_members SET user_id = ? WHERE id = ?',
                    [$newForemanId, $leadRowId]
                );
                $updated++;
                continue;
            }

            // Legacy tasks may have no task_lead row — create one.
            try {
                $connection->executeStatement(
                    "INSERT INTO fw_prj_team_members (project_id, task_id, user_id, role_in_project) VALUES (?, ?, ?, 'task_lead')",
                    [$projectId, $taskId, $newForemanId]
                );
                $updated++;
            } catch (\Exception $e) {
                $this->logger->warning('Failed to assign project foreman to task during propagation', [
                    'project_id' => $projectId,
                    'task_id' => $taskId,
                    'foreman_id' => $newForemanId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->logger->info('Propagated project foreman to task leads', [
            'project_id' => $projectId,
            'foreman_id' => $newForemanId,
            'tasks_updated' => $updated,
        ]);

        return $updated;
    }

    private function projectClientsTablePresent(\Doctrine\DBAL\Connection $connection): bool
    {
        if (self::$projectClientsTableExists !== null) {
            return self::$projectClientsTableExists;
        }

        try {
            $row = $connection->executeQuery(
                "SHOW TABLES LIKE 'fw_project_clients'"
            )->fetchOne();
            self::$projectClientsTableExists = !empty($row);
        } catch (\Exception $e) {
            self::$projectClientsTableExists = false;
        }

        return self::$projectClientsTableExists;
    }

    private function locationsOfInterestColumnPresent(\Doctrine\DBAL\Connection $connection): bool
    {
        if (self::$locationsOfInterestColumnExists !== null) {
            return self::$locationsOfInterestColumnExists;
        }

        try {
            $row = $connection->executeQuery(
                "SHOW COLUMNS FROM fw_projects LIKE 'locations_of_interest'"
            )->fetchOne();
            self::$locationsOfInterestColumnExists = !empty($row);
        } catch (\Exception $e) {
            self::$locationsOfInterestColumnExists = false;
        }

        return self::$locationsOfInterestColumnExists;
    }

    private function locationsOfInterestSelectSql(\Doctrine\DBAL\Connection $connection): string
    {
        if (!$this->locationsOfInterestColumnPresent($connection)) {
            return '';
        }

        return ', p.locations_of_interest';
    }

    private function clinicModelTypeColumnPresent(\Doctrine\DBAL\Connection $connection): bool
    {
        if (self::$clinicModelTypeColumnExists !== null) {
            return self::$clinicModelTypeColumnExists;
        }

        try {
            $row = $connection->executeQuery(
                "SHOW COLUMNS FROM fw_projects LIKE 'clinic_model_type'"
            )->fetchOne();
            self::$clinicModelTypeColumnExists = !empty($row);
        } catch (\Exception $e) {
            self::$clinicModelTypeColumnExists = false;
        }

        return self::$clinicModelTypeColumnExists;
    }

    private function clinicModelTypeSelectSql(\Doctrine\DBAL\Connection $connection): string
    {
        if (!$this->clinicModelTypeColumnPresent($connection)) {
            return '';
        }

        return ', p.clinic_model_type';
    }

    private function healthcareServicesColumnPresent(\Doctrine\DBAL\Connection $connection): bool
    {
        if (self::$healthcareServicesColumnExists !== null) {
            return self::$healthcareServicesColumnExists;
        }

        try {
            $row = $connection->executeQuery(
                "SHOW COLUMNS FROM fw_projects LIKE 'healthcare_services'"
            )->fetchOne();
            self::$healthcareServicesColumnExists = !empty($row);
        } catch (\Exception $e) {
            self::$healthcareServicesColumnExists = false;
        }

        return self::$healthcareServicesColumnExists;
    }

    private function healthcareServicesSelectSql(\Doctrine\DBAL\Connection $connection): string
    {
        if (!$this->healthcareServicesColumnPresent($connection)) {
            return '';
        }

        return ', p.healthcare_services';
    }

    private function projectInclusionsColumnPresent(\Doctrine\DBAL\Connection $connection): bool
    {
        if (self::$projectInclusionsColumnExists !== null) {
            return self::$projectInclusionsColumnExists;
        }
        try {
            self::$projectInclusionsColumnExists = !empty($connection->executeQuery(
                "SHOW COLUMNS FROM fw_projects LIKE 'project_inclusions'"
            )->fetchOne());
        } catch (\Exception $e) {
            self::$projectInclusionsColumnExists = false;
        }
        return self::$projectInclusionsColumnExists;
    }

    private function projectInclusionsSelectSql(\Doctrine\DBAL\Connection $connection): string
    {
        return $this->projectInclusionsColumnPresent($connection) ? ', p.project_inclusions' : '';
    }

    private function longTermFmTeamSizeColumnPresent(\Doctrine\DBAL\Connection $connection): bool
    {
        if (self::$longTermFmTeamSizeColumnExists !== null) {
            return self::$longTermFmTeamSizeColumnExists;
        }

        try {
            $row = $connection->executeQuery(
                "SHOW COLUMNS FROM fw_projects LIKE 'long_term_fm_team_size'"
            )->fetchOne();
            self::$longTermFmTeamSizeColumnExists = !empty($row);
        } catch (\Exception $e) {
            self::$longTermFmTeamSizeColumnExists = false;
        }

        return self::$longTermFmTeamSizeColumnExists;
    }

    private function longTermFmTeamSizeSelectSql(\Doctrine\DBAL\Connection $connection): string
    {
        if (!$this->longTermFmTeamSizeColumnPresent($connection)) {
            return '';
        }

        return ', p.long_term_fm_team_size';
    }

    private function monthlyBudgetFirstYearColumnPresent(\Doctrine\DBAL\Connection $connection): bool
    {
        if (self::$monthlyBudgetFirstYearColumnExists !== null) {
            return self::$monthlyBudgetFirstYearColumnExists;
        }

        try {
            $row = $connection->executeQuery(
                "SHOW COLUMNS FROM fw_projects LIKE 'monthly_budget_first_year'"
            )->fetchOne();
            self::$monthlyBudgetFirstYearColumnExists = !empty($row);
        } catch (\Exception $e) {
            self::$monthlyBudgetFirstYearColumnExists = false;
        }

        return self::$monthlyBudgetFirstYearColumnExists;
    }

    private function monthlyBudgetFirstYearSelectSql(\Doctrine\DBAL\Connection $connection): string
    {
        if (!$this->monthlyBudgetFirstYearColumnPresent($connection)) {
            return '';
        }

        return ', p.monthly_budget_first_year';
    }

    private function estClinicalHoursMdsOnSiteColumnPresent(\Doctrine\DBAL\Connection $connection): bool
    {
        if (self::$estClinicalHoursMdsOnSiteColumnExists !== null) {
            return self::$estClinicalHoursMdsOnSiteColumnExists;
        }

        try {
            $row = $connection->executeQuery(
                "SHOW COLUMNS FROM fw_projects LIKE 'est_clinical_hours_mds_on_site'"
            )->fetchOne();
            self::$estClinicalHoursMdsOnSiteColumnExists = !empty($row);
        } catch (\Exception $e) {
            self::$estClinicalHoursMdsOnSiteColumnExists = false;
        }

        return self::$estClinicalHoursMdsOnSiteColumnExists;
    }

    private function estClinicalHoursMdsOnSiteSelectSql(\Doctrine\DBAL\Connection $connection): string
    {
        if (!$this->estClinicalHoursMdsOnSiteColumnPresent($connection)) {
            return '';
        }

        return ', p.est_clinical_hours_mds_on_site';
    }

    private function hrVisionColumnPresent(\Doctrine\DBAL\Connection $connection): bool
    {
        if (self::$hrVisionColumnExists !== null) {
            return self::$hrVisionColumnExists;
        }

        try {
            $row = $connection->executeQuery(
                "SHOW COLUMNS FROM fw_projects LIKE 'hr_vision'"
            )->fetchOne();
            self::$hrVisionColumnExists = !empty($row);
        } catch (\Exception $e) {
            self::$hrVisionColumnExists = false;
        }

        return self::$hrVisionColumnExists;
    }

    private function hrVisionSelectSql(\Doctrine\DBAL\Connection $connection): string
    {
        if (!$this->hrVisionColumnPresent($connection)) {
            return '';
        }

        return ', p.hr_vision';
    }

    private function operationalHoursColumnPresent(\Doctrine\DBAL\Connection $connection): bool
    {
        if (self::$operationalHoursColumnExists !== null) {
            return self::$operationalHoursColumnExists;
        }
        try {
            self::$operationalHoursColumnExists = !empty($connection->executeQuery(
                "SHOW COLUMNS FROM fw_projects LIKE 'operational_hours'"
            )->fetchOne());
        } catch (\Exception $e) {
            self::$operationalHoursColumnExists = false;
        }
        return self::$operationalHoursColumnExists;
    }

    private function operationalHoursSelectSql(\Doctrine\DBAL\Connection $connection): string
    {
        return $this->operationalHoursColumnPresent($connection) ? ', p.operational_hours' : '';
    }

    private function contentsOfSpaceColumnPresent(\Doctrine\DBAL\Connection $connection): bool
    {
        if (self::$contentsOfSpaceColumnExists !== null) {
            return self::$contentsOfSpaceColumnExists;
        }
        try {
            self::$contentsOfSpaceColumnExists = !empty($connection->executeQuery(
                "SHOW COLUMNS FROM fw_projects LIKE 'contents_of_space'"
            )->fetchOne());
        } catch (\Exception $e) {
            self::$contentsOfSpaceColumnExists = false;
        }
        return self::$contentsOfSpaceColumnExists;
    }

    private function contentsOfSpaceSelectSql(\Doctrine\DBAL\Connection $connection): string
    {
        return $this->contentsOfSpaceColumnPresent($connection) ? ', p.contents_of_space' : '';
    }

    private function marketingStrategyColumnPresent(\Doctrine\DBAL\Connection $connection): bool
    {
        if (self::$marketingStrategyColumnExists !== null) {
            return self::$marketingStrategyColumnExists;
        }
        try {
            self::$marketingStrategyColumnExists = !empty($connection->executeQuery(
                "SHOW COLUMNS FROM fw_projects LIKE 'marketing_strategy'"
            )->fetchOne());
        } catch (\Exception $e) {
            self::$marketingStrategyColumnExists = false;
        }
        return self::$marketingStrategyColumnExists;
    }

    private function marketingStrategySelectSql(\Doctrine\DBAL\Connection $connection): string
    {
        return $this->marketingStrategyColumnPresent($connection) ? ', p.marketing_strategy' : '';
    }

    /**
     * @param mixed $raw
     * @return list<string>|null
     */
    private function parseHealthcareServices($raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            return $this->normalizeHealthcareServicesInput($raw);
        }
        if (!is_string($raw)) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $this->normalizeHealthcareServicesInput($decoded);
        }
        // Legacy single VARCHAR value
        return in_array($raw, self::ALLOWED_HEALTHCARE_SERVICES, true) ? [$raw] : null;
    }

    /**
     * @param mixed $input
     * @return list<string>|null
     */
    private function normalizeHealthcareServicesInput($input): ?array
    {
        if ($input === null || !is_array($input)) {
            return $input === null ? null : [];
        }
        $result = [];
        foreach ($input as $service) {
            if (is_string($service)
                && in_array($service, self::ALLOWED_HEALTHCARE_SERVICES, true)
                && !in_array($service, $result, true)
            ) {
                $result[] = $service;
            }
        }
        return $result;
    }

    /** @param list<string>|null $services */
    private function encodeHealthcareServices(?array $services): ?string
    {
        return $services === null ? null : json_encode(array_values($services));
    }

    /**
     * @param mixed $raw
     * @return list<string>|null
     */
    private function parseProjectInclusions($raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            return $this->normalizeProjectInclusionsInput($raw);
        }
        if (!is_string($raw)) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $this->normalizeProjectInclusionsInput($decoded);
        }
        return in_array($raw, self::ALLOWED_PROJECT_INCLUSIONS, true) ? [$raw] : null;
    }

    /**
     * @param mixed $input
     * @return list<string>|null
     */
    private function normalizeProjectInclusionsInput($input): ?array
    {
        if ($input === null || !is_array($input)) {
            return $input === null ? null : [];
        }
        $result = [];
        foreach ($input as $item) {
            if (is_string($item)
                && in_array($item, self::ALLOWED_PROJECT_INCLUSIONS, true)
                && !in_array($item, $result, true)
            ) {
                $result[] = $item;
            }
        }
        return $result;
    }

    /** @param list<string>|null $items */
    private function encodeProjectInclusions(?array $items): ?string
    {
        return $items === null ? null : json_encode(array_values($items));
    }

    private function optionalProjectTextColumnPresent(
        \Doctrine\DBAL\Connection $connection,
        string $field
    ): bool {
        if (!array_key_exists($field, self::OPTIONAL_PROJECT_TEXT_FIELDS)) {
            return false;
        }
        if (array_key_exists($field, self::$optionalProjectTextColumnExists)
            && self::$optionalProjectTextColumnExists[$field] !== null
        ) {
            return self::$optionalProjectTextColumnExists[$field];
        }
        try {
            self::$optionalProjectTextColumnExists[$field] = !empty($connection->executeQuery(
                "SHOW COLUMNS FROM fw_projects LIKE '{$field}'"
            )->fetchOne());
        } catch (\Exception $e) {
            self::$optionalProjectTextColumnExists[$field] = false;
        }
        return (bool) self::$optionalProjectTextColumnExists[$field];
    }

    private function optionalProjectTextFieldsSelectSql(\Doctrine\DBAL\Connection $connection): string
    {
        $parts = [];
        foreach (array_keys(self::OPTIONAL_PROJECT_TEXT_FIELDS) as $field) {
            if ($this->optionalProjectTextColumnPresent($connection, $field)) {
                $parts[] = ", p.{$field}";
            }
        }
        return implode('', $parts);
    }

    /**
     * @param mixed $value
     */
    private function normalizeOptionalProjectTextValue($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param mixed $raw
     * @return list<string>|null
     */
    private function parseLocationsOfInterest($raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_array($raw)) {
            return $this->normalizeLocationsOfInterestInput($raw);
        }

        if (!is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return null;
        }

        return $this->normalizeLocationsOfInterestInput($decoded);
    }

    /**
     * @param mixed $input
     * @return list<string>|null null means empty/cleared
     */
    private function normalizeLocationsOfInterestInput($input): ?array
    {
        if ($input === null) {
            return null;
        }
        if (!is_array($input)) {
            return null;
        }
        if ($input === []) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($input as $code) {
            if (!is_string($code)) {
                continue;
            }
            $normalized = strtoupper(trim($code));
            if (!preg_match('/^[A-Z]\d[A-Z]$/', $normalized)) {
                continue;
            }
            if (isset($seen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $out[] = $normalized;
        }

        return $out;
    }

    /**
     * @param list<string>|null $codes
     */
    private function encodeLocationsOfInterest(?array $codes): ?string
    {
        if ($codes === null) {
            return null;
        }

        return json_encode(array_values($codes));
    }

    /**
     * @param mixed $raw
     * @return list<string>|null
     */
    private function parseHrVision($raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_array($raw)) {
            return $this->normalizeHrVisionInput($raw);
        }

        if (!is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return null;
        }

        return $this->normalizeHrVisionInput($decoded);
    }

    /**
     * @param mixed $input
     * @return list<string>|null null means empty/cleared
     */
    private function normalizeHrVisionInput($input): ?array
    {
        if ($input === null) {
            return null;
        }
        if (!is_array($input)) {
            return null;
        }

        $out = [];
        $seen = [];
        foreach ($input as $specialty) {
            if (!is_string($specialty) || !in_array($specialty, self::ALLOWED_HR_VISION_SPECIALTIES, true)) {
                continue;
            }
            if (isset($seen[$specialty])) {
                continue;
            }
            $seen[$specialty] = true;
            $out[] = $specialty;
        }

        return $out;
    }

    /**
     * @param list<string>|null $specialties
     */
    private function encodeHrVision(?array $specialties): ?string
    {
        if ($specialties === null) {
            return null;
        }

        return json_encode(array_values($specialties));
    }

    /**
     * @param mixed $raw
     * @return list<string>|null
     */
    private function parseMarketingStrategy($raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            return $this->normalizeMarketingStrategyInput($raw);
        }
        if (!is_string($raw)) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return json_last_error() === JSON_ERROR_NONE && is_array($decoded)
            ? $this->normalizeMarketingStrategyInput($decoded)
            : null;
    }

    /**
     * @param mixed $input
     * @return list<string>|null
     */
    private function normalizeMarketingStrategyInput($input): ?array
    {
        if ($input === null || !is_array($input)) {
            return $input === null ? null : [];
        }
        $result = [];
        foreach ($input as $strategy) {
            if (is_string($strategy)
                && in_array($strategy, self::ALLOWED_MARKETING_STRATEGIES, true)
                && !in_array($strategy, $result, true)
            ) {
                $result[] = $strategy;
            }
        }
        return $result;
    }

    /** @param list<string>|null $strategies */
    private function encodeMarketingStrategy(?array $strategies): ?string
    {
        return $strategies === null ? null : json_encode(array_values($strategies));
    }

    /**
     * @param mixed $raw
     * @return array{days: list<array{day:string, open:?string, close:?string}>}|null
     */
    private function parseOperationalHours($raw): ?array
    {
        if ($raw === null || $raw === '') {
            return $this->normalizeOperationalHoursInput(null);
        }
        if (is_array($raw)) {
            return $this->normalizeOperationalHoursInput($raw);
        }
        if (!is_string($raw)) {
            return $this->normalizeOperationalHoursInput(null);
        }
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            // Legacy free-text values are discarded
            return $this->normalizeOperationalHoursInput(null);
        }
        return $this->normalizeOperationalHoursInput($decoded);
    }

    /**
     * @param mixed $input
     * @return array{days: list<array{day:string, open:?string, close:?string}>}|null
     */
    private function normalizeOperationalHoursInput($input): ?array
    {
        if ($input !== null && !is_array($input)) {
            return null;
        }

        $byDay = [];
        $daysInput = [];
        if (is_array($input)) {
            if (isset($input['days']) && is_array($input['days'])) {
                $daysInput = $input['days'];
            } elseif ($input !== [] && array_keys($input) === range(0, count($input) - 1)) {
                $daysInput = $input;
            }
        }

        foreach ($daysInput as $row) {
            if (!is_array($row) || !isset($row['day']) || !is_string($row['day'])) {
                continue;
            }
            $day = $row['day'];
            if (!in_array($day, self::OPERATIONAL_HOURS_DAYS, true)) {
                continue;
            }
            $byDay[$day] = [
                'day' => $day,
                'open' => $this->normalizeOperationalHoursTime($row['open'] ?? null),
                'close' => $this->normalizeOperationalHoursTime($row['close'] ?? null),
            ];
        }

        $days = [];
        foreach (self::OPERATIONAL_HOURS_DAYS as $day) {
            $days[] = $byDay[$day] ?? [
                'day' => $day,
                'open' => null,
                'close' => null,
            ];
        }

        return ['days' => $days];
    }

    /**
     * @param mixed $raw
     */
    private function normalizeOperationalHoursTime($raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw)) {
            return null;
        }
        return in_array($raw, self::OPERATIONAL_HOURS_TIME_OPTIONS, true) ? $raw : null;
    }

    /**
     * @param array{days: list<array{day:string, open:?string, close:?string}>}|null $data
     */
    private function encodeOperationalHours(?array $data): ?string
    {
        $normalized = $this->normalizeOperationalHoursInput($data);
        return $normalized === null ? null : json_encode($normalized);
    }

    /**
     * Open must be strictly before Close for clock times.
     * "24 Hours" is valid only when both Open and Close are "24 Hours".
     *
     * @param array{days: list<array{day:string, open:?string, close:?string}>} $data
     */
    private function validateOperationalHoursOpenBeforeClose(array $data): ?string
    {
        $invalid = [];
        foreach ($data['days'] as $row) {
            $day = $row['day'] ?? '';
            $open = $row['open'] ?? null;
            $close = $row['close'] ?? null;
            if ($open === null || $close === null || $open === '' || $close === '') {
                continue;
            }
            if ($open === '24 Hours' || $close === '24 Hours') {
                if ($open === '24 Hours' && $close === '24 Hours') {
                    continue;
                }
                $invalid[] = $day;
                continue;
            }
            $openMinutes = $this->operationalHoursTimeToMinutes((string) $open);
            $closeMinutes = $this->operationalHoursTimeToMinutes((string) $close);
            if ($openMinutes === null || $closeMinutes === null) {
                continue;
            }
            if ($openMinutes >= $closeMinutes) {
                $invalid[] = $day;
            }
        }
        if ($invalid === []) {
            return null;
        }
        return 'Open must be before Close for: ' . implode(', ', $invalid);
    }

    private function operationalHoursTimeToMinutes(string $value): ?int
    {
        if ($value === '24 Hours') {
            return null;
        }
        if (!preg_match('/^(\d{1,2})(am|pm)$/', $value, $matches)) {
            return null;
        }
        $hour = (int) $matches[1];
        $meridiem = $matches[2];
        if ($meridiem === 'am') {
            if ($hour === 12) {
                $hour = 0;
            }
        } elseif ($hour !== 12) {
            $hour += 12;
        }
        return $hour * 60;
    }

    /**
     * @param mixed $raw
     * @return array{rows: list<array<string, mixed>>}|null
     */
    private function parseContentsOfSpace($raw): ?array
    {
        if ($raw === null || $raw === '') {
            return $this->normalizeContentsOfSpaceInput(null);
        }
        if (is_array($raw)) {
            return $this->normalizeContentsOfSpaceInput($raw);
        }
        if (!is_string($raw)) {
            return $this->normalizeContentsOfSpaceInput(null);
        }
        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            // Legacy free-text values are discarded
            return $this->normalizeContentsOfSpaceInput(null);
        }
        return $this->normalizeContentsOfSpaceInput($decoded);
    }

    /**
     * Build a complete Contents of Space payload (zeros / null selects).
     *
     * @param mixed $input
     * @return array{rows: list<array<string, mixed>>}|null
     */
    private function normalizeContentsOfSpaceInput($input): ?array
    {
        if ($input !== null && !is_array($input)) {
            return null;
        }

        $quantityByKey = [];
        $valueByKey = [];

        $rowsInput = [];
        if (is_array($input)) {
            if (isset($input['rows']) && is_array($input['rows'])) {
                $rowsInput = $input['rows'];
            } elseif ($input !== [] && array_keys($input) === range(0, count($input) - 1)) {
                $rowsInput = $input;
            }
        }

        foreach ($rowsInput as $row) {
            if (!is_array($row) || !isset($row['key'], $row['kind']) || !is_string($row['key'])) {
                continue;
            }
            $key = $row['key'];
            if ($row['kind'] === 'calc' && isset(self::CONTENTS_OF_SPACE_CALC_SET_SIZES[$key])) {
                $qty = $row['quantity'] ?? 0;
                if ($qty === null || $qty === '') {
                    $qty = 0;
                }
                if (!is_numeric($qty) || (float) $qty < 0) {
                    $qty = 0;
                }
                $quantityByKey[$key] = (int) floor((float) $qty);
            } elseif ($row['kind'] === 'select' && isset(self::CONTENTS_OF_SPACE_SELECT_OPTIONS[$key])) {
                $value = $row['value'] ?? null;
                if ($value === null || $value === '') {
                    $valueByKey[$key] = null;
                } elseif (is_numeric($value) && in_array((int) $value, self::CONTENTS_OF_SPACE_SELECT_OPTIONS[$key], true)) {
                    $valueByKey[$key] = (int) $value;
                } else {
                    $valueByKey[$key] = null;
                }
            }
        }

        $rows = [];
        foreach (self::CONTENTS_OF_SPACE_CALC_SET_SIZES as $key => $_setSize) {
            $rows[] = [
                'key' => $key,
                'kind' => 'calc',
                'quantity' => $quantityByKey[$key] ?? 0,
            ];
        }
        foreach (self::CONTENTS_OF_SPACE_SELECT_OPTIONS as $key => $_options) {
            $rows[] = [
                'key' => $key,
                'kind' => 'select',
                'value' => array_key_exists($key, $valueByKey) ? $valueByKey[$key] : null,
            ];
        }

        return ['rows' => $rows];
    }

    /**
     * @param array{rows: list<array<string, mixed>>}|null $data
     */
    private function encodeContentsOfSpace(?array $data): ?string
    {
        $normalized = $this->normalizeContentsOfSpaceInput($data);
        return $normalized === null ? null : json_encode($normalized);
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, array{client_id:int, client_type:?string, client_table:string, client_data:?array, client_name:?string}>
     */
    private function normalizeAdditionalClientsPayload(array $items, ?int $primaryClientId, ?string $primaryClientTable): array
    {
        $normalized = [];
        $seen = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $clientId = isset($item['client_id']) ? (int) $item['client_id'] : 0;
            $clientTable = isset($item['client_table']) ? (string) $item['client_table'] : '';
            if ($clientId <= 0 || !in_array($clientTable, self::ALLOWED_CLIENT_TABLES, true)) {
                continue;
            }
            if (
                $primaryClientId !== null
                && $primaryClientTable !== null
                && $clientId === $primaryClientId
                && $clientTable === $primaryClientTable
            ) {
                continue;
            }
            $key = $clientTable . ':' . $clientId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $clientName = isset($item['client_name']) && is_string($item['client_name']) && $item['client_name'] !== ''
                ? $item['client_name']
                : $this->getClientName($clientTable, $clientId);

            $normalized[] = [
                'client_id' => $clientId,
                'client_type' => isset($item['client_type']) ? (string) $item['client_type'] : null,
                'client_table' => $clientTable,
                'client_data' => is_array($item['client_data'] ?? null) ? $item['client_data'] : null,
                'client_name' => $clientName,
            ];
        }

        return $normalized;
    }

    /**
     * Replace non-primary rows and mirror primary into fw_project_clients.
     *
     * @param array<int, array{client_id:int, client_type:?string, client_table:string, client_data:?array, client_name:?string}> $additional
     */
    private function syncProjectClients(
        \Doctrine\DBAL\Connection $connection,
        int $projectId,
        ?int $primaryClientId,
        ?string $primaryClientType,
        ?string $primaryClientTable,
        $primaryClientData,
        ?string $primaryClientName,
        array $additional,
    ): void {
        if (!$this->projectClientsTablePresent($connection)) {
            return;
        }

        $connection->executeStatement(
            'DELETE FROM fw_project_clients WHERE project_id = ?',
            [$projectId]
        );

        if (
            $primaryClientId
            && $primaryClientTable
            && in_array($primaryClientTable, self::ALLOWED_CLIENT_TABLES, true)
        ) {
            $connection->executeStatement(
                'INSERT INTO fw_project_clients
                    (project_id, client_id, client_type, client_table, client_name, client_data, is_primary, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, 1, 0)',
                [
                    $projectId,
                    $primaryClientId,
                    $primaryClientType,
                    $primaryClientTable,
                    $primaryClientName,
                    $this->encodeClientData($primaryClientData),
                ]
            );
        }

        $sort = 1;
        foreach ($additional as $row) {
            $connection->executeStatement(
                'INSERT INTO fw_project_clients
                    (project_id, client_id, client_type, client_table, client_name, client_data, is_primary, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, 0, ?)',
                [
                    $projectId,
                    $row['client_id'],
                    $row['client_type'],
                    $row['client_table'],
                    $row['client_name'],
                    $this->encodeClientData($row['client_data'] ?? null),
                    $sort,
                ]
            );
            $sort++;
        }
    }

    /**
     * @param array<int, int|string> $projectIds
     * @return array<int, list<array<string, mixed>>>
     */
    private function loadAdditionalClientsByProjectIds(\Doctrine\DBAL\Connection $connection, array $projectIds): array
    {
        $out = [];
        foreach ($projectIds as $id) {
            $out[(int) $id] = [];
        }

        if ($projectIds === [] || !$this->projectClientsTablePresent($connection)) {
            return $out;
        }

        $ids = array_values(array_unique(array_map('intval', $projectIds)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $connection->executeQuery(
            "SELECT id, project_id, client_id, client_type, client_table, client_name, client_data, sort_order
             FROM fw_project_clients
             WHERE project_id IN ({$placeholders}) AND is_primary = 0
             ORDER BY project_id ASC, sort_order ASC, id ASC",
            $ids
        )->fetchAllAssociative();

        foreach ($rows as $row) {
            $projectId = (int) $row['project_id'];
            $out[$projectId][] = [
                'id' => (int) $row['id'],
                'client_id' => (int) $row['client_id'],
                'client_type' => $row['client_type'] ?? null,
                'client_table' => $row['client_table'] ?? null,
                'client_name' => $row['client_name'] ?? null,
                'client_data' => $this->parseClientData($row['client_data'] ?? null),
                'sort_order' => isset($row['sort_order']) ? (int) $row['sort_order'] : 0,
            ];
        }

        return $out;
    }

    /**
     * Fallback when junction table empty: expose legacy client2 as single additional client.
     *
     * @param array<string, mixed> $project
     * @return list<array<string, mixed>>
     */
    private function additionalClientsFromLegacyClient2(array $project): array
    {
        if (empty($project['client2_id']) || empty($project['client2_table'])) {
            return [];
        }
        if (!in_array((string) $project['client2_table'], self::ALLOWED_CLIENT_TABLES, true)) {
            return [];
        }

        $client2Data = $this->parseClientData($project['client2_data'] ?? null);

        return [[
            'id' => null,
            'client_id' => (int) $project['client2_id'],
            'client_type' => $project['client2_type'] ?? null,
            'client_table' => $project['client2_table'],
            'client_name' => $this->getClient2NameWithFallback($project, $client2Data),
            'client_data' => $client2Data,
            'sort_order' => 1,
        ]];
    }

    /**
     * Mirror first additional client into legacy client2_* columns.
     *
     * @param array<int, array{client_id:int, client_type:?string, client_table:string, client_data:?array, client_name:?string}> $additional
     * @return array{client2_id:?int, client2_type:?string, client2_table:?string, client2_data:mixed, client2_name:?string}
     */
    private function client2FieldsFromAdditional(array $additional): array
    {
        if ($additional === []) {
            return [
                'client2_id' => null,
                'client2_type' => null,
                'client2_table' => null,
                'client2_data' => null,
                'client2_name' => null,
            ];
        }

        $first = $additional[0];

        return [
            'client2_id' => $first['client_id'],
            'client2_type' => $first['client_type'],
            'client2_table' => $first['client_table'],
            'client2_data' => $first['client_data'],
            'client2_name' => $first['client_name'],
        ];
    }
}
