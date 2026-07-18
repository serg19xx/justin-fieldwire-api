<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\ValueObjects\NotificationChannelResult;
use App\ValueObjects\NotificationDispatchResult;
use App\ValueObjects\NotificationRequest;
use Doctrine\DBAL\Connection;
use Monolog\Logger;
use Throwable;

/**
 * Unified multi-channel notification dispatcher (email / SMS / push).
 * Push is a short signal; email and SMS remain primary content channels.
 */
class NotificationDispatcher
{
    private Connection $connection;
    private EmailService $emailService;
    private TwilioService $twilioService;
    private WebPushService $webPushService;
    private NotificationPreferenceService $preferenceService;
    private NotificationDeliveryRepository $deliveryRepository;

    public function __construct(
        private readonly Logger $logger,
        ?EmailService $emailService = null,
        ?TwilioService $twilioService = null,
        ?WebPushService $webPushService = null,
        ?NotificationPreferenceService $preferenceService = null,
        ?NotificationDeliveryRepository $deliveryRepository = null,
    ) {
        $this->connection = Database::getConnection();
        $this->emailService = $emailService ?? new EmailService($logger);
        $this->twilioService = $twilioService ?? new TwilioService($logger);
        $this->webPushService = $webPushService ?? new WebPushService($logger);
        $this->preferenceService = $preferenceService ?? new NotificationPreferenceService($logger);
        $this->deliveryRepository = $deliveryRepository ?? new NotificationDeliveryRepository($logger);
    }

    public function dispatch(NotificationRequest $request): NotificationDispatchResult
    {
        $channelResults = [];

        // Never notify the actor about their own action (unless explicitly bypassed).
        if (
            !$request->bypassPreferences
            && $request->senderUserId !== null
            && $request->senderUserId === $request->recipientUserId
        ) {
            foreach ($request->normalizedChannels() as $channel) {
                $channelResults[] = $this->recordSkip(
                    $request,
                    $channel,
                    'actor_is_recipient',
                    'Notification skipped because recipient triggered the event'
                );
            }

            return $this->buildResult($request, $channelResults);
        }

        $recipient = $this->loadRecipient($request->recipientUserId);

        if ($recipient === null) {
            foreach ($request->normalizedChannels() as $channel) {
                $channelResults[] = $this->recordSkip(
                    $request,
                    $channel,
                    'recipient_not_found',
                    'Recipient user not found or inactive'
                );
            }

            return $this->buildResult($request, $channelResults);
        }

        foreach ($request->normalizedChannels() as $channel) {
            $channelResults[] = $this->dispatchChannel($request, $recipient, $channel);
        }

        return $this->buildResult($request, $channelResults);
    }

    /**
     * @param array<string, mixed> $recipient
     */
    private function dispatchChannel(
        NotificationRequest $request,
        array $recipient,
        string $channel
    ): NotificationChannelResult {
        if (!$this->preferenceService->isChannelAllowed(
            $request->recipientUserId,
            $request->type,
            $channel,
            $request->bypassPreferences
        )) {
            return $this->recordSkip(
                $request,
                $channel,
                'preferences_disabled',
                'Event or channel disabled by user preferences'
            );
        }

        $preflight = $this->preflightChannel($channel, $recipient);
        if ($preflight !== null) {
            return $this->recordSkip($request, $channel, $preflight[0], $preflight[1]);
        }

        $row = $this->deliveryRepository->createOrGet($request, $channel);
        if ($row['was_duplicate'] && in_array($row['status'], ['sent', 'skipped'], true)) {
            return new NotificationChannelResult(
                channel: $channel,
                status: $row['status'],
                notificationId: $row['id'],
                wasDuplicate: true,
            );
        }

        try {
            return match ($channel) {
                'email' => $this->sendEmail($request, $recipient, $row['id']),
                'sms' => $this->sendSms($request, $recipient, $row['id']),
                'push' => $this->sendPush($request, $row['id']),
                default => $this->markFailed(
                    $row['id'],
                    $channel,
                    'unsupported_channel',
                    'Unsupported channel',
                    false
                ),
            };
        } catch (Throwable $e) {
            $this->logger->error('Notification channel dispatch failed', [
                'channel' => $channel,
                'recipient_id' => $request->recipientUserId,
                'type' => $request->type,
                'error' => $e->getMessage(),
            ]);

            return $this->markFailed(
                $row['id'],
                $channel,
                'exception',
                $e->getMessage(),
                true
            );
        }
    }

    /**
     * @param array<string, mixed> $recipient
     * @return array{0: string, 1: string}|null
     */
    private function preflightChannel(string $channel, array $recipient): ?array
    {
        return match ($channel) {
            'email' => empty($recipient['email'])
                ? ['missing_email', 'Recipient has no email address']
                : null,
            'sms' => empty($recipient['phone'])
                ? ['missing_phone', 'Recipient has no phone number']
                : null,
            'push' => $this->webPushService->countSubscriptionsForUser((int) $recipient['id']) === 0
                ? ['no_push_subscription', 'Recipient has no push subscriptions']
                : null,
            default => ['unsupported_channel', 'Unsupported channel'],
        };
    }

