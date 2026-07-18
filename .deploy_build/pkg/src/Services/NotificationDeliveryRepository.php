<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\ValueObjects\NotificationRequest;
use Doctrine\DBAL\Connection;
use Monolog\Logger;
use Throwable;

class NotificationDeliveryRepository
{
    private Connection $connection;

    public function __construct(
        private readonly Logger $logger
    ) {
        $this->connection = Database::getConnection();
    }

    /**
     * Create a pending delivery row or return existing one by idempotency key.
     *
     * @return array{id: int, status: string, was_duplicate: bool}
     */
    public function createOrGet(NotificationRequest $request, string $channel): array
    {
        $idempotencyKey = $request->channelIdempotencyKey($channel);

        $existing = $this->connection->fetchAssociative(
            'SELECT id, status FROM fw_notifications WHERE idempotency_key = ? LIMIT 1',
            [$idempotencyKey]
        );
        if ($existing) {
            return [
                'id' => (int) $existing['id'],
                'status' => (string) $existing['status'],
                'was_duplicate' => true,
            ];
        }

        $message = match ($channel) {
            'email' => $request->emailBody(),
            'sms' => $request->smsBody(),
            'push' => $request->pushBody(),
            default => $request->message,
        };
        $title = match ($channel) {
            'push' => $request->pushTitle(),
            'email' => $request->emailSubject(),
            default => $request->title,
        };

        $dataJson = $request->data !== []
            ? json_encode($request->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        try {
            $this->connection->executeStatement(
                'INSERT INTO fw_notifications
                    (recipient_id, sender_id, type, title, message, data, status, channel, priority,
                     event_log_id, correlation_id, idempotency_key, url, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [
                    $request->recipientUserId,
                    $request->senderUserId,
                    $request->type,
                    $title,
                    $message,
                    $dataJson,
                    'pending',
                    $channel,
                    $this->normalizePriority($request->priority),
                    $request->eventLogId,
                    $request->correlationId,
                    $idempotencyKey,
                    $request->url,
                ]
            );
        } catch (Throwable $e) {
            // Race on unique idempotency key
            $existing = $this->connection->fetchAssociative(
                'SELECT id, status FROM fw_notifications WHERE idempotency_key = ? LIMIT 1',
                [$idempotencyKey]
            );
            if ($existing) {
                return [
                    'id' => (int) $existing['id'],
                    'status' => (string) $existing['status'],
                    'was_duplicate' => true,
                ];
            }
            $this->logger->error('Failed to create notification row', [
                'error' => $e->getMessage(),
                'channel' => $channel,
                'type' => $request->type,
            ]);
            throw $e;
        }

        return [
            'id' => (int) $this->connection->lastInsertId(),
            'status' => 'pending',
            'was_duplicate' => false,
        ];
    }

    public function markResult(
        int $notificationId,
        string $status,
        ?string $provider = null,
        ?string $providerMessageId = null,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        bool $isRetryable = false
    ): void {
        $attemptNo = (int) $this->connection->fetchOne(
            'SELECT COALESCE(MAX(attempt_no), 0) + 1 FROM fw_notification_attempts WHERE notification_id = ?',
            [$notificationId]
        );

        $this->connection->executeStatement(
            'INSERT INTO fw_notification_attempts
                (notification_id, attempt_no, provider, status, provider_message_id, error_code, error_message, is_retryable, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())',
            [
                $notificationId,
                $attemptNo,
                $provider,
                $status,
                $providerMessageId,
                $errorCode,
                $errorMessage,
                $isRetryable ? 1 : 0,
            ]
        );

        $sentAt = $status === 'sent' ? date('Y-m-d H:i:s') : null;
        $failedAt = $status === 'failed' ? date('Y-m-d H:i:s') : null;
        $nextAttempt = ($status === 'failed' && $isRetryable)
            ? date('Y-m-d H:i:s', time() + 300)
            : null;

        $this->connection->executeStatement(
            'UPDATE fw_notifications
             SET status = ?,
                 provider = COALESCE(?, provider),
                 provider_message_id = COALESCE(?, provider_message_id),
                 failure_reason = ?,
                 sent_at = COALESCE(?, sent_at),
                 failed_at = ?,
                 next_attempt_at = ?,
                 last_attempt_at = NOW(),
                 retry_count = GREATEST(retry_count, ? - 1),
                 updated_at = NOW()
             WHERE id = ?',
            [
                $status,
                $provider,
                $providerMessageId,
                $errorMessage,
                $sentAt,
                $failedAt,
                $nextAttempt,
                $attemptNo,
                $notificationId,
            ]
        );
    }

    private function normalizePriority(string $priority): string
    {
        $priority = strtolower(trim($priority));
        return in_array($priority, ['low', 'medium', 'high', 'urgent'], true) ? $priority : 'medium';
    }
}
