<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\OutreachRecipientService;
use Flight;
use Monolog\Logger;

class SendGridEventWebhookController
{
    public function __construct(
        private readonly Logger $logger,
        private readonly OutreachRecipientService $recipients,
    ) {
    }

    /** POST /api/v1/sendgrid/events */
    public function handle(): void
    {
        if (!$this->authorize()) {
            Flight::json(['error_code' => 401, 'status' => 'error', 'message' => 'Unauthorized', 'data' => null], 401);

            return;
        }

        $raw = Flight::request()->getBody();
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            $data = Flight::request()->data->getData();
            $decoded = is_array($data) ? $data : [];
        }

        // SendGrid sends a JSON array of events.
        $events = array_is_list($decoded) ? $decoded : [$decoded];
        $processed = 0;
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            if ($this->processOne($event)) {
                $processed++;
            }
        }

        Flight::json([
            'error_code' => 0,
            'status' => 'success',
            'message' => 'Events processed',
            'data' => ['processed' => $processed],
        ]);
    }

    /** @param array<string, mixed> $event */
    private function processOne(array $event): bool
    {
        $type = strtolower(trim((string) ($event['event'] ?? '')));
        if (!in_array($type, ['unsubscribe', 'group_unsubscribe', 'spamreport'], true)) {
            return false;
        }

        $recipientId = $this->resolveRecipientId($event);
        if ($recipientId === null) {
            $this->logger->info('SendGrid event: recipient not resolved', [
                'event' => $type,
                'email' => $event['email'] ?? null,
            ]);

            return false;
        }

        return $this->recipients->applyStatus(
            $recipientId,
            'unsubscribed',
            'sendgrid',
            'SendGrid: ' . $type,
            null,
            $event,
            $type,
        );
    }

    /** @param array<string, mixed> $event */
    private function resolveRecipientId(array $event): ?int
    {
        $custom = $event['outreach_recipient_id']
            ?? $event['unique_args']['outreach_recipient_id']
            ?? null;
        if (is_numeric($custom)) {
            return (int) $custom;
        }

        $email = strtolower(trim((string) ($event['email'] ?? '')));
        if ($email === '') {
            return null;
        }

        $campaignHint = $event['campaign_id']
            ?? $event['unique_args']['campaign_id']
            ?? null;
        $sql = "SELECT id FROM fw_outreach_recipients
                WHERE channel = 'email' AND LOWER(destination) = ? AND status IN ('sent','queued')";
        $params = [$email];
        if (is_numeric($campaignHint)) {
            $sql .= ' AND campaign_id = ?';
            $params[] = (int) $campaignHint;
        }
        $sql .= ' ORDER BY id DESC LIMIT 1';

        try {
            $id = (new \App\Database\Database())->getConnection()
                ->executeQuery($sql, $params)
                ->fetchOne();

            return $id !== false && $id !== null ? (int) $id : null;
        } catch (\Throwable $e) {
            $this->logger->error('SendGrid event lookup failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function authorize(): bool
    {
        $skip = strtolower(trim((string) ($_ENV['SENDGRID_EVENT_WEBHOOK_SKIP_VERIFY'] ?? '')));
        if (in_array($skip, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        $expected = trim((string) ($_ENV['SENDGRID_EVENT_WEBHOOK_SECRET'] ?? ''));
        if ($expected === '') {
            $expected = trim((string) ($_ENV['OUTREACH_N8N_SECRET'] ?? ''));
        }
        if ($expected === '') {
            // Misconfigured: reject rather than accept anonymous posts.
            return false;
        }

        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach (['X-Outreach-Secret', 'X-Sendgrid-Event-Secret'] as $name) {
            foreach ($headers as $k => $v) {
                if (strcasecmp((string) $k, $name) === 0 && hash_equals($expected, (string) $v)) {
                    return true;
                }
            }
        }

        // Query token fallback for SendGrid URL query string auth.
        $q = trim((string) (Flight::request()->query->secret ?? ''));
        if ($q !== '' && hash_equals($expected, $q)) {
            return true;
        }

        return false;
    }
}
