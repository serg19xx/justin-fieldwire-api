<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\SmsMeetingInviteService;
use App\Services\TwilioService;
use Flight;
use Monolog\Logger;
use Twilio\Security\RequestValidator;

class TwilioSmsWebhookController
{
    public function __construct(
        private readonly Logger $logger,
        private readonly SmsMeetingInviteService $inviteService,
        private readonly TwilioService $twilioService,
    ) {
    }

    public function inboundSms(): void
    {
        if (!$this->validateTwilioSignature()) {
            Flight::halt(403, 'Forbidden');
            return;
        }

        $payload = $this->readTwilioPayload();

        $this->logger->info('Twilio inbound SMS received', [
            'from' => $payload['From'] ?? null,
            'body_preview' => isset($payload['Body']) ? substr((string) $payload['Body'], 0, 40) : null,
        ]);

        $result = $this->inviteService->handleInboundSms($payload);
        if (!$result['success']) {
            Flight::halt(500, 'Processing failed');
            return;
        }

        $reply = trim((string) ($result['reply_sms'] ?? ''));
        if ($reply !== '' && isset($payload['From'])) {
            $this->twilioService->sendSms((string) $payload['From'], $reply);
        }

        header('Content-Type: text/xml; charset=utf-8');
        echo '<?xml version="1.0" encoding="UTF-8"?><Response></Response>';
        Flight::stop();
    }

    /**
     * @return array<string, string>
     */
    private function readTwilioPayload(): array
    {
        if (is_array($_POST) && $_POST !== []) {
            $out = [];
            foreach ($_POST as $key => $value) {
                if (is_string($key) && (is_string($value) || is_numeric($value))) {
                    $out[$key] = (string) $value;
                }
            }
            return $out;
        }

        $raw = file_get_contents('php://input');
        if (is_string($raw) && $raw !== '') {
            parse_str($raw, $parsed);
            if (is_array($parsed)) {
                $out = [];
                foreach ($parsed as $key => $value) {
                    if (is_string($key) && (is_string($value) || is_numeric($value))) {
                        $out[$key] = (string) $value;
                    }
                }
                return $out;
            }
        }

        $data = Flight::request()->data->getData();
        return is_array($data) ? $data : [];
    }

    private function validateTwilioSignature(): bool
    {
        $skip = strtolower(trim((string) ($_ENV['TWILIO_SKIP_WEBHOOK_SIGNATURE'] ?? '')));
        if (in_array($skip, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }

        $authToken = trim((string) ($_ENV['TWILIO_AUTH_TOKEN'] ?? ''));
        if ($authToken === '') {
            $this->logger->warning('Twilio webhook: missing TWILIO_AUTH_TOKEN');
            return false;
        }

        $signature = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';
        if ($signature === '') {
            $this->logger->warning('Twilio webhook: missing X-Twilio-Signature header');
            return false;
        }

        $payload = $this->readTwilioPayload();
        $validator = new RequestValidator($authToken);

        foreach ($this->webhookUrlCandidates() as $url) {
            if ($validator->validate($signature, $url, $payload)) {
                return true;
            }
        }

        $this->logger->warning('Twilio SMS webhook signature validation failed', [
            'urls_tried' => $this->webhookUrlCandidates(),
            'payload_keys' => array_keys($payload),
        ]);

        return false;
    }

    /**
     * @return list<string>
     */
    private function webhookUrlCandidates(): array
    {
        $candidates = [];

        $configured = trim((string) ($_ENV['TWILIO_SMS_WEBHOOK_URL'] ?? ''));
        if ($configured !== '') {
            $candidates[] = $configured;
            $candidates[] = rtrim($configured, '/');
        }

        $appUrl = rtrim(trim((string) ($_ENV['APP_URL'] ?? '')), '/');
        if ($appUrl !== '') {
            $candidates[] = $appUrl . '/api/v1/twilio/sms/inbound';
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $uri = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?') ?: '/api/v1/twilio/sms/inbound';
        $candidates[] = $scheme . '://' . $host . $uri;
        $candidates[] = rtrim($scheme . '://' . $host . $uri, '/');

        return array_values(array_unique(array_filter($candidates)));
    }

    private function resolveWebhookUrl(): string
    {
        $candidates = $this->webhookUrlCandidates();
        return $candidates[0] ?? 'https://fwapi.medicalcontractor.ca/api/v1/twilio/sms/inbound';
    }
}