    /**
     * @param array<string, mixed> $recipient
     */
    private function sendEmail(
        NotificationRequest $request,
        array $recipient,
        int $notificationId
    ): NotificationChannelResult {
        $ok = $this->emailService->sendEmail(
            (string) $recipient['email'],
            $request->emailSubject(),
            $request->emailBody(),
            trim(($recipient['first_name'] ?? '') . ' ' . ($recipient['last_name'] ?? ''))
        );

        if ($ok) {
            $this->deliveryRepository->markResult($notificationId, 'sent', 'email');
            return new NotificationChannelResult(
                channel: 'email',
                status: 'sent',
                notificationId: $notificationId,
                provider: 'email',
            );
        }

        return $this->markFailed(
            $notificationId,
            'email',
            'send_failed',
            'Email provider returned failure',
            true,
            'email'
        );
    }

    /**
     * @param array<string, mixed> $recipient
     */
    private function sendSms(
        NotificationRequest $request,
        array $recipient,
        int $notificationId
    ): NotificationChannelResult {
        $ok = $this->twilioService->sendSms((string) $recipient['phone'], $request->smsBody());
        if ($ok) {
            $this->deliveryRepository->markResult($notificationId, 'sent', 'twilio');
            return new NotificationChannelResult(
                channel: 'sms',
                status: 'sent',
                notificationId: $notificationId,
                provider: 'twilio',
            );
        }

        return $this->markFailed(
            $notificationId,
            'sms',
            'send_failed',
            'SMS provider returned failure',
            true,
            'twilio'
        );
    }

    private function sendPush(NotificationRequest $request, int $notificationId): NotificationChannelResult
    {
        $result = $this->webPushService->sendToUser(
            $request->recipientUserId,
            $request->pushTitle(),
            $request->pushBody(),
            $request->url ?? '/'
        );

        if (($result['sent'] ?? 0) > 0) {
            $this->deliveryRepository->markResult($notificationId, 'sent', 'web-push');
            return new NotificationChannelResult(
                channel: 'push',
                status: 'sent',
                notificationId: $notificationId,
                provider: 'web-push',
            );
        }

        return $this->markFailed(
            $notificationId,
            'push',
            'send_failed',
            'No push endpoints accepted the payload',
            true,
            'web-push'
        );
    }

    private function recordSkip(
        NotificationRequest $request,
        string $channel,
        string $errorCode,
        string $errorMessage
    ): NotificationChannelResult {
        try {
            $row = $this->deliveryRepository->createOrGet($request, $channel);
            if ($row['was_duplicate'] && in_array($row['status'], ['sent', 'skipped'], true)) {
                return new NotificationChannelResult(
                    channel: $channel,
                    status: $row['status'],
                    notificationId: $row['id'],
                    errorCode: $errorCode,
                    errorMessage: $errorMessage,
                    wasDuplicate: true,
                );
            }

            $this->deliveryRepository->markResult(
                $row['id'],
                'skipped',
                null,
                null,
                $errorCode,
                $errorMessage,
                false
            );

            return new NotificationChannelResult(
                channel: $channel,
                status: 'skipped',
                notificationId: $row['id'],
                errorCode: $errorCode,
                errorMessage: $errorMessage,
            );
        } catch (Throwable $e) {
            $this->logger->warning('Could not persist skipped notification', [
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);

            return new NotificationChannelResult(
                channel: $channel,
                status: 'skipped',
                errorCode: $errorCode,
                errorMessage: $errorMessage,
            );
        }
    }

    private function markFailed(
        int $notificationId,
        string $channel,
        string $errorCode,
        string $errorMessage,
        bool $isRetryable,
        ?string $provider = null
    ): NotificationChannelResult {
        $this->deliveryRepository->markResult(
            $notificationId,
            'failed',
            $provider,
            null,
            $errorCode,
            $errorMessage,
            $isRetryable
        );

        return new NotificationChannelResult(
            channel: $channel,
            status: 'failed',
            notificationId: $notificationId,
            provider: $provider,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            isRetryable: $isRetryable,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadRecipient(int $userId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, email, phone, first_name, last_name, status, archived_at
             FROM fw_users
             WHERE id = ?
             LIMIT 1',
            [$userId]
        );

        if (!$row) {
            return null;
        }

        if (!empty($row['archived_at'])) {
            return null;
        }

        // status is tinyint(1) in prod; treat 0/false as inactive
        if (array_key_exists('status', $row) && (int) $row['status'] === 0) {
            return null;
        }

        return $row;
    }

    /**
     * @param list<NotificationChannelResult> $channelResults
     */
    private function buildResult(
        NotificationRequest $request,
        array $channelResults
    ): NotificationDispatchResult {
        $hasSent = false;
        $hasFailed = false;
        $allSkipped = true;

        foreach ($channelResults as $result) {
            if ($result->isSuccess()) {
                $hasSent = true;
                $allSkipped = false;
            } elseif ($result->isFailed()) {
                $hasFailed = true;
                $allSkipped = false;
            } elseif (!$result->isSkipped()) {
                $allSkipped = false;
            }
        }

        $overall = match (true) {
            $hasSent && !$hasFailed => 'sent',
            $hasSent && $hasFailed => 'partial',
            $hasFailed => 'failed',
            $allSkipped => 'skipped',
            default => 'pending',
        };

        return new NotificationDispatchResult(
            recipientUserId: $request->recipientUserId,
            type: $request->type,
            channels: $channelResults,
            overallStatus: $overall,
        );
    }
}
