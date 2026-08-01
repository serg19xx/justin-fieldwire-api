<?php

declare(strict_types=1);

namespace App\Services;

use Monolog\Logger;
use RuntimeException;

/**
 * Thin BoldSign eSignature API client (REST + X-API-KEY).
 * Works without credentials: isConfigured() === false until BOLDSIGN_API_KEY is set.
 *
 * Docs: https://developers.boldsign.com/
 */
class BoldSignService
{
    private string $apiKey;
    private string $baseUrl;
    private string $webhookSecret;
    private bool $sandbox;
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->apiKey = trim((string) ($_ENV['BOLDSIGN_API_KEY'] ?? ''));
        $this->baseUrl = rtrim(
            (string) ($_ENV['BOLDSIGN_API_BASE_URL'] ?? 'https://api.boldsign.com'),
            '/',
        );
        $this->webhookSecret = trim((string) ($_ENV['BOLDSIGN_WEBHOOK_SECRET'] ?? ''));
        $this->sandbox = filter_var($_ENV['BOLDSIGN_SANDBOX'] ?? '1', FILTER_VALIDATE_BOOLEAN);
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '';
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    /**
     * @return array{configured: bool, sandbox: bool, base_url: string, has_webhook_secret: bool}
     */
    public function status(): array
    {
        return [
            'configured' => $this->isConfigured(),
            'sandbox' => $this->sandbox,
            'base_url' => $this->baseUrl,
            'has_webhook_secret' => $this->webhookSecret !== '',
        ];
    }

    /**
     * Soft connectivity check (list brands or properties). Returns null if not configured.
     *
     * @return array{ok: bool, http_status?: int, message: string, raw?: mixed}
     */
    public function ping(): array
    {
        if (!$this->isConfigured()) {
            return [
                'ok' => false,
                'message' => 'BoldSign API key is not configured (set BOLDSIGN_API_KEY).',
            ];
        }

        try {
            // Lightweight authenticated call used across BoldSign accounts.
            $result = $this->request('GET', '/v1/brand/list?PageSize=1');
            $code = (int) ($result['status'] ?? 0);
            if ($code >= 200 && $code < 300) {
                return [
                    'ok' => true,
                    'http_status' => $code,
                    'message' => 'BoldSign API key accepted.',
                    'raw' => $result['body'] ?? null,
                ];
            }

            return [
                'ok' => false,
                'http_status' => $code,
                'message' => 'BoldSign API responded with HTTP ' . $code,
                'raw' => $result['body'] ?? null,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('BoldSign ping failed', ['error' => $e->getMessage()]);
            return [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send a PDF for signature (one signer, signature field on page 1).
     *
     * @return array{documentId: string, raw: mixed}
     */
    public function sendDocument(
        string $absoluteFilePath,
        string $title,
        string $signerName,
        string $signerEmail,
        ?string $message = null,
    ): array {
        if (!$this->isConfigured()) {
            throw new RuntimeException('BoldSign is not configured. Set BOLDSIGN_API_KEY.');
        }
        if (!is_file($absoluteFilePath)) {
            throw new RuntimeException('File not found for BoldSign send.');
        }

        $signerPayload = [
            'name' => $signerName,
            'emailAddress' => $signerEmail,
            'signerType' => 'Signer',
            'signerOrder' => 1,
            'formFields' => [
                [
                    'id' => 'signature_1',
                    'name' => 'Signature',
                    'fieldType' => 'Signature',
                    'pageNumber' => 1,
                    'bounds' => [
                        'x' => 100,
                        'y' => 700,
                        'width' => 200,
                        'height' => 50,
                    ],
                    'isRequired' => true,
                ],
            ],
            'locale' => 'EN',
        ];

        $postFields = [
            'Title' => $title,
            'Message' => $message ?? 'Please review and sign this document.',
            'EnableSigningOrder' => 'false',
            'UseTextTags' => 'false',
            'Signers' => json_encode([$signerPayload], JSON_UNESCAPED_UNICODE),
            'Files' => new \CURLFile($absoluteFilePath, 'application/pdf', basename($absoluteFilePath)),
        ];

        $result = $this->request('POST', '/v1/document/send', $postFields, true);
        $code = (int) ($result['status'] ?? 0);
        $body = $result['body'] ?? null;

        if ($code < 200 || $code >= 300) {
            $msg = is_array($body) ? (string) ($body['error'] ?? $body['message'] ?? json_encode($body)) : (string) $body;
            throw new RuntimeException('BoldSign send failed (HTTP ' . $code . '): ' . $msg);
        }

        $documentId = '';
        if (is_array($body)) {
            $documentId = (string) ($body['documentId'] ?? $body['DocumentId'] ?? '');
        }
        if ($documentId === '') {
            throw new RuntimeException('BoldSign send succeeded but documentId missing in response.');
        }

        return [
            'documentId' => $documentId,
            'raw' => $body,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getDocumentProperties(string $documentId): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('BoldSign is not configured. Set BOLDSIGN_API_KEY.');
        }
        $id = rawurlencode($documentId);
        $result = $this->request('GET', '/v1/document/properties?documentId=' . $id);
        $code = (int) ($result['status'] ?? 0);
        $body = $result['body'] ?? null;
        if ($code < 200 || $code >= 300 || !is_array($body)) {
            throw new RuntimeException('BoldSign get properties failed (HTTP ' . $code . ').');
        }
        return $body;
    }

    /**
     * Verify webhook HMAC when BOLDSIGN_WEBHOOK_SECRET is set.
     * If secret empty and BOLDSIGN_SKIP_WEBHOOK_SIGNATURE=1, accept (local/dev only).
     */
    public function verifyWebhookSignature(string $rawBody, ?string $signatureHeader): bool
    {
        $skip = filter_var($_ENV['BOLDSIGN_SKIP_WEBHOOK_SIGNATURE'] ?? '0', FILTER_VALIDATE_BOOLEAN);
        if ($this->webhookSecret === '') {
            return $skip;
        }
        if ($signatureHeader === null || $signatureHeader === '') {
            return false;
        }

        $computed = base64_encode(hash_hmac('sha256', $rawBody, $this->webhookSecret, true));
        return hash_equals($computed, $signatureHeader);
    }

    /**
     * @param array<string, mixed>|null $formOrJson
     * @return array{status: int, body: mixed}
     */
    private function request(string $method, string $path, ?array $formOrJson = null, bool $multipart = false): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Failed to init cURL for BoldSign.');
        }

        $headers = [
            'Accept: application/json',
            'X-API-KEY: ' . $this->apiKey,
        ];

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($formOrJson !== null) {
            if ($multipart) {
                $opts[CURLOPT_POSTFIELDS] = $formOrJson;
            } else {
                $headers[] = 'Content-Type: application/json';
                $opts[CURLOPT_HTTPHEADER] = $headers;
                $opts[CURLOPT_POSTFIELDS] = json_encode($formOrJson, JSON_UNESCAPED_UNICODE);
            }
        }

        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('BoldSign cURL error: ' . $err);
        }

        $decoded = json_decode($raw, true);
        return [
            'status' => $status,
            'body' => $decoded !== null ? $decoded : $raw,
        ];
    }
}
