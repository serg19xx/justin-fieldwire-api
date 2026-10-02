<?php

declare(strict_types=1);

namespace App\Services;

use Monolog\Logger;

/**
 * Map inbound SMS keywords to outreach recipient statuses.
 */
class OutreachSmsReplyService
{
    public function __construct(
        private readonly Logger $logger,
        private readonly OutreachRecipientService $recipients,
    ) {
    }

    /**
     * @param array<string, mixed> $twilioPayload
     * @return array{handled: bool, reply_sms?: string}
     */
    public function handleInbound(array $twilioPayload): array
    {
        $from = trim((string) ($twilioPayload['From'] ?? ''));
        $body = trim((string) ($twilioPayload['Body'] ?? ''));
        if ($from === '' || $body === '') {
            return ['handled' => false];
        }

        $recipient = $this->recipients->findAwaitingSmsByPhone($from);
        if ($recipient === null) {
            return ['handled' => false];
        }

        $intent = $this->parseIntent($body);
        if ($intent === null) {
            return [
                'handled' => true,
                'reply_sms' => 'FieldWire outreach: reply YES if interested, DECLINE to refuse, or STOP to unsubscribe.',
            ];
        }

        $ok = $this->recipients->applyStatus(
            (int) $recipient['id'],
            $intent['status'],
            'twilio',
            $intent['note'],
            null,
            [
                'from' => $from,
                'body' => substr($body, 0, 200),
                'keyword' => $intent['keyword'],
            ],
            $intent['event_type'],
        );

        if (!$ok) {
            $this->logger->warning('Outreach SMS status apply failed', [
                'recipient_id' => $recipient['id'],
                'intent' => $intent,
            ]);

            return ['handled' => true, 'reply_sms' => ''];
        }

        return [
            'handled' => true,
            'reply_sms' => $intent['ack'],
        ];
    }

    /**
     * @return array{status: string, event_type: string, keyword: string, note: string, ack: string}|null
     */
    private function parseIntent(string $body): ?array
    {
        $norm = strtoupper(trim(preg_replace('/\s+/', ' ', $body) ?? $body));
        $first = preg_split('/[\s,;.!?]+/', $norm, 2)[0] ?? $norm;

        if (in_array($first, ['YES', 'Y', 'INTERESTED', 'OK', 'ACCEPT'], true)) {
            return [
                'status' => 'replied',
                'event_type' => 'sms_yes',
                'keyword' => $first,
                'note' => 'SMS: ' . $first,
                'ack' => 'Thanks — we recorded your interest. A team member may follow up soon.',
            ];
        }
        if (in_array($first, ['DECLINE', 'NO', 'NOPE', 'REFUSE'], true)) {
            return [
                'status' => 'declined',
                'event_type' => 'sms_decline',
                'keyword' => $first,
                'note' => 'SMS: ' . $first,
                'ack' => 'Understood. We will not follow up on this invitation.',
            ];
        }
        if (in_array($first, ['STOP', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'], true)) {
            return [
                'status' => 'unsubscribed',
                'event_type' => 'sms_stop',
                'keyword' => $first,
                'note' => 'SMS: ' . $first,
                'ack' => 'You are unsubscribed from FieldWire outreach SMS. No further messages.',
            ];
        }

        return null;
    }
}
