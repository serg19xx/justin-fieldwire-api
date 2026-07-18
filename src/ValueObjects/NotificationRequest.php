<?php

declare(strict_types=1);

namespace App\ValueObjects;

/**
 * Multi-channel notification request for NotificationDispatcher.
 */
class NotificationRequest
{
    public function __construct(
        public readonly int $recipientUserId,
        public readonly string $type,
        public readonly string $title,
        public readonly string $message,
        /** @var list<string> */
        public readonly array $channels = ['email'],
        public readonly string $priority = 'medium',
        public readonly ?int $senderUserId = null,
        public readonly ?int $eventLogId = null,
        public readonly ?string $correlationId = null,
        public readonly ?string $url = null,
        /** @var array<string, mixed> */
        public readonly array $data = [],
        public readonly ?string $emailSubject = null,
        public readonly ?string $emailHtml = null,
        public readonly ?string $smsBody = null,
        public readonly ?string $pushTitle = null,
        public readonly ?string $pushBody = null,
        public readonly bool $bypassPreferences = false,
        public readonly ?string $idempotencyKey = null,
    ) {
    }

    public function emailSubject(): string
    {
        return $this->emailSubject ?? $this->title;
    }

    public function emailBody(): string
    {
        return $this->emailHtml ?? $this->message;
    }

    public function smsBody(): string
    {
        return $this->smsBody ?? $this->message;
    }

    public function pushTitle(): string
    {
        return $this->pushTitle ?? $this->title;
    }

    public function pushBody(): string
    {
        return $this->pushBody ?? $this->message;
    }

    public function normalizedChannels(): array
    {
        $allowed = ['email', 'sms', 'push'];
        $out = [];
        foreach ($this->channels as $channel) {
            $channel = strtolower(trim((string) $channel));
            if (in_array($channel, $allowed, true) && !in_array($channel, $out, true)) {
                $out[] = $channel;
            }
        }
        return $out !== [] ? $out : ['email'];
    }

    public function channelIdempotencyKey(string $channel): string
    {
        if ($this->idempotencyKey !== null && $this->idempotencyKey !== '') {
            return substr($this->idempotencyKey . ':' . $channel, 0, 191);
        }

        $correlation = $this->correlationId ?: ('evt:' . ($this->eventLogId ?? 'none'));
        return substr(implode(':', [
            $this->type,
            (string) $this->recipientUserId,
            $channel,
            $correlation,
        ]), 0, 191);
    }
}
