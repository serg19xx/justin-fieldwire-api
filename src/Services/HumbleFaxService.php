<?php

declare(strict_types=1);

namespace App\Services;

use Monolog\Logger;

/**
 * HumbleFax REST API client (HTTP Basic Auth).
 * @see https://api.humblefax.com/
 */
class HumbleFaxService
{
    private const BASE_URL = 'https://api.humblefax.com';

    private Logger $logger;
    private string $accessKey;
    private string $secretKey;
    private int $callerId;
    private string $fromName;
    private bool $configured;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->accessKey = trim((string) ($_ENV['HUMBLEFAX_ACCESS_KEY'] ?? ''));
        $this->secretKey = trim((string) ($_ENV['HUMBLEFAX_SECRET_KEY'] ?? ''));
        $this->fromName = trim((string) ($_ENV['HUMBLEFAX_FROM_NAME'] ?? 'FieldWire'));
        $callerRaw = preg_replace('/\D/', '', (string) ($_ENV['HUMBLEFAX_CALLER_ID'] ?? ''));
        $this->callerId = $callerRaw !== '' ? (int) $callerRaw : 0;
        $this->configured = $this->accessKey !== ''
            && $this->secretKey !== ''
            && $this->callerId > 0;

        if (!$this->configured) {
            $this->logger->warning('HumbleFax credentials incomplete', [
                'has_access_key' => $this->accessKey !== '',
                'has_secret_key' => $this->secretKey !== '',
                'caller_id' => $this->callerId,
            ]);
        }
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    /**
     * @param int[] $recipientNumbers Digits only, e.g. [12895551234]
     * @param array<string, mixed> $options toName, subject, message, companyInfo, includeCoversheet, resolution, pageSize
     * @return array{success: bool, tmp_fax_id?: string, data?: array, error?: string}
     */
    public function createTmpFax(array $recipientNumbers, array $options = []): array
    {
        if (!$this->configured) {
            return ['success' => false, 'error' => 'HumbleFax is not configured'];
        }

        $recipients = array_values(array_filter(array_map(
            fn ($n) => $this->formatRecipientNumber($n),
            $recipientNumbers,
        )));

        if ($recipients === []) {
            return ['success' => false, 'error' => 'At least one valid fax recipient is required'];
        }

        $payload = [
            'toName' => (string) ($options['toName'] ?? ''),
            'fromName' => (string) ($options['fromName'] ?? $this->fromName),
            'subject' => (string) ($options['subject'] ?? ''),
            'message' => (string) ($options['message'] ?? ''),
            'companyInfo' => (string) ($options['companyInfo'] ?? ''),
            'fromNumber' => $this->callerId,
            'recipients' => $recipients,
            'resolution' => (string) ($options['resolution'] ?? 'Fine'),
            'pageSize' => (string) ($options['pageSize'] ?? 'Letter'),
            'includeCoversheet' => (bool) ($options['includeCoversheet'] ?? true),
        ];

        if (!empty($options['uuid'])) {
            $payload['uuid'] = (string) $options['uuid'];
        }

        $response = $this->request('POST', '/tmpFax', $payload);
        if (!$response['success']) {
            return $response;
        }

        $tmpFaxId = $this->extractTmpFaxId($response['data'] ?? []);
        if ($tmpFaxId === null) {
            return ['success' => false, 'error' => 'Unexpected HumbleFax response: missing tmpFax id'];
        }

        return ['success' => true, 'tmp_fax_id' => $tmpFaxId, 'data' => $response['data']];
    }

