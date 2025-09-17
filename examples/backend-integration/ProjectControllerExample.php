<?php

namespace App\Examples;

use App\Controllers\ProjectController;
use App\Services\EventLoggingService;
use Monolog\Logger;

/**
 * Пример интеграции системы логирования в ProjectController
 * Показывает, как логировать события на уровне проектов
 */
class ProjectControllerExample extends ProjectController
{
    private EventLoggingService $eventLoggingService;

    public function __construct(Logger $logger)
    {
        parent::__construct($logger);
        $this->eventLoggingService = new EventLoggingService($logger);
    }

    /**
     * Пример: Создание проекта
     */
    public function createProject(array $projectData): array
    {
        try {
            // 1. Создаем проект в базе данных
            $projectId = $this->insertProjectToDatabase($projectData);

            // 2. Логируем создание проекта
            $eventLogId = $this->eventLoggingService->logEvent(
                entityType: 'project',
                entityId: $projectId,
                eventType: 'PROJECT_CREATED',
                beforeData: [],
                afterData: $projectData,
                changedFields: array_keys($projectData),
                options: [
                    'comment' => "New project created: {$projectData['name']}",
                    'actor_type' => 'user',
                    'actor_id' => $this->getCurrentUserId(),
                    'entity_version' => 1
                ]
            );

            return [
                'success' => true,
                'project_id' => $projectId,
                'event_log_id' => $eventLogId
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to create project', [
                'project_data' => $projectData,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Пример: Изменение статуса проекта
     */
    public function updateProjectStatus(int $projectId, string $newStatus): array
    {
        try {
            // 1. Получаем текущие данные проекта
            $currentProject = $this->getProjectById($projectId);
            if (!$currentProject) {
                throw new \Exception("Project not found");
            }

            $oldStatus = $currentProject['status'];

            // 2. Обновляем статус
            $this->updateProjectInDatabase($projectId, ['status' => $newStatus]);

            // 3. Логируем изменение статуса
            $eventLogId = $this->eventLoggingService->logEvent(
                entityType: 'project',
                entityId: $projectId,
                eventType: 'PROJECT_STATUS_CHANGED',
                beforeData: ['status' => $oldStatus],
                afterData: ['status' => $newStatus],
                changedFields: ['status', 'updated_at'],
                options: [
                    'comment' => "Project status changed from {$oldStatus} to {$newStatus}",
                    'actor_type' => 'user',
                    'actor_id' => $this->getCurrentUserId(),
                    'entity_version' => $currentProject['version'] ?? 1
                ]
            );

            // 4. Дополнительная логика при изменении статуса
            if ($newStatus === 'completed') {
                $this->handleProjectCompletion($projectId);
            } elseif ($newStatus === 'cancelled') {
                $this->handleProjectCancellation($projectId);
            }

            return [
                'success' => true,
                'project_id' => $projectId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'event_log_id' => $eventLogId
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to update project status', [
                'project_id' => $projectId,
                'new_status' => $newStatus,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Пример: Добавление участника в проект
     */
    public function addProjectMember(int $projectId, int $userId, string $role): array
    {
        try {
            // 1. Получаем текущих участников
            $currentMembers = $this->getProjectMembers($projectId);

            // 2. Добавляем участника
            $this->addMemberToProject($projectId, $userId, $role);

            // 3. Получаем обновленный список участников
            $newMembers = $this->getProjectMembers($projectId);

            // 4. Логируем добавление участника
            $eventLogId = $this->eventLoggingService->logEvent(
                entityType: 'project',
                entityId: $projectId,
                eventType: 'PROJECT_MEMBER_ADDED',
                beforeData: ['members' => $currentMembers],
                afterData: ['members' => $newMembers],
                changedFields: ['members', 'updated_at'],
                options: [
                    'comment' => "User {$userId} added to project with role {$role}",
                    'actor_type' => 'user',
                    'actor_id' => $this->getCurrentUserId()
                ]
            );

            return [
                'success' => true,
                'project_id' => $projectId,
                'user_id' => $userId,
                'role' => $role,
                'event_log_id' => $eventLogId
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to add project member', [
                'project_id' => $projectId,
                'user_id' => $userId,
                'role' => $role,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Пример: Изменение бюджета проекта с условиями
     */
    public function updateProjectBudget(int $projectId, float $newBudget): array
    {
        try {
            // 1. Получаем текущий бюджет
            $currentProject = $this->getProjectById($projectId);
            $oldBudget = $currentProject['budget'];

            // 2. Проверяем, нужно ли уведомлять (изменение более чем на 10%)
            $budgetChangePercent = abs(($newBudget - $oldBudget) / $oldBudget * 100);
            $shouldNotify = $budgetChangePercent > 10;

            // 3. Обновляем бюджет
            $this->updateProjectInDatabase($projectId, ['budget' => $newBudget]);

            // 4. Логируем только значительные изменения
            $eventLogId = null;
            if ($shouldNotify) {
                $eventLogId = $this->eventLoggingService->logEvent(
                    entityType: 'project',
                    entityId: $projectId,
                    eventType: 'PROJECT_BUDGET_CHANGED',
                    beforeData: ['budget' => $oldBudget],
                    afterData: ['budget' => $newBudget],
                    changedFields: ['budget', 'updated_at'],
                    options: [
                        'comment' => "Significant budget change: {$budgetChangePercent}% (from {$oldBudget} to {$newBudget})",
                        'actor_type' => 'user',
                        'actor_id' => $this->getCurrentUserId(),
                        'severity' => 'critical'
                    ]
                );
            }

            return [
                'success' => true,
                'project_id' => $projectId,
                'old_budget' => $oldBudget,
                'new_budget' => $newBudget,
                'change_percent' => $budgetChangePercent,
                'should_notify' => $shouldNotify,
                'event_log_id' => $eventLogId
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to update project budget', [
                'project_id' => $projectId,
                'new_budget' => $newBudget,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Пример: Публикация проекта
     */
    public function publishProject(int $projectId): array
    {
        try {
            // 1. Получаем данные проекта
            $project = $this->getProjectById($projectId);
            if (!$project) {
                throw new \Exception("Project not found");
            }

            // 2. Проверяем, готов ли проект к публикации
            if (!$this->isProjectReadyForPublishing($projectId)) {
                throw new \Exception("Project is not ready for publishing");
            }

            // 3. Публикуем проект
            $this->updateProjectInDatabase($projectId, [
                'status' => 'published',
                'published_at' => date('Y-m-d H:i:s')
            ]);

            // 4. Логируем публикацию (критическое событие)
            $eventLogId = $this->eventLoggingService->logEvent(
                entityType: 'project',
                entityId: $projectId,
                eventType: 'PROJECT_PUBLISHED',
                beforeData: ['status' => $project['status']],
                afterData: ['status' => 'published', 'published_at' => date('Y-m-d H:i:s')],
                changedFields: ['status', 'published_at', 'updated_at'],
                options: [
                    'comment' => "Project published: {$project['name']}",
                    'actor_type' => 'user',
                    'actor_id' => $this->getCurrentUserId(),
                    'severity' => 'critical'
                ]
            );

            // 5. Дополнительные действия при публикации
            $this->notifyStakeholders($projectId);
            $this->createProjectMilestones($projectId);

            return [
                'success' => true,
                'project_id' => $projectId,
                'published_at' => date('Y-m-d H:i:s'),
                'event_log_id' => $eventLogId
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to publish project', [
                'project_id' => $projectId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    // Вспомогательные методы (заглушки для примера)
    private function getProjectById(int $projectId): ?array
    {
        return [
            'id' => $projectId,
            'name' => 'Example Project',
            'status' => 'draft',
            'budget' => 100000,
            'version' => 1
        ];
    }

    private function updateProjectInDatabase(int $projectId, array $data): void
    {
        // Реальная реализация обновления в БД
    }

    private function insertProjectToDatabase(array $data): int
    {
        // Реальная реализация создания в БД
        return 456;
    }

    private function getCurrentUserId(): int
    {
        return 789;
    }

    private function getProjectMembers(int $projectId): array
    {
        return [
            ['user_id' => 1, 'role' => 'manager'],
            ['user_id' => 2, 'role' => 'developer']
        ];
    }

    private function addMemberToProject(int $projectId, int $userId, string $role): void
    {
        // Реальная реализация добавления участника
    }

    private function isProjectReadyForPublishing(int $projectId): bool
    {
        // Проверка готовности проекта к публикации
        return true;
    }

    private function handleProjectCompletion(int $projectId): void
    {
        $this->logger->info('Project completed', ['project_id' => $projectId]);
    }

    private function handleProjectCancellation(int $projectId): void
    {
        $this->logger->info('Project cancelled', ['project_id' => $projectId]);
    }

    private function notifyStakeholders(int $projectId): void
    {
        $this->logger->info('Notifying stakeholders', ['project_id' => $projectId]);
    }

    private function createProjectMilestones(int $projectId): void
    {
        $this->logger->info('Creating project milestones', ['project_id' => $projectId]);
    }
}
