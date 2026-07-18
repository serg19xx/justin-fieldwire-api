<?php

declare(strict_types=1);

namespace App\ValueObjects;

class NotificationChannelResult
{
    public function __construct(
        public readonly string $channel,
        public readonly string $status,
        public readonly ?int $notificationId = null,
        public readonly ?string $provider = null,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly bool $isRetryable = false,
        public readonly bool $wasDuplicate = false,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status === 'sent';
    }

    public function isSkipped(): bool
    {
        return $this->status === 'skipped';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'channel' => $this->channel,
            'status' => $this->status,
            'notification_id' => $this->notificationId,
            'provider' => $this->provider,
            'provider_message_id' => $this->providerMessageId,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
            'is_retryable' => $this->isRetryable,
            'was_duplicate' => $this->wasDuplicate,
        ];
    }
}
