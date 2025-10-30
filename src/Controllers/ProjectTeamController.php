<?php

namespace App\Controllers;

use App\Database\Database;
use Exception;
use Monolog\Logger;

/**
 * @OA\Tag(
 *     name="Project Team",
 *     description="Project team management endpoints"
 * )
 */
class ProjectTeamController
{
    private $database;
    private $logger;

    public function __construct(Logger $logger)
    {
        $this->database = new Database();
        $this->logger = $logger;
    }

    /**
     * @OA\Get(
     *     path="/api/v1/projects/{project_id}/team",
     *     tags={"Project Team"},
     *     summary="Get project team members",
     *     description="Retrieve all team members for a specific project",
     *     @OA\Parameter(
     *         name="project_id",
     *         in="path",
     *         required=true,
     *         description="Project ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         required=false,
     *         description="Page number for pagination",
     *         @OA\Schema(type="integer", default=1)
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         required=false,
     *         description="Number of items per page",
     *         @OA\Schema(type="integer", default=50)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Team members retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Team members retrieved successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(
     *                     property="team_members",
     *                     type="array",
     *                     @OA\Items(
     *                         @OA\Property(property="id", type="integer", example=1),
     *                         @OA\Property(property="project_id", type="integer", example=9),
     *                         @OA\Property(property="user_id", type="integer", example=47),
     *                         @OA\Property(property="role", type="string", example="lead"),
     *                         @OA\Property(property="added_at", type="string", format="date-time", example="2025-01-15T10:00:00Z"),
     *                         @OA\Property(property="added_by", type="integer", example=1),
     *                         @OA\Property(property="name", type="string", example="Mike Davis"),
     *                         @OA\Property(property="email", type="string", example="mike@example.com"),
     *                         @OA\Property(property="", type="string", example="Project Manager"),
     *                         @OA\Property(property="job_title", type="string", example="Senior PM"),
     *                         @OA\Property(property="status", type="integer", example=1)
     *                     )
     *                 ),
     *                 @OA\Property(
     *                     property="pagination",
     *                     type="object",
     *                     @OA\Property(property="current_page", type="integer", example=1),
     *                     @OA\Property(property="per_page", type="integer", example=50),
     *                     @OA\Property(property="total", type="integer", example=5),
     *                     @OA\Property(property="last_page", type="integer", example=1)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Project not found"
     *     )
     * )
     */
    public function getTeamMembers($projectId)
    {
        try {
            if (!$this->checkProjectExists($projectId)) {
                return $this->errorResponse('Project not found', 404);
            }

            $page = (int) ($_GET['page'] ?? 1);
            $limit = (int) ($_GET['limit'] ?? 50);
            $offset = ($page - 1) * $limit;

            $connection = $this->database->getConnection();

            // Get total count (exclude System Administrator and Project Manager)
            $countSql = "
                SELECT COUNT(*) as total 
                FROM fw_prj_team_members tm
                JOIN fw_v_users u ON tm.user_id = u.id
                WHERE tm.project_id = ? 
                  AND u.archived_at IS NULL 
                  AND u.role_code NOT IN ('admin', 'project_manager')
            ";
            $countResult = $connection->executeQuery($countSql, [$projectId]);
            $total = $countResult->fetchOne();

            // Get team members with all user data (exclude System Administrator and Project Manager)
            $sql = "
                SELECT 
                    -- Fields from fw_prj_team_members
                    tm.id as team_member_id,
                    tm.project_id,
                    tm.role_in_project as role,
                    tm.assigned_at as added_at,
                    
                    -- All fields from fw_v_users
                    u.id,
                    u.email,
                    u.password_hash,
                    u.first_name,
                    u.last_name,
                    u.phone,
                    u.role_id,
                    u.job_title,
                    u.status,
                    u.status_reason,
                    u.status_details,
                    u.additional_info,
                    u.full_img_url,
                    u.avatar_url,
                    u.two_factor_enabled,
                    u.two_factor_secret,
                    u.last_login,
                    u.status_changed_at,
                    u.status_end_at,
                    u.dob,
                    u.gender,
                    u.nationality,
                    u.country_of_origin,
                    u.workforce_group,
                    u.city,
                    u.emergency,
                    u.created_at,
                    u.updated_at,
                    u.invitation_status,
                    u.invitation_token,
                    u.invitation_sent_at,
                    u.invitation_expires_at,
                    u.invited_by,
                    u.registration_completed_at,
                    u.invitation_attempts,
                    u.last_reminder_sent_at,
                    u.archived_at,
                    u.role_code,
                    u.role_name,
                    u.role_category,
                    u.role_description
                FROM fw_prj_team_members tm
                JOIN fw_v_users u ON tm.user_id = u.id
                WHERE tm.project_id = ? 
                  AND u.archived_at IS NULL 
                  AND u.role_name NOT IN ('admin', 'project_manager')
                ORDER BY tm.assigned_at DESC
                LIMIT " . (int)$limit . " OFFSET " . (int)$offset . "
            ";

            $result = $connection->executeQuery($sql, [$projectId]);
            $teamMembers = $result->fetchAllAssociative();

            $formattedMembers = array_map([$this, 'formatTeamMember'], $teamMembers);

            $lastPage = ceil($total / $limit);

            return [
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Team members retrieved successfully',
                'data' => [
                    'team_members' => $formattedMembers,
                    'pagination' => [
                        'current_page' => $page,
                        'per_page' => $limit,
                        'total' => (int) $total,
                        'last_page' => $lastPage
                    ]
                ]
            ];

        } catch (Exception $e) {
            $this->logger->error('Error getting team members', [
                'project_id' => $projectId,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Failed to get team members');
        }
    }

    /**
     * @OA\Get(
     *     path="/api/v1/projects/{project_id}/team/available-users",
     *     tags={"Project Team"},
     *     summary="Get available users for team",
     *     description="Get list of users that can be added to project team (excludes System Administrators and Project Managers)",
     *     @OA\Parameter(
     *         name="project_id",
     *         in="path",
     *         required=true,
     *         description="Project ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         required=false,
     *         description="Search term for user name or email",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Available users retrieved successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Available users retrieved successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(
     *                     property="users",
     *                     type="array",
     *                     @OA\Items(
     *                         @OA\Property(property="id", type="integer", example=45),
     *                         @OA\Property(property="name", type="string", example="John Smith"),
     *                         @OA\Property(property="email", type="string", example="architect1@example.com"),
     *                         @OA\Property(property="", type="string", example="Architect"),
     *                         @OA\Property(property="job_title", type="string", example="Senior Architect"),
     *                         @OA\Property(property="status", type="integer", example=1)
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Project not found"
     *     )
     * )
     */
    public function getAvailableUsers($projectId)
    {
        try {

            if (!$this->checkProjectExists($projectId)) {
                return $this->errorResponse('Project not found', 404);
            }

            $search = $_GET['search'] ?? '';
            $connection = $this->database->getConnection();

            // Get users that can be added to team (exclude System Administrator and Project Manager)
            $sql = "
                SELECT 
                    -- All fields from fw_v_users
                    u.id,
                    u.email,
                    u.password_hash,
                    u.first_name,
                    u.last_name,
                    u.phone,
                    u.role_id,
                    u.job_title,
                    u.status,
                    u.status_reason,
                    u.status_details,
                    u.additional_info,
                    u.full_img_url,
                    u.avatar_url,
                    u.two_factor_enabled,
                    u.two_factor_secret,
                    u.last_login,
                    u.status_changed_at,
                    u.status_end_at,
                    u.dob,
                    u.gender,
                    u.nationality,
                    u.country_of_origin,
                    u.workforce_group,
                    u.city,
                    u.emergency,
                    u.created_at,
                    u.updated_at,
                    u.invitation_status,
                    u.invitation_token,
                    u.invitation_sent_at,
                    u.invitation_expires_at,
                    u.invited_by,
                    u.registration_completed_at,
                    u.invitation_attempts,
                    u.last_reminder_sent_at,
                    u.archived_at,
                    u.role_code,
                    u.role_name,
                    u.role_category,
                    u.role_description
                FROM fw_v_users u
                WHERE u.archived_at IS NULL 
                  AND u.role_code NOT IN ('admin', 'project_manager')
                  AND u.id NOT IN (
                      SELECT tm.user_id 
                      FROM fw_prj_team_members tm 
                      WHERE tm.project_id = ?
                  )
            ";

            $params = [$projectId];

            if (!empty($search)) {
                $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
                $searchTerm = '%' . $search . '%';
                $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
            }

            $sql .= " ORDER BY u.first_name, u.last_name";

            $result = $connection->executeQuery($sql, $params);
            $users = $result->fetchAllAssociative();

            $formattedUsers = array_map([$this, 'formatAvailableUser'], $users);

            return [
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Available users retrieved successfully',
                'data' => [
                    'users' => $formattedUsers
                ]
            ];

        } catch (Exception $e) {
            $this->logger->error('Error getting available users', [
                'project_id' => $projectId,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Failed to get available users');
        }
    }

    /**
     * @OA\Post(
     *     path="/api/v1/projects/{project_id}/team",
     *     tags={"Project Team"},
     *     summary="Add team member to project",
     *     description="Add a new team member to the project",
     *     @OA\Parameter(
     *         name="project_id",
     *         in="path",
     *         required=true,
     *         description="Project ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="user_id", type="integer", example=47),
     *             @OA\Property(property="role", type="string", example="member")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Team member added successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Team member added successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="project_id", type="integer", example=9),
     *                 @OA\Property(property="user_id", type="integer", example=47),
     *                 @OA\Property(property="role", type="string", example="member"),
     *                 @OA\Property(property="added_at", type="string", format="date-time", example="2025-01-15T10:00:00Z")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input data"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Project or user not found"
     *     ),
     *     @OA\Response(
     *         response=409,
     *         description="User already in team"
     *     )
     * )
     */
    public function addTeamMember($projectId)
    {
        try {

            if (!$this->checkProjectExists($projectId)) {
                return $this->errorResponse('Project not found', 404);
            }

            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$this->validateTeamMemberData($input)) {
                return $this->errorResponse('Invalid input data', 400);
            }

            $connection = $this->database->getConnection();

            // Check if user exists, is not archived, and is not System Administrator or Project Manager
            $userSql = "SELECT id, email, first_name, last_name, role_code, role_name, status, status_changed_at, status_end_at, dob, gender, nationality, country_of_origin, workforce_group, city, emergency FROM fw_v_users WHERE id = ? AND archived_at IS NULL";
            $userResult = $connection->executeQuery($userSql, [$input['user_id']]);
            $user = $userResult->fetchAssociative();
            
            if (!$user) {
                return $this->errorResponse('User not found', 404);
            }
            
            // Log user role for debugging
            $this->logger->info('Checking user role for team addition', [
                'user_id' => $input['user_id'],
                'role_code' => $user['role_code'] ?? 'null',
                'role_name' => $user['role_name'] ?? 'null'
            ]);
            
            // Check by role_code (more reliable than role_name)
            // Also check role_name as fallback in case role_code is null or empty
            $userRoleCode = $user['role_code'] ?? null;
            $userRoleName = $user['role_name'] ?? null;
            
            $isAdmin = ($userRoleCode === 'admin' || $userRoleCode === 'project_manager' || 
                       $userRoleName === 'System Administrator' || $userRoleName === 'Project Manager');
            
            if ($isAdmin) {
                $this->logger->warning('Attempt to add admin/manager to team blocked', [
                    'user_id' => $input['user_id'],
                    'role_code' => $userRoleCode,
                    'role_name' => $userRoleName
                ]);
                return $this->errorResponse('Cannot add System Administrator or Project Manager to team', 400);
            }

            // Check if user is already in team
            $existingSql = "SELECT id FROM fw_prj_team_members WHERE project_id = ? AND user_id = ?";
            $existingResult = $connection->executeQuery($existingSql, [$projectId, $input['user_id']]);
            if ($existingResult->fetchOne()) {
                return $this->errorResponse('User already in team', 409);
            }

            // Add team member
            $insertSql = "INSERT INTO fw_prj_team_members (project_id, user_id, role_in_project) VALUES (?, ?, ?)";
            $connection->executeStatement($insertSql, [$projectId, $input['user_id'], $input['role']]);

            $teamMemberId = $connection->lastInsertId();

            return [
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Team member added successfully',
                'data' => [
                    'id' => (int) $teamMemberId,
                    'project_id' => (int) $projectId,
                    'user_id' => (int) $input['user_id'],
                    'role' => $input['role'],
                    'added_at' => date('c')
                ]
            ];

        } catch (Exception $e) {
            $this->logger->error('Error adding team member', [
                'project_id' => $projectId,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Failed to add team member');
        }
    }

    /**
     * @OA\Put(
     *     path="/api/v1/projects/{project_id}/team/{team_member_id}",
     *     tags={"Project Team"},
     *     summary="Update team member role",
     *     description="Update the role of a team member in the project",
     *     @OA\Parameter(
     *         name="project_id",
     *         in="path",
     *         required=true,
     *         description="Project ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="team_member_id",
     *         in="path",
     *         required=true,
     *         description="Team member ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="role", type="string", example="lead")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Team member updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Team member updated successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="project_id", type="integer", example=9),
     *                 @OA\Property(property="user_id", type="integer", example=47),
     *                 @OA\Property(property="role", type="string", example="lead"),
     *                 @OA\Property(property="added_at", type="string", format="date-time", example="2025-01-15T10:00:00Z")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input data"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Team member not found"
     *     )
     * )
     */
    public function updateTeamMember($projectId, $teamMemberId)
    {
        try {

            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($input['role']) || empty($input['role'])) {
                return $this->errorResponse('Role is required', 400);
            }

            $connection = $this->database->getConnection();

            // Check if team member exists
            $checkSql = "SELECT id, user_id, role_in_project, assigned_at FROM fw_prj_team_members WHERE id = ? AND project_id = ?";
            $checkResult = $connection->executeQuery($checkSql, [$teamMemberId, $projectId]);
            $teamMember = $checkResult->fetchAssociative();

            if (!$teamMember) {
                return $this->errorResponse('Team member not found', 404);
            }

            // Update role
            $updateSql = "UPDATE fw_prj_team_members SET role_in_project = ? WHERE id = ?";
            $connection->executeStatement($updateSql, [$input['role'], $teamMemberId]);

            return [
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Team member updated successfully',
                'data' => [
                    'id' => (int) $teamMemberId,
                    'project_id' => (int) $projectId,
                    'user_id' => (int) $teamMember['user_id'],
                    'role' => $input['role'],
                    'added_at' => $teamMember['assigned_at']
                ]
            ];

        } catch (Exception $e) {
            $this->logger->error('Error updating team member', [
                'project_id' => $projectId,
                'team_member_id' => $teamMemberId,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Failed to update team member');
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/v1/projects/{project_id}/team/{team_member_id}",
     *     tags={"Project Team"},
     *     summary="Remove team member from project",
     *     description="Remove a team member from the project",
     *     @OA\Parameter(
     *         name="project_id",
     *         in="path",
     *         required=true,
     *         description="Project ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="team_member_id",
     *         in="path",
     *         required=true,
     *         description="Team member ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Team member removed successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="error_code", type="integer", example=0),
     *             @OA\Property(property="status", type="string", example="success"),
     *             @OA\Property(property="message", type="string", example="Team member removed successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthorized"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Team member not found"
     *     )
     * )
     */
    public function removeTeamMember($projectId, $teamMemberId)
    {
        try {

            $connection = $this->database->getConnection();

            // Check if team member exists
            $checkSql = "SELECT id FROM fw_prj_team_members WHERE id = ? AND project_id = ?";
            $checkResult = $connection->executeQuery($checkSql, [$teamMemberId, $projectId]);
            
            if (!$checkResult->fetchOne()) {
                return $this->errorResponse('Team member not found', 404);
            }

            // Remove team member
            $deleteSql = "DELETE FROM fw_prj_team_members WHERE id = ?";
            $connection->executeStatement($deleteSql, [$teamMemberId]);

            return [
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Team member removed successfully',
                'data' => null
            ];

        } catch (Exception $e) {
            $this->logger->error('Error removing team member', [
                'project_id' => $projectId,
                'team_member_id' => $teamMemberId,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Failed to remove team member');
        }
    }

    private function checkAuth(): bool
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

        if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return false;
        }

        $token = $matches[1];
        
        try {
            // Правильная JWT проверка
            $parts = explode('.', $token);
            
            if (count($parts) !== 3) {
                return false;
            }

            [$base64Header, $base64Payload, $base64Signature] = $parts;

            // Декодируем payload
            $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $base64Payload)), true);

            if (!$payload || !isset($payload['user_id']) || !isset($payload['exp'])) {
                return false;
            }

            // Проверяем подпись
            $secret = $_ENV['JWT_SECRET'] ?? 'your-secret-key-change-in-production';
            $expectedSignature = hash_hmac('sha256', $base64Header . '.' . $base64Payload, $secret, true);
            $expectedBase64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($expectedSignature));

            if (!hash_equals($expectedBase64Signature, $base64Signature)) {
                return false;
            }

            // Проверяем срок действия
            if ($payload['exp'] < time()) {
                return false;
            }

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    private function checkProjectExists($projectId): bool
    {
        try {
            $connection = $this->database->getConnection();
            $sql = "SELECT id FROM fw_projects WHERE id = ?";
            $result = $connection->executeQuery($sql, [$projectId]);
            return (bool) $result->fetchOne();
        } catch (Exception $e) {
            return false;
        }
    }

    private function validateTeamMemberData($data): bool
    {
        return isset($data['user_id']) && 
               is_numeric($data['user_id']) && 
               isset($data['role']) && 
               !empty($data['role']);
    }

    private function formatTeamMember($member): array
    {
        return [
            // Team member specific fields
            'team_member_id' => (int) $member['team_member_id'],
            'project_id' => (int) $member['project_id'],
            'project_role' => $member['role'],
            'added_at' => $member['added_at'],
            
            // User basic info
            'id' => (int) $member['id'],
            'email' => $member['email'],
            'first_name' => $member['first_name'],
            'last_name' => $member['last_name'],
            'full_name' => trim($member['first_name'] . ' ' . $member['last_name']),
            'phone' => $member['phone'],
            'job_title' => $member['job_title'],
            'status' => (int) $member['status'],
            'status_reason' => $member['status_reason'],
            'status_details' => $member['status_details'],
            'additional_info' => $member['additional_info'],
            
            // Images
            'full_img_url' => $member['full_img_url'],
            'avatar_url' => $member['avatar_url'],
            
            // Personal info
            'dob' => $member['dob'],
            'gender' => $member['gender'],
            'nationality' => $member['nationality'],
            'country_of_origin' => $member['country_of_origin'],
            'workforce_group' => $member['workforce_group'],
            'city' => $member['city'],
            'emergency' => $member['emergency'],
            
            // Timestamps
            'created_at' => $member['created_at'],
            'updated_at' => $member['updated_at'],
            
            // Invitation info
            'invitation_status' => $member['invitation_status'],
            'invitation_sent_at' => $member['invitation_sent_at'],
            'invitation_expires_at' => $member['invitation_expires_at'],
            'invited_by' => $member['invited_by'] ? (int) $member['invited_by'] : null,
            'registration_completed_at' => $member['registration_completed_at'],
            'invitation_attempts' => (int) $member['invitation_attempts'],
            'last_reminder_sent_at' => $member['last_reminder_sent_at'],
            
            // Role info
            'role_id' => (int) $member['role_id'],
            'role_code' => $member['role_code'],
            'role_name' => $member['role_name'],
            'role_category' => $member['role_category'],
            'role_description' => $member['role_description']
        ];
    }

    private function formatAvailableUser($user): array
    {
        return [
            // User basic info
            'id' => (int) $user['id'],
            'email' => $user['email'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'name' => trim($user['first_name'] . ' ' . $user['last_name']),
            'phone' => $user['phone'],
            'job_title' => $user['job_title'],
            'status' => (int) $user['status'],
            'status_reason' => $user['status_reason'],
            'status_details' => $user['status_details'],
            'additional_info' => $user['additional_info'],
            
            // Images
            'full_img_url' => $user['full_img_url'],
            'avatar_url' => $user['avatar_url'],
            
            // 2FA
            'two_factor_enabled' => (bool) $user['two_factor_enabled'],
            
            // Login info
            'last_login' => $user['last_login'],
            'status_changed_at' => $user['status_changed_at'],
            'status_end_at' => $user['status_end_at'],
            
            // Personal info
            'dob' => $user['dob'],
            'gender' => $user['gender'],
            'nationality' => $user['nationality'],
            'country_of_origin' => $user['country_of_origin'],
            'workforce_group' => $user['workforce_group'],
            'city' => $user['city'],
            'emergency' => $user['emergency'],
            
            // Timestamps
            'created_at' => $user['created_at'],
            'updated_at' => $user['updated_at'],
            
            // Invitation info
            'invitation_status' => $user['invitation_status'],
            'invitation_sent_at' => $user['invitation_sent_at'],
            'invitation_expires_at' => $user['invitation_expires_at'],
            'invited_by' => $user['invited_by'] ? (int) $user['invited_by'] : null,
            'registration_completed_at' => $user['registration_completed_at'],
            'invitation_attempts' => (int) $user['invitation_attempts'],
            'last_reminder_sent_at' => $user['last_reminder_sent_at'],
            
            // Role info
            'role_id' => (int) $user['role_id'],
            'role_code' => $user['role_code'],
            'role_name' => $user['role_name'],
            'role_category' => $user['role_category'],
            'role_description' => $user['role_description']
        ];
    }

    private function errorResponse(string $message, int $code = 500): array
    {
        http_response_code($code);
        return [
            'error_code' => $code,
            'status' => 'error',
            'message' => $message,
            'data' => null
        ];
    }
}
