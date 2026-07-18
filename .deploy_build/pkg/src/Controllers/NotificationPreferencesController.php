<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\NotificationPreferenceService;
use Flight;
use Monolog\Logger;
use Throwable;

class NotificationPreferencesController
{
    private NotificationPreferenceService $preferenceService;

    public function __construct(
        private readonly Logger $logger
    ) {
        $this->preferenceService = new NotificationPreferenceService($logger);
    }

    public function getMine(): void
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            $this->error('Unauthorized', 401);
            return;
        }

        try {
            $prefs = $this->preferenceService->getForUser($userId);
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Notification preferences retrieved',
                'data' => $prefs,
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Failed to get notification preferences', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            $this->error('Failed to get notification preferences', 500);
        }
    }

    public function patchMine(): void
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            $this->error('Unauthorized', 401);
            return;
        }

        $raw = Flight::request()->getBody();
        $body = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($body)) {
            $this->error('Invalid JSON body', 422);
            return;
        }

        $allowed = [
            'outbound_enabled',
            'email_enabled',
            'sms_enabled',
            'push_enabled',
            'field_work_start_enabled',
            'field_work_end_enabled',
        ];
        $patch = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            if (!is_bool($body[$key]) && !in_array($body[$key], [0, 1, '0', '1'], true)) {
                $this->error("{$key} must be a boolean", 422);
                return;
            }
            $patch[$key] = (bool) $body[$key];
        }

        if ($patch === []) {
            $this->error('No preference fields provided', 422);
            return;
        }

        try {
            $prefs = $this->preferenceService->updateForUser($userId, $patch);
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Notification preferences updated',
                'data' => $prefs,
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 403);
        } catch (Throwable $e) {
            $this->logger->error('Failed to update notification preferences', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            $this->error('Failed to update notification preferences', 500);
        }
    }

    public function getMyEvents(): void
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            $this->error('Unauthorized', 401);
            return;
        }

        try {
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Notification event preferences retrieved',
                'data' => [
                    'events' => $this->preferenceService->getAvailableEventsForUser($userId),
                ],
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Failed to get notification event preferences', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            $this->error('Failed to get notification event preferences', 500);
        }
    }

    public function patchMyEvent(string $eventType): void
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            $this->error('Unauthorized', 401);
            return;
        }

        $raw = Flight::request()->getBody();
        $body = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($body)) {
            $this->error('Invalid JSON body', 422);
            return;
        }

        $patch = [];
        foreach (['email_enabled', 'sms_enabled', 'push_enabled'] as $key) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            if (!is_bool($body[$key]) && !in_array($body[$key], [0, 1, '0', '1'], true)) {
                $this->error("{$key} must be a boolean", 422);
                return;
            }
            $patch[$key] = (bool) $body[$key];
        }
        if ($patch === []) {
            $this->error('No event preference fields provided', 422);
            return;
        }

        try {
            $event = $this->preferenceService->updateEventForUser($userId, $eventType, $patch);
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Notification event preference updated',
                'data' => $event,
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 403);
        } catch (Throwable $e) {
            $this->logger->error('Failed to update notification event preference', [
                'user_id' => $userId,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
            $this->error('Failed to update notification event preference', 500);
        }
    }

    private function currentUserId(): ?int
    {
        $user = Flight::get('current_user');
        if (!is_array($user) || !isset($user['id'])) {
            return null;
        }
        return (int) $user['id'];
    }

    private function error(string $message, int $http): void
    {
        Flight::json([
            'error_code' => $http,
            'status' => 'error',
            'message' => $message,
            'data' => null,
        ], $http);
    }
}
