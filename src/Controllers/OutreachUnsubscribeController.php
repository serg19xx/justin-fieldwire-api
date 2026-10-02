<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\OutreachRecipientService;
use App\Support\OutreachEmailShell;
use Flight;
use Monolog\Logger;

class OutreachUnsubscribeController
{
    public function __construct(
        private readonly Logger $logger,
        private readonly OutreachRecipientService $recipients,
    ) {
    }

    /** GET|POST /api/v1/outreach/unsubscribe?token= */
    public function handle(): void
    {
        $token = trim((string) (Flight::request()->query->token ?? ''));
        if ($token === '') {
            $data = Flight::request()->data->getData();
            if (is_array($data)) {
                $token = trim((string) ($data['token'] ?? ''));
            }
        }

        $recipientId = OutreachEmailShell::parseUnsubscribeToken($token);
        if ($recipientId === null) {
            $this->htmlPage(400, 'Invalid unsubscribe link', 'This unsubscribe link is invalid or expired.');

            return;
        }

        $row = $this->recipients->findById($recipientId);
        if ($row === null) {
            $this->htmlPage(404, 'Not found', 'We could not find this subscription record.');

            return;
        }

        $this->recipients->applyStatus(
            $recipientId,
            'unsubscribed',
            'link',
            'Unsubscribed via email link',
            null,
            ['via' => 'footer_link'],
            'unsubscribe_link',
        );

        $this->logger->info('Outreach unsubscribe via link', ['recipient_id' => $recipientId]);
        $this->htmlPage(200, 'Unsubscribed', 'You have been unsubscribed from FieldWire outreach emails.');
    }

    private function htmlPage(int $code, string $title, string $message): void
    {
        http_response_code($code);
        header('Content-Type: text/html; charset=utf-8');
        $t = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $m = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo "<!DOCTYPE html><html><head><meta charset=\"utf-8\"><title>{$t}</title></head>"
            . "<body style=\"font-family:Arial,sans-serif;padding:40px;max-width:520px;margin:auto;\">"
            . "<h1>{$t}</h1><p>{$m}</p></body></html>";
        Flight::stop();
    }
}
