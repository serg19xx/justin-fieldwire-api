<?php

declare(strict_types=1);

namespace App\ValueObjects;

class NotificationDispatchResult
{
    /** @param list<NotificationChannelResult> $channels */
    public function __construct(
        public readonly int $recipientUserId,
        public readonly string $type,
        public readonly array $channels,
        public readonly string $overallStatus,
    ) {
    }

    public function hasFailures(): bool
    {
        foreach ($this->channels as $channel) {
            if ($channel->isFailed()) {
                return true;
            }
        }
        return false;
    }

    public function hasSent(): bool
    {
        foreach ($this->channels as $channel) {
            if ($channel->isSuccess()) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'recipient_user_id' => $this->recipientUserId,
            'type' => $this->type,
            'overall_status' => $this->overallStatus,
            'channels' => array_map(
                static fn (NotificationChannelResult $c) => $c->toArray(),
                $this->channels
            ),
        ];
    }
}
