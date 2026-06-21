<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\SmsMeetingInviteService;
use App\Services\TwilioService;
use App\Support\ClientRegistryContacts;
use Flight;
use Monolog\Logger;
use Twilio\Security\RequestValidator;

class SmsMeetingInviteController
{
    public function __construct(
        private readonly Logger $logger,
        private readonly SmsMeetingInviteService $inviteService,
    ) {
    }

    public function send(string $type, int $id): void
    {
        if (!$this->authorize()) {
            return;
        }

        if (!ClientRegistryContacts::isAllowedType($type)) {
            $this->respondError('Invalid client type', 400);
            return;
        }

        $body = Flight::request()->data->getData();
        if (!is_array($body)) {
            $body = json_decode(Flight::request()->getBody(), true);
        }
        if (!is_array($body)) {
            $body = [];
        }

        $user = Flight::get('current_user');
        $userId = (int) ($user['id'] ?? 0);

        $result = $this->inviteService->sendInvite($userId, $type, $id, $body);
        if (!$result['success']) {
            $this->respondError($result['error'] ?? 'Failed to send invite', 400);
            return;
        }

        Flight::json([
            'status' => 'success',
            'message' => $result['message'] ?? 'Meeting invite sent',
            'data' => [
                'invite_id' => $result['invite_id'] ?? null,
                'sent_to' => $result['sent_to'] ?? '',
                'original_to' => $result['original_to'] ?? '',
                'test_mode' => $result['test_mode'] ?? false,
            ],
        ]);
    }

    public function latest(string $type, int $id): void
    {
        if (!$this->authorize()) {
            return;
        }

        if (!ClientRegistryContacts::isAllowedType($type)) {
            $this->respondError('Invalid client type', 400);
            return;
        }

        $user = Flight::get('current_user');
        $userId = (int) ($user['id'] ?? 0);

        $result = $this->inviteService->getLatestInvite($userId, $type, $id);
        if (!$result['success']) {
            $this->respondError($result['error'] ?? 'Failed to load invite', 500);
            return;
        }

        Flight::json([
            'status' => 'success',
            'data' => [
                'invite' => $result['invite'] ?? null,
            ],
        ]);
    }

    private function authorize(): bool
    {
        $user = Flight::get('current_user');
        if (!$user) {
            $this->respondError('Unauthorized', 401);
            return false;
        }

        $role = strtolower((string) ($user['role_code'] ?? ''));
        if (!SmsMeetingInviteService::isAllowedRole($role)) {
            $this->respondError('Forbidden', 403);
            return false;
        }

        return true;
    }

    private function respondError(string $message, int $httpStatus): void
    {
        Flight::json([
            'error_code' => $httpStatus,
            'status' => 'error',
            'message' => $message,
            'data' => null,
        ], $httpStatus);
    }
}
