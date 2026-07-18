<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\WebPushService;
use Flight;
use Monolog\Logger;
use Throwable;

class PushSubscriptionController
{
    private WebPushService $webPushService;

    public function __construct(
        private readonly Logger $logger
    ) {
        $this->webPushService = new WebPushService($logger);
    }

    public function upsert(): void
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            $this->error('Unauthorized', 401);
            return;
        }

        $body = $this->jsonBody();
        $endpoint = trim((string) ($body['endpoint'] ?? ''));
        $keys = is_array($body['keys'] ?? null) ? $body['keys'] : [];
        $p256dh = trim((string) ($keys['p256dh'] ?? $body['p256dh'] ?? ''));
        $auth = trim((string) ($keys['auth'] ?? $body['auth'] ?? ''));

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            $this->error('endpoint, keys.p256dh and keys.auth are required', 422);
            return;
        }

        if (strlen($endpoint) > 500) {
            $this->error('endpoint is too long', 422);
            return;
        }

        $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512) ?: null;

        try {
            $this->webPushService->upsertSubscription($userId, $endpoint, $p256dh, $auth, $userAgent);
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Push subscription saved',
                'data' => ['endpoint' => $endpoint],
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Failed to save push subscription', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            $this->error('Failed to save push subscription', 500);
        }
    }

    public function delete(): void
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            $this->error('Unauthorized', 401);
            return;
        }

        $body = $this->jsonBody();
        $endpoint = trim((string) ($body['endpoint'] ?? ''));

        if ($endpoint === '') {
            $this->error('endpoint is required', 422);
            return;
        }

        try {
            $this->webPushService->deleteSubscription($userId, $endpoint);
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Push subscription removed',
                'data' => null,
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Failed to delete push subscription', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            $this->error('Failed to delete push subscription', 500);
        }
    }

    public function sendTest(): void
    {
        $userId = $this->currentUserId();
        if ($userId === null) {
            $this->error('Unauthorized', 401);
            return;
        }

        try {
            $count = $this->webPushService->countSubscriptionsForUser($userId);
            if ($count === 0) {
                $this->error('No push subscriptions registered for this user', 404);
                return;
            }

            $result = $this->webPushService->sendToUser(
                $userId,
                'FieldWire test',
                'Push notifications are working on this device.',
                '/account'
            );

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Test push notification queued',
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Failed to send test push', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            $this->error('Failed to send test push notification: ' . $e->getMessage(), 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $raw = Flight::request()->getBody();
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
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
