<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use Doctrine\DBAL\Connection;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Monolog\Logger;
use Throwable;

class WebPushService
{
    private Connection $connection;
    private ?WebPush $webPush = null;

    public function __construct(
        private readonly Logger $logger
    ) {
        $this->connection = Database::getConnection();
    }

    public function upsertSubscription(
        int $userId,
        string $endpoint,
        string $p256dh,
        string $auth,
        ?string $userAgent = null
    ): void {
        $existing = $this->connection->fetchAssociative(
            'SELECT id, user_id FROM fw_push_subscriptions WHERE endpoint = ? LIMIT 1',
            [$endpoint]
        );

        if ($existing) {
            $this->connection->executeStatement(
                'UPDATE fw_push_subscriptions
                 SET user_id = ?, p256dh = ?, auth = ?, user_agent = ?, updated_at = NOW()
                 WHERE id = ?',
                [$userId, $p256dh, $auth, $userAgent, (int) $existing['id']]
            );
            return;
        }

        $this->connection->executeStatement(
            'INSERT INTO fw_push_subscriptions (user_id, endpoint, p256dh, auth, user_agent, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NOW(), NOW())',
            [$userId, $endpoint, $p256dh, $auth, $userAgent]
        );
    }

    public function deleteSubscription(int $userId, string $endpoint): void
    {
        $this->connection->executeStatement(
            'DELETE FROM fw_push_subscriptions WHERE user_id = ? AND endpoint = ?',
            [$userId, $endpoint]
        );
    }

    /**
     * @return array{sent: int, failed: int, removed: int}
     */
    public function sendToUser(int $userId, string $title, string $body, string $url = '/'): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, endpoint, p256dh, auth FROM fw_push_subscriptions WHERE user_id = ?',
            [$userId]
        );

        if ($rows === []) {
            return ['sent' => 0, 'failed' => 0, 'removed' => 0];
        }

        $webPush = $this->getWebPush();
        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'url' => $url,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            throw new \RuntimeException('Failed to encode push payload');
        }

        foreach ($rows as $row) {
            $subscription = Subscription::create([
                'endpoint' => (string) $row['endpoint'],
                'publicKey' => (string) $row['p256dh'],
                'authToken' => (string) $row['auth'],
            ]);
            $webPush->queueNotification($subscription, $payload);
        }

        $sent = 0;
        $failed = 0;
        $removed = 0;

        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $sent++;
                continue;
            }

            $failed++;
            $endpoint = $report->getEndpoint();
            $response = $report->getResponse();
            $statusCode = $response ? $response->getStatusCode() : 0;

            $this->logger->warning('Web push delivery failed', [
                'user_id' => $userId,
                'endpoint' => $endpoint,
                'status_code' => $statusCode,
                'reason' => $report->getReason(),
            ]);

            if (in_array($statusCode, [404, 410], true)) {
                $this->connection->executeStatement(
                    'DELETE FROM fw_push_subscriptions WHERE endpoint = ?',
                    [$endpoint]
                );
                $removed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'removed' => $removed];
    }

    public function countSubscriptionsForUser(int $userId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM fw_push_subscriptions WHERE user_id = ?',
            [$userId]
        );
    }

    private function getWebPush(): WebPush
    {
        if ($this->webPush instanceof WebPush) {
            return $this->webPush;
        }

        $publicKey = trim((string) ($_ENV['VAPID_PUBLIC_KEY'] ?? getenv('VAPID_PUBLIC_KEY') ?: ''));
        $privateKey = trim((string) ($_ENV['VAPID_PRIVATE_KEY'] ?? getenv('VAPID_PRIVATE_KEY') ?: ''));
        $subject = trim((string) ($_ENV['VAPID_SUBJECT'] ?? getenv('VAPID_SUBJECT') ?: 'mailto:support@medicalcontractor.ca'));

        if ($publicKey === '' || $privateKey === '') {
            throw new \RuntimeException('VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY must be configured');
        }

        try {
            $this->webPush = new WebPush([
                'VAPID' => [
                    'subject' => $subject,
                    'publicKey' => $publicKey,
                    'privateKey' => $privateKey,
                ],
            ]);
        } catch (Throwable $e) {
            $this->logger->error('Failed to initialize WebPush', ['error' => $e->getMessage()]);
            throw $e;
        }

        return $this->webPush;
    }
}