    /**
     * @return array{success: bool, data?: array, error?: string}
     */
    public function uploadAttachment(string $tmpFaxId, string $filePath, ?string $fieldName = null): array
    {
        if (!$this->configured) {
            return ['success' => false, 'error' => 'HumbleFax is not configured'];
        }

        if (!is_readable($filePath)) {
            return ['success' => false, 'error' => 'Attachment file is not readable'];
        }

        $uploadName = $this->normalizeAttachmentFieldName($fieldName, $filePath);
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';

        $curl = curl_init(self::BASE_URL . '/attachment/' . rawurlencode($tmpFaxId));
        if ($curl === false) {
            return ['success' => false, 'error' => 'Failed to initialize upload request'];
        }

        $file = new \CURLFile($filePath, $mimeType, $uploadName);

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->accessKey . ':' . $this->secretKey,
            CURLOPT_POSTFIELDS => [$uploadName => $file],
            CURLOPT_TIMEOUT => 120,
        ]);

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);

        if ($body === false) {
            return ['success' => false, 'error' => $curlError ?: 'Upload failed'];
        }

        $decoded = json_decode($body, true);
        if ($status < 200 || $status >= 300) {
            $this->logger->error('HumbleFax attachment upload failed', [
                'status' => $status,
                'upload_name' => $uploadName,
                'body' => $body,
            ]);
            return [
                'success' => false,
                'error' => is_array($decoded) ? json_encode($decoded) : $body,
            ];
        }

        $this->logger->info('HumbleFax attachment uploaded', [
            'tmp_fax_id' => $tmpFaxId,
            'upload_name' => $uploadName,
            'status' => $status,
        ]);

        return ['success' => true, 'data' => is_array($decoded) ? $decoded : []];
    }

    private function normalizeAttachmentFieldName(?string $fieldName, string $filePath): string
    {
        $candidate = trim((string) $fieldName);
        if ($candidate !== '') {
            $candidate = basename($candidate);
        }

        if ($candidate === '' || !str_contains($candidate, '.')) {
            $extension = pathinfo($filePath, PATHINFO_EXTENSION);
            if ($extension === '') {
                $mimeType = mime_content_type($filePath) ?: '';
                $extension = match ($mimeType) {
                    'application/pdf' => 'pdf',
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/tiff' => 'tif',
                    'image/bmp' => 'bmp',
                    'image/webp' => 'webp',
                    default => 'bin',
                };
            }
            $candidate = $candidate !== '' ? $candidate . '.' . $extension : 'document.' . $extension;
        }

        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $candidate);
        return $safe !== '' ? $safe : 'document.pdf';
    }

    /**
     * @return array{success: bool, data?: array, error?: string}
     */
    public function sendTmpFax(string $tmpFaxId): array
    {
        if (!$this->configured) {
            return ['success' => false, 'error' => 'HumbleFax is not configured'];
        }

        return $this->request('POST', '/tmpFax/' . rawurlencode($tmpFaxId) . '/send');
    }

    /**
     * @param int[] $recipientNumbers
     * @param array<string, mixed> $options
     * @return array{success: bool, tmp_fax_id?: string, data?: array, error?: string}
     */
    public function sendFax(
        array $recipientNumbers,
        array $options = [],
        ?string $attachmentPath = null,
        ?string $attachmentFileName = null,
    ): array {
        $created = $this->createTmpFax($recipientNumbers, $options);
        if (!$created['success']) {
            return $created;
        }

        $tmpFaxId = $created['tmp_fax_id'];

        if ($attachmentPath !== null && $attachmentPath !== '') {
            $upload = $this->uploadAttachment($tmpFaxId, $attachmentPath, $attachmentFileName);
            if (!$upload['success']) {
                return $upload;
            }

            $this->logger->info('HumbleFax proceeding to send after attachment upload', [
                'tmp_fax_id' => $tmpFaxId,
                'attachments_in_upload_response' => count($this->extractTmpAttachments($upload['data'] ?? [])),
            ]);
        }

        $sent = $this->sendTmpFax($tmpFaxId);
        if (!$sent['success'] && $attachmentPath !== null && $attachmentPath !== '') {
            $sent = $this->retrySendAfterAttachmentProcessing($tmpFaxId, $sent);
        }
        if (!$sent['success']) {
            return $sent;
        }

        return [
            'success' => true,
            'tmp_fax_id' => $tmpFaxId,
            'data' => $sent['data'] ?? [],
        ];
    }

    /**
     * HumbleFax blocks on the upload request until validation completes.
     * If send still fails briefly afterward, retry a few times.
     *
     * @param array{success: bool, data?: array, error?: string} $initialResult
     * @return array{success: bool, data?: array, error?: string}
     */
    private function retrySendAfterAttachmentProcessing(string $tmpFaxId, array $initialResult): array
    {
        $last = $initialResult;
        $maxAttempts = 5;

        for ($attempt = 2; $attempt <= $maxAttempts; $attempt++) {
            $this->logger->info('HumbleFax send retry after attachment upload', [
                'tmp_fax_id' => $tmpFaxId,
                'attempt' => $attempt,
                'previous_error' => $last['error'] ?? null,
            ]);
            sleep(2);
            $last = $this->sendTmpFax($tmpFaxId);
            if ($last['success']) {
                return $last;
            }
        }

        return $last;
    }

    /**
     * @return array{success: bool, data?: array, error?: string, status?: int}
     */
    public function getTmpFax(string $tmpFaxId): array
    {
        if (!$this->configured) {
            return ['success' => false, 'error' => 'HumbleFax is not configured'];
        }

        return $this->request('GET', '/tmpFax/' . rawurlencode($tmpFaxId));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function extractTmpAttachments(array $payload): array
    {
        $attachments = [];
        $data = $payload['data'] ?? $payload;
        if (!is_array($data)) {
            return [];
        }

        $tmpFax = $this->extractTmpFaxNode($payload);
        if (is_array($tmpFax)) {
            $fromFax = $tmpFax['tmpAttachments'] ?? $tmpFax['attachments'] ?? [];
            if (is_array($fromFax)) {
                foreach ($fromFax as $attachment) {
                    if (is_array($attachment)) {
                        $attachments[] = $attachment;
                    }
                }
            }
        }

        $single = $data['tmpAttachment'] ?? $data['TmpAttachment'] ?? null;
        if (is_array($single)) {
            $attachments[] = $single;
        }

        return $attachments;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractTmpFaxNode(array $payload): ?array
    {
        $data = $payload['data'] ?? $payload;
        if (!is_array($data)) {
            return null;
        }

        $tmpFax = $data['tmpFax'] ?? $data['TmpFax'] ?? null;
        return is_array($tmpFax) ? $tmpFax : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractTmpFaxId(array $payload): ?string
    {
        $tmpFax = $this->extractTmpFaxNode($payload);
        $id = $tmpFax['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Normalize fax number to integer digits for HumbleFax API (NANP: leading 1).
     */
    public function formatRecipientNumber(string|int $number): ?int
    {
        $digits = preg_replace('/\D/', '', (string) $number);
        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 10) {
            $digits = '1' . $digits;
        }

        if (strlen($digits) < 11 || strlen($digits) > 15) {
            return null;
        }

        return (int) $digits;
    }

    /**
     * @param array<string, mixed>|null $jsonBody
     * @return array{success: bool, data?: array, error?: string, status?: int}
     */
    private function request(string $method, string $path, ?array $jsonBody = null): array
    {
        $url = self::BASE_URL . $path;
        $curl = curl_init($url);
        if ($curl === false) {
            return ['success' => false, 'error' => 'Failed to initialize request'];
        }

        $headers = ['Accept: application/json'];
        if ($jsonBody !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->accessKey . ':' . $this->secretKey,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
        ]);

        if ($jsonBody !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($jsonBody, JSON_THROW_ON_ERROR));
        }

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);

        if ($body === false) {
            return ['success' => false, 'error' => $curlError ?: 'Request failed', 'status' => $status];
        }

        $decoded = json_decode($body, true);

        if ($status < 200 || $status >= 300) {
            $this->logger->error('HumbleFax API error', [
                'method' => $method,
                'path' => $path,
                'status' => $status,
                'body' => $body,
            ]);
            return [
                'success' => false,
                'error' => is_array($decoded) ? json_encode($decoded) : $body,
                'status' => $status,
            ];
        }

        return [
            'success' => true,
            'data' => is_array($decoded) ? $decoded : [],
            'status' => $status,
        ];
    }
}
