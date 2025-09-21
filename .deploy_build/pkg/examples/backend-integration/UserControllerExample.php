<?php

namespace App\Examples;

use App\Services\EventLoggingService;
use Monolog\Logger;

/**
 * Пример интеграции системы логирования в UserController
 * Показывает, как логировать события на уровне пользователей
 */
class UserControllerExample
{
    private Logger $logger;
    private EventLoggingService $eventLoggingService;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->eventLoggingService = new EventLoggingService($logger);
    }

    /**
     * Пример: Регистрация нового пользователя
     */
    public function registerUser(array $userData): array
    {
        try {
            // 1. Создаем пользователя в базе данных
            $userId = $this->insertUserToDatabase($userData);

            // 2. Логируем регистрацию
            $eventLogId = $this->eventLoggingService->logEvent(
                entityType: 'user',
                entityId: $userId,
                eventType: 'USER_REGISTERED',
                beforeData: [],
                afterData: $userData,
                changedFields: array_keys($userData),
                options: [
                    'comment' => "New user registered: {$userData['email']}",
                    'actor_type' => 'system',
                    'actor_id' => null,
                    'entity_version' => 1
                ]
            );

            // 3. Дополнительные действия при регистрации
            $this->sendWelcomeEmail($userId);
            $this->assignDefaultRole($userId);

            return [
                'success' => true,
                'user_id' => $userId,
                'event_log_id' => $eventLogId
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to register user', [
                'user_data' => $userData,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Пример: Изменение роли пользователя
     */
    public function updateUserRole(int $userId, string $newRole): array
    {
        try {
            // 1. Получаем текущие данные пользователя
            $currentUser = $this->getUserById($userId);
            if (!$currentUser) {
                throw new \Exception("User not found");
            }

            $oldRole = $currentUser['role'];

            // 2. Проверяем права на изменение роли
            if (!$this->canChangeUserRole($userId, $newRole)) {
                throw new \Exception("Insufficient permissions to change user role");
            }

            // 3. Обновляем роль
            $this->updateUserInDatabase($userId, ['role' => $newRole]);

            // 4. Логируем изменение роли (критическое событие)
            $eventLogId = $this->eventLoggingService->logEvent(
                entityType: 'user',
                entityId: $userId,
                eventType: 'USER_ROLE_CHANGED',
                beforeData: ['role' => $oldRole],
                afterData: ['role' => $newRole],
                changedFields: ['role', 'updated_at'],
                options: [
                    'comment' => "User role changed from {$oldRole} to {$newRole}",
                    'actor_type' => 'user',
                    'actor_id' => $this->getCurrentUserId(),
                    'severity' => 'critical'
                ]
            );

            // 5. Дополнительные действия при изменении роли
            if ($newRole === 'admin') {
                $this->grantAdminPermissions($userId);
            } elseif ($oldRole === 'admin' && $newRole !== 'admin') {
                $this->revokeAdminPermissions($userId);
            }

            return [
                'success' => true,
                'user_id' => $userId,
                'old_role' => $oldRole,
                'new_role' => $newRole,
                'event_log_id' => $eventLogId
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to update user role', [
                'user_id' => $userId,
                'new_role' => $newRole,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Пример: Активация/деактивация пользователя
     */
    public function toggleUserStatus(int $userId, bool $isActive): array
    {
        try {
            // 1. Получаем текущие данные пользователя
            $currentUser = $this->getUserById($userId);
            if (!$currentUser) {
                throw new \Exception("User not found");
            }

            $oldStatus = $currentUser['is_active'] ? 'active' : 'inactive';
            $newStatus = $isActive ? 'active' : 'inactive';

            // 2. Обновляем статус
            $this->updateUserInDatabase($userId, ['is_active' => $isActive]);

            // 3. Логируем изменение статуса
            $eventLogId = $this->eventLoggingService->logEvent(
                entityType: 'user',
                entityId: $userId,
                eventType: 'USER_STATUS_CHANGED',
                beforeData: ['is_active' => $currentUser['is_active']],
                afterData: ['is_active' => $isActive],
                changedFields: ['is_active', 'updated_at'],
                options: [
                    'comment' => "User status changed from {$oldStatus} to {$newStatus}",
                    'actor_type' => 'user',
                    'actor_id' => $this->getCurrentUserId(),
                    'severity' => $isActive ? 'important' : 'critical'
                ]
            );

            // 4. Дополнительные действия
            if (!$isActive) {
                $this->deactivateUserSessions($userId);
                $this->notifyUserDeactivation($userId);
            } else {
                $this->notifyUserActivation($userId);
            }

            return [
                'success' => true,
                'user_id' => $userId,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'event_log_id' => $eventLogId
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to toggle user status', [
                'user_id' => $userId,
                'is_active' => $isActive,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Пример: Изменение пароля с дополнительными проверками
     */
    public function changeUserPassword(int $userId, string $newPassword): array
    {
        try {
            // 1. Получаем данные пользователя
            $user = $this->getUserById($userId);
            if (!$user) {
                throw new \Exception("User not found");
            }

            // 2. Проверяем сложность пароля
            if (!$this->isPasswordStrong($newPassword)) {
                throw new \Exception("Password does not meet security requirements");
            }

            // 3. Хешируем новый пароль
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

            // 4. Обновляем пароль
            $this->updateUserInDatabase($userId, ['password_hash' => $hashedPassword]);

            // 5. Логируем изменение пароля (критическое событие)
            $eventLogId = $this->eventLoggingService->logEvent(
                entityType: 'user',
                entityId: $userId,
                eventType: 'USER_PASSWORD_CHANGED',
                beforeData: ['password_changed' => true],
                afterData: ['password_changed' => true],
                changedFields: ['password_hash', 'updated_at'],
                options: [
                    'comment' => "User password changed",
                    'actor_type' => 'user',
                    'actor_id' => $this->getCurrentUserId(),
                    'severity' => 'critical'
                ]
            );

            // 6. Дополнительные действия
            $this->invalidateAllUserSessions($userId);
            $this->sendPasswordChangeNotification($userId);

            return [
                'success' => true,
                'user_id' => $userId,
                'event_log_id' => $eventLogId
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to change user password', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Пример: Удаление пользователя с проверками
     */
    public function deleteUser(int $userId): array
    {
        try {
            // 1. Получаем данные пользователя
            $user = $this->getUserById($userId);
            if (!$user) {
                throw new \Exception("User not found");
            }

            // 2. Проверяем, можно ли удалить пользователя
            if (!$this->canDeleteUser($userId)) {
                throw new \Exception("User cannot be deleted (has active projects/tasks)");
            }

            // 3. Мягкое удаление (устанавливаем флаг deleted)
            $this->updateUserInDatabase($userId, [
                'deleted_at' => date('Y-m-d H:i:s'),
                'is_active' => false
            ]);

            // 4. Логируем удаление (критическое событие)
            $eventLogId = $this->eventLoggingService->logEvent(
                entityType: 'user',
                entityId: $userId,
                eventType: 'USER_DELETED',
                beforeData: $user,
                afterData: ['deleted_at' => date('Y-m-d H:i:s'), 'is_active' => false],
                changedFields: ['deleted_at', 'is_active', 'updated_at'],
                options: [
                    'comment' => "User deleted: {$user['email']}",
                    'actor_type' => 'user',
                    'actor_id' => $this->getCurrentUserId(),
                    'severity' => 'critical'
                ]
            );

            // 5. Дополнительные действия при удалении
            $this->deactivateUserSessions($userId);
            $this->transferUserData($userId);
            $this->notifyUserDeletion($userId);

            return [
                'success' => true,
                'user_id' => $userId,
                'deleted_user' => $user,
                'event_log_id' => $eventLogId
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to delete user', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    // Вспомогательные методы (заглушки для примера)
    private function getUserById(int $userId): ?array
    {
        return [
            'id' => $userId,
            'email' => 'user@example.com',
            'role' => 'user',
            'is_active' => true,
            'version' => 1
        ];
    }

    private function updateUserInDatabase(int $userId, array $data): void
    {
        // Реальная реализация обновления в БД
    }

    private function insertUserToDatabase(array $data): int
    {
        // Реальная реализация создания в БД
        return 123;
    }

    private function getCurrentUserId(): int
    {
        return 456;
    }

    private function canChangeUserRole(int $userId, string $newRole): bool
    {
        // Проверка прав на изменение роли
        return true;
    }

    private function canDeleteUser(int $userId): bool
    {
        // Проверка возможности удаления пользователя
        return true;
    }

    private function isPasswordStrong(string $password): bool
    {
        // Проверка сложности пароля
        return strlen($password) >= 8;
    }

    private function sendWelcomeEmail(int $userId): void
    {
        $this->logger->info('Sending welcome email', ['user_id' => $userId]);
    }

    private function assignDefaultRole(int $userId): void
    {
        $this->logger->info('Assigning default role', ['user_id' => $userId]);
    }

    private function grantAdminPermissions(int $userId): void
    {
        $this->logger->info('Granting admin permissions', ['user_id' => $userId]);
    }

    private function revokeAdminPermissions(int $userId): void
    {
        $this->logger->info('Revoking admin permissions', ['user_id' => $userId]);
    }

    private function deactivateUserSessions(int $userId): void
    {
        $this->logger->info('Deactivating user sessions', ['user_id' => $userId]);
    }

    private function notifyUserDeactivation(int $userId): void
    {
        $this->logger->info('Notifying user deactivation', ['user_id' => $userId]);
    }

    private function notifyUserActivation(int $userId): void
    {
        $this->logger->info('Notifying user activation', ['user_id' => $userId]);
    }

    private function invalidateAllUserSessions(int $userId): void
    {
        $this->logger->info('Invalidating all user sessions', ['user_id' => $userId]);
    }

    private function sendPasswordChangeNotification(int $userId): void
    {
        $this->logger->info('Sending password change notification', ['user_id' => $userId]);
    }

    private function transferUserData(int $userId): void
    {
        $this->logger->info('Transferring user data', ['user_id' => $userId]);
    }

    private function notifyUserDeletion(int $userId): void
    {
        $this->logger->info('Notifying user deletion', ['user_id' => $userId]);
    }
}
