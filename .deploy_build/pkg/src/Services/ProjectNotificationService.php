<?php

namespace App\Services;

use App\Database\Database;
use Doctrine\DBAL\Exception;
use Monolog\Logger;

/**
 * Сервис для обработки уведомлений проектов
 */
class ProjectNotificationService
{
    private Database $database;
    private Logger $logger;
    private EmailService $emailService;
    private EventLoggingService $eventLoggingService;

    public function __construct(Database $database, Logger $logger)
    {
        $this->database = $database;
        $this->logger = $logger;
        $this->emailService = new EmailService($logger);
        $this->eventLoggingService = new EventLoggingService($logger);
    }

    /**
     * Обрабатывает pending события из outbox
     */
    public function processPendingEvents(int $limit = 100): void
    {
        try {
            $pendingEvents = $this->eventLoggingService->getPendingOutboxEvents($limit);
            
            foreach ($pendingEvents as $event) {
                $this->processEvent($event);
            }
            
            $this->logger->info('Processed pending events', [
                'count' => count($pendingEvents)
            ]);
            
        } catch (Exception $e) {
            $this->logger->error('Failed to process pending events', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Обрабатывает одно событие
     */
    private function processEvent(array $event): void
    {
        try {
            $payload = $event['payload'];
            $action = $payload['action'];
            
            switch ($action) {
                case 'notify_project_manager':
                    $this->notifyProjectManager($payload);
                    break;
                case 'notify_admin':
                    $this->notifyAdmin($payload);
                    break;
                case 'send_email_notification':
                    $this->sendEmailNotification($payload);
                    break;
                case 'generate_daily_report':
                    $this->generateDailyReport($payload);
                    break;
                case 'send_email_report':
                    $this->sendEmailReport($payload);
                    break;
                default:
                    $this->logger->warning('Unknown action', ['action' => $action]);
            }
            
            // Обновляем статус события
            $this->eventLoggingService->updateOutboxEventStatus($event['id'], 'sent');
            
        } catch (Exception $e) {
            $this->logger->error('Failed to process event', [
                'event_id' => $event['id'],
                'error' => $e->getMessage()
            ]);
            
            // Обновляем статус на ошибку
            $this->eventLoggingService->updateOutboxEventStatus(
                $event['id'], 
                'error', 
                $e->getMessage()
            );
        }
    }

    /**
     * Уведомляет менеджера проекта о создании проекта
     */
    private function notifyProjectManager(array $payload): void
    {
        try {
            $projectData = $payload['after_data'];
            $managerId = $projectData['prj_manager'];
            
            if (!$managerId) {
                $this->logger->warning('No project manager assigned', [
                    'project_id' => $projectData['id']
                ]);
                return;
            }

            // Получаем информацию о менеджере
            $manager = $this->getUserById($managerId);
            if (!$manager) {
                $this->logger->error('Project manager not found', [
                    'manager_id' => $managerId
                ]);
                return;
            }

            // Получаем информацию о создателе
            $creator = $this->getUserById($payload['actor_id']);
            
            $subject = "Новый проект назначен: {$projectData['prj_name']}";
            $message = $this->buildProjectManagerNotificationMessage($projectData, $creator);
            
            // Отправляем email
            $this->emailService->sendEmail(
                $manager['email'],
                $manager['first_name'] . ' ' . $manager['last_name'],
                $subject,
                $message
            );

            $this->logger->info('Project manager notified', [
                'project_id' => $projectData['id'],
                'manager_id' => $managerId,
                'manager_email' => $manager['email']
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to notify project manager', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);
            throw $e;
        }
    }

    /**
     * Уведомляет админа о создании проекта менеджером
     */
    private function notifyAdmin(array $payload): void
    {
        try {
            $projectData = $payload['after_data'];
            $creatorId = $payload['actor_id'];
            
            // Получаем информацию о создателе
            $creator = $this->getUserById($creatorId);
            if (!$creator) {
                $this->logger->error('Project creator not found', [
                    'creator_id' => $creatorId
                ]);
                return;
            }

            // Проверяем, является ли создатель админом
            if ($this->isUserAdmin($creatorId)) {
                $this->logger->info('Project created by admin, skipping admin notification', [
                    'project_id' => $projectData['id'],
                    'creator_id' => $creatorId
                ]);
                return;
            }

            // Получаем всех админов
            $admins = $this->getAdminUsers();
            
            foreach ($admins as $admin) {
                $subject = "Новый проект создан: {$projectData['prj_name']}";
                $message = $this->buildAdminNotificationMessage($projectData, $creator);
                
                // Отправляем email
                $this->emailService->sendEmail(
                    $admin['email'],
                    $admin['first_name'] . ' ' . $admin['last_name'],
                    $subject,
                    $message
                );
            }

            $this->logger->info('Admins notified about project creation', [
                'project_id' => $projectData['id'],
                'creator_id' => $creatorId,
                'admins_count' => count($admins)
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to notify admins', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);
            throw $e;
        }
    }

    /**
     * Отправляет email уведомление
     */
    private function sendEmailNotification(array $payload): void
    {
        // Этот метод может быть использован для дополнительных email уведомлений
        $this->logger->info('Email notification sent', [
            'event_type' => $payload['event_type'],
            'entity_id' => $payload['entity_id']
        ]);
    }

    /**
     * Генерирует ежедневный отчет
     */
    public function generateDailyReport(array $payload): void
    {
        try {
            $reportDate = date('Y-m-d');
            
            // Получаем статистику проектов за день
            $stats = $this->getDailyProjectStats($reportDate);
            
            // Сохраняем отчет в базу данных
            $this->saveDailyReport($reportDate, $stats);
            
            $this->logger->info('Daily report generated', [
                'report_date' => $reportDate,
                'stats' => $stats
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to generate daily report', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);
            throw $e;
        }
    }

    /**
     * Отправляет ежедневный отчет по email
     */
    private function sendEmailReport(array $payload): void
    {
        try {
            $reportDate = date('Y-m-d');
            $report = $this->getDailyReport($reportDate);
            
            if (!$report) {
                $this->logger->warning('No daily report found', [
                    'report_date' => $reportDate
                ]);
                return;
            }

            // Получаем получателей отчета
            $recipients = $this->getReportRecipients();
            
            foreach ($recipients as $recipient) {
                $subject = "Ежедневный отчет по проектам - {$reportDate}";
                $message = $this->buildDailyReportMessage($report);
                
                $this->emailService->sendEmail(
                    $recipient['email'],
                    $recipient['first_name'] . ' ' . $recipient['last_name'],
                    $subject,
                    $message
                );
            }

            // Обновляем статус отчета
            $this->updateReportStatus($report['id'], 'sent');

            $this->logger->info('Daily report sent', [
                'report_date' => $reportDate,
                'recipients_count' => count($recipients)
            ]);

        } catch (Exception $e) {
            $this->logger->error('Failed to send daily report', [
                'error' => $e->getMessage(),
                'payload' => $payload
            ]);
            throw $e;
        }
    }

    /**
     * Получает пользователя по ID
     */
    private function getUserById(int $userId): ?array
    {
        try {
            $result = $this->database->getConnection()->executeQuery(
                "SELECT id, email, first_name, last_name, role_id FROM fw_users WHERE id = ?",
                [$userId]
            );
            
            return $result->fetchAssociative() ?: null;
        } catch (Exception $e) {
            $this->logger->error('Failed to get user by ID', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Проверяет, является ли пользователь админом
     */
    private function isUserAdmin(int $userId): bool
    {
        try {
            $result = $this->database->getConnection()->executeQuery(
                "SELECT r.code FROM fw_users u 
                 LEFT JOIN fw_glob_roles r ON u.role_id = r.id 
                 WHERE u.id = ? AND r.code = 'admin'",
                [$userId]
            );
            
            return $result->fetchOne() !== false;
        } catch (Exception $e) {
            $this->logger->error('Failed to check if user is admin', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Получает всех админов
     */
    private function getAdminUsers(): array
    {
        try {
            $result = $this->database->getConnection()->executeQuery(
                "SELECT u.id, u.email, u.first_name, u.last_name 
                 FROM fw_users u 
                 LEFT JOIN fw_glob_roles r ON u.role_id = r.id 
                 WHERE r.code = 'admin' AND u.status = 'active'"
            );
            
            return $result->fetchAllAssociative();
        } catch (Exception $e) {
            $this->logger->error('Failed to get admin users', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Получает статистику проектов за день
     */
    private function getDailyProjectStats(string $date): array
    {
        try {
            $connection = $this->database->getConnection();
            
            // Общее количество созданных проектов
            $totalCreated = $connection->executeQuery(
                "SELECT COUNT(*) FROM fw_projects WHERE DATE(created_at) = ?",
                [$date]
            )->fetchOne();

            // Проекты по приоритету
            $byPriority = $connection->executeQuery(
                "SELECT priority, COUNT(*) as count 
                 FROM fw_projects 
                 WHERE DATE(created_at) = ? 
                 GROUP BY priority",
                [$date]
            )->fetchAllAssociative();

            // Проекты по статусу
            $byStatus = $connection->executeQuery(
                "SELECT status, COUNT(*) as count 
                 FROM fw_projects 
                 WHERE DATE(created_at) = ? 
                 GROUP BY status",
                [$date]
            )->fetchAllAssociative();

            // Проекты по менеджерам
            $byManager = $connection->executeQuery(
                "SELECT prj_manager, COUNT(*) as count 
                 FROM fw_projects 
                 WHERE DATE(created_at) = ? AND prj_manager IS NOT NULL
                 GROUP BY prj_manager",
                [$date]
            )->fetchAllAssociative();

            return [
                'total_created' => (int)$totalCreated,
                'by_priority' => $byPriority,
                'by_status' => $byStatus,
                'by_manager' => $byManager,
                'date' => $date
            ];
        } catch (Exception $e) {
            $this->logger->error('Failed to get daily project stats', [
                'date' => $date,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Сохраняет ежедневный отчет
     */
    private function saveDailyReport(string $date, array $stats): void
    {
        try {
            $connection = $this->database->getConnection();
            
            $connection->executeStatement(
                "INSERT INTO fw_daily_project_reports 
                 (report_date, total_projects_created, projects_by_priority, projects_by_status, 
                  projects_by_manager, projects_by_creator, report_generated_at, status) 
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), 'generated')
                 ON DUPLICATE KEY UPDATE 
                 total_projects_created = VALUES(total_projects_created),
                 projects_by_priority = VALUES(projects_by_priority),
                 projects_by_status = VALUES(projects_by_status),
                 projects_by_manager = VALUES(projects_by_manager),
                 projects_by_creator = VALUES(projects_by_creator),
                 report_generated_at = NOW()",
                [
                    $date,
                    $stats['total_created'],
                    json_encode($stats['by_priority']),
                    json_encode($stats['by_status']),
                    json_encode($stats['by_manager']),
                    json_encode($stats['by_creator'] ?? [])
                ]
            );
        } catch (Exception $e) {
            $this->logger->error('Failed to save daily report', [
                'date' => $date,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Получает ежедневный отчет
     */
    private function getDailyReport(string $date): ?array
    {
        try {
            $result = $this->database->getConnection()->executeQuery(
                "SELECT * FROM fw_daily_project_reports WHERE report_date = ?",
                [$date]
            );
            
            return $result->fetchAssociative() ?: null;
        } catch (Exception $e) {
            $this->logger->error('Failed to get daily report', [
                'date' => $date,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Получает получателей отчетов
     */
    private function getReportRecipients(): array
    {
        // Возвращаем всех админов и менеджеров проектов
        try {
            $result = $this->database->getConnection()->executeQuery(
                "SELECT u.id, u.email, u.first_name, u.last_name, r.code as role_code
                 FROM fw_users u 
                 LEFT JOIN fw_glob_roles r ON u.role_id = r.id 
                 WHERE r.code IN ('admin', 'project_manager') AND u.status = 'active'"
            );
            
            return $result->fetchAllAssociative();
        } catch (Exception $e) {
            $this->logger->error('Failed to get report recipients', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Обновляет статус отчета
     */
    private function updateReportStatus(int $reportId, string $status): void
    {
        try {
            $this->database->getConnection()->executeStatement(
                "UPDATE fw_daily_project_reports SET status = ?, report_sent_at = NOW() WHERE id = ?",
                [$status, $reportId]
            );
        } catch (Exception $e) {
            $this->logger->error('Failed to update report status', [
                'report_id' => $reportId,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Строит сообщение для уведомления менеджера проекта
     */
    private function buildProjectManagerNotificationMessage(array $project, ?array $creator): string
    {
        $creatorName = $creator ? $creator['first_name'] . ' ' . $creator['last_name'] : 'Система';
        
        return "
        <h2>Новый проект назначен</h2>
        <p>Вам назначен новый проект:</p>
        <ul>
            <li><strong>Название:</strong> {$project['prj_name']}</li>
            <li><strong>Адрес:</strong> {$project['address']}</li>
            <li><strong>Дата начала:</strong> {$project['date_start']}</li>
            <li><strong>Дата окончания:</strong> {$project['date_end']}</li>
            <li><strong>Приоритет:</strong> {$project['priority']}</li>
            <li><strong>Статус:</strong> {$project['status']}</li>
            <li><strong>Создал:</strong> {$creatorName}</li>
        </ul>
        <p>Пожалуйста, войдите в систему для просмотра деталей проекта.</p>
        ";
    }

    /**
     * Строит сообщение для уведомления админа
     */
    private function buildAdminNotificationMessage(array $project, array $creator): string
    {
        $creatorName = $creator['first_name'] . ' ' . $creator['last_name'];
        
        return "
        <h2>Новый проект создан</h2>
        <p>Менеджер проекта создал новый проект:</p>
        <ul>
            <li><strong>Название:</strong> {$project['prj_name']}</li>
            <li><strong>Адрес:</strong> {$project['address']}</li>
            <li><strong>Дата начала:</strong> {$project['date_start']}</li>
            <li><strong>Дата окончания:</strong> {$project['date_end']}</li>
            <li><strong>Приоритет:</strong> {$project['priority']}</li>
            <li><strong>Статус:</strong> {$project['status']}</li>
            <li><strong>Создал:</strong> {$creatorName}</li>
        </ul>
        <p>Пожалуйста, проверьте проект в системе.</p>
        ";
    }

    /**
     * Строит сообщение ежедневного отчета
     */
    private function buildDailyReportMessage(array $report): string
    {
        $stats = [
            'total_created' => $report['total_projects_created'],
            'by_priority' => json_decode($report['projects_by_priority'], true),
            'by_status' => json_decode($report['projects_by_status'], true),
            'by_manager' => json_decode($report['projects_by_manager'], true)
        ];

        $message = "<h2>Ежедневный отчет по проектам - {$report['report_date']}</h2>";
        $message .= "<p><strong>Всего создано проектов:</strong> {$stats['total_created']}</p>";
        
        if (!empty($stats['by_priority'])) {
            $message .= "<h3>По приоритету:</h3><ul>";
            foreach ($stats['by_priority'] as $priority) {
                $message .= "<li>{$priority['priority']}: {$priority['count']}</li>";
            }
            $message .= "</ul>";
        }
        
        if (!empty($stats['by_status'])) {
            $message .= "<h3>По статусу:</h3><ul>";
            foreach ($stats['by_status'] as $status) {
                $message .= "<li>{$status['status']}: {$status['count']}</li>";
            }
            $message .= "</ul>";
        }
        
        $message .= "<p>Подробную информацию можно найти в системе.</p>";
        
        return $message;
    }
}
