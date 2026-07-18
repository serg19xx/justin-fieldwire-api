<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\EmailService;
use App\Services\HumbleFaxService;
use App\Services\TwilioService;
use App\Support\ClientCommsTestRedirect;
use App\Support\ClientRegistryContacts;
use Flight;
use Monolog\Logger;

class ClientCommunicationController
{
    private const ALLOWED_ROLES = ['admin', 'project_manager'];

    public function __construct(
        private readonly Logger $logger,
        private readonly TwilioService $twilioService,
        private readonly EmailService $emailService,
        private readonly HumbleFaxService $humbleFaxService,
    ) {
    }

    public function sendSms(string $type, int $id): void
    {
        if (!$this->authorize()) {
            return;
        }

        $payload = $this->parsePayload(requireMessage: true);
        if ($payload === null) {
            return;
        }

        $row = $this->loadClientOrFail($type, $id);
        if ($row === null) {
            return;
        }

        $phone = ClientRegistryContacts::resolvePhone($type, $row);
        if ($phone === null) {
            $this->respondError('Client has no phone number on file', 400);
            return;
        }

        $redirect = ClientCommsTestRedirect::phone($phone);
        $sent = $this->twilioService->sendSms($redirect['destination'], $payload['message']);
        if (!$sent) {
            $this->respondError('Failed to send SMS', 502);
            return;
        }

        $this->respondSuccess('SMS sent', [
            'channel' => 'sms',
            'client_type' => $type,
            'client_id' => $id,
            'client_name' => ClientRegistryContacts::displayName($type, $row),
            'sent_to' => $redirect['destination'],
            'original_to' => $redirect['original'],
            'test_mode' => $redirect['test_mode'],
        ]);
    }

    public function listDynamicTemplates(): void
    {
        if (!$this->authorize()) {
            return;
        }

        try {
            $templates = $this->emailService->listActiveDynamicTemplates();
            Flight::json([
                'status' => 'success',
                'data' => [
                    'templates' => $templates,
                    'sendgrid_configured' => $this->emailService->isSendGridAvailable(),
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to list SendGrid dynamic templates', [
                'error' => $e->getMessage(),
            ]);
            $this->respondError('Failed to load SendGrid templates', 502);
        }
    }

    public function sendEmail(string $type, int $id): void
    {
        if (!$this->authorize()) {
            return;
        }

        $payload = $this->parseEmailPayload();
        if ($payload === null) {
            return;
        }

        $row = $this->loadClientOrFail($type, $id);
        if ($row === null) {
            return;
        }

        $email = ClientRegistryContacts::resolveEmail($row);
        if ($email === null) {
            $this->respondError('Client has no email on file', 400);
            return;
        }

        $redirect = ClientCommsTestRedirect::email($email);
        $clientName = ClientRegistryContacts::displayName($type, $row);
        $sent = $this->dispatchClientEmail($redirect['destination'], $clientName, $payload);

        if (!$sent) {
            $this->respondError('Failed to send email', 502);
            return;
        }

        $this->respondSuccess('Email sent', [
            'channel' => 'email',
            'client_type' => $type,
            'client_id' => $id,
            'client_name' => $clientName,
            'sent_to' => $redirect['destination'],
            'original_to' => $redirect['original'],
            'test_mode' => $redirect['test_mode'],
            'template_id' => $payload['template_id'] !== '' ? $payload['template_id'] : null,
        ]);
    }

    public function sendFax(string $type, int $id): void
    {
        if (!$this->authorize()) {
            return;
        }

        if (!$this->humbleFaxService->isConfigured()) {
            $this->respondError('Fax service is not configured', 503);
            return;
        }

        $payload = $this->parseFaxPayload();
        if ($payload === null) {
            return;
        }

        $row = $this->loadClientOrFail($type, $id);
        if ($row === null) {
            return;
        }

        $fax = ClientRegistryContacts::resolveFax($type, $row);
        if ($fax === null) {
            $this->respondError('Client has no fax number on file', 400);
            return;
        }

        $redirect = ClientCommsTestRedirect::fax($fax);
        $recipient = $this->humbleFaxService->formatRecipientNumber($redirect['destination']);
        if ($recipient === null) {
            $this->respondError('Invalid fax number format', 400);
            return;
        }

        $clientName = ClientRegistryContacts::displayName($type, $row);
        $hasCoverContent = $payload['message'] !== '' || $payload['subject'] !== '';
        $subject = $payload['subject'] !== ''
            ? $payload['subject']
            : ($hasCoverContent ? 'Message from FieldWire' : '');

        $storedAttachment = $payload['attachment_path'];
        try {
            $result = $this->humbleFaxService->sendFax(
                [$recipient],
                [
                    'toName' => $clientName,
                    'subject' => $subject,
                    'message' => $payload['message'],
                    'includeCoversheet' => $hasCoverContent,
                ],
                $storedAttachment,
                $payload['attachment_name'],
            );
        } finally {
            if ($storedAttachment !== null && is_file($storedAttachment)) {
                @unlink($storedAttachment);
            }
        }

        if (!$result['success']) {
            $this->respondError($result['error'] ?? 'Failed to send fax', 502);
            return;
        }

        $this->respondSuccess('Fax queued', [
            'channel' => 'fax',
            'client_type' => $type,
            'client_id' => $id,
            'client_name' => $clientName,
            'sent_to' => (string) $recipient,
            'original_to' => $redirect['original'],
            'test_mode' => $redirect['test_mode'],
            'tmp_fax_id' => $result['tmp_fax_id'] ?? null,
        ]);
    }

    public function sendBulkSms(string $type): void
    {
        if (!$this->authorize()) {
            return;
        }

        if (!ClientRegistryContacts::isAllowedType($type)) {
            $this->respondError('Invalid client type', 400);
            return;
        }

        $payload = $this->parsePayload(requireMessage: true);
        if ($payload === null) {
            return;
        }

        $ids = $this->parseIdList();
        if ($ids === null) {
            return;
        }

        $results = [];
        $sent = 0;
        $failed = 0;

        foreach ($ids as $id) {
            $row = ClientRegistryContacts::fetchRow($type, $id);
            if ($row === null) {
                $results[] = ['id' => $id, 'success' => false, 'error' => 'Not found'];
                $failed++;
                continue;
            }

            $phone = ClientRegistryContacts::resolvePhone($type, $row);
            if ($phone === null) {
                $results[] = ['id' => $id, 'success' => false, 'error' => 'No phone'];
                $failed++;
                continue;
            }

            $redirect = ClientCommsTestRedirect::phone($phone);
            $ok = $this->twilioService->sendSms($redirect['destination'], $payload['message']);
            if ($ok) {
                $sent++;
                $results[] = [
                    'id' => $id,
                    'success' => true,
                    'sent_to' => $redirect['destination'],
                    'original_to' => $redirect['original'],
                    'test_mode' => $redirect['test_mode'],
                ];
            } else {
                $failed++;
                $results[] = ['id' => $id, 'success' => false, 'error' => 'Send failed'];
            }
        }

        $this->respondSuccess('Bulk SMS completed', [
            'channel' => 'sms',
            'sent' => $sent,
            'failed' => $failed,
            'results' => $results,
            'test_mode' => ClientCommsTestRedirect::isEnabled(),
        ]);
    }

    public function sendBulkEmail(string $type): void
    {
        if (!$this->authorize()) {
            return;
        }

        if (!ClientRegistryContacts::isAllowedType($type)) {
            $this->respondError('Invalid client type', 400);
            return;
        }

        $payload = $this->parseEmailPayload();
        if ($payload === null) {
            return;
        }

        $ids = $this->parseIdList();
        if ($ids === null) {
            return;
        }

        $results = [];
        $sent = 0;
        $failed = 0;

        foreach ($ids as $id) {
            $row = ClientRegistryContacts::fetchRow($type, $id);
            if ($row === null) {
                $results[] = ['id' => $id, 'success' => false, 'error' => 'Not found'];
                $failed++;
                continue;
            }

            $email = ClientRegistryContacts::resolveEmail($row);
            if ($email === null) {
                $results[] = ['id' => $id, 'success' => false, 'error' => 'No email'];
                $failed++;
                continue;
            }

            $redirect = ClientCommsTestRedirect::email($email);
            $ok = $this->dispatchClientEmail(
                $redirect['destination'],
                ClientRegistryContacts::displayName($type, $row),
                $payload,
            );

            if ($ok) {
                $sent++;
                $results[] = [
                    'id' => $id,
                    'success' => true,
                    'sent_to' => $redirect['destination'],
                    'original_to' => $redirect['original'],
                    'test_mode' => $redirect['test_mode'],
                ];
            } else {
                $failed++;
                $results[] = ['id' => $id, 'success' => false, 'error' => 'Send failed'];
            }
        }

        $this->respondSuccess('Bulk email completed', [
            'channel' => 'email',
            'sent' => $sent,
            'failed' => $failed,
            'results' => $results,
            'test_mode' => ClientCommsTestRedirect::isEnabled(),
            'template_id' => $payload['template_id'] !== '' ? $payload['template_id'] : null,
        ]);
    }

    /**
     * @param array{message: string, subject: string, template_id: string} $payload
     */
    private function dispatchClientEmail(string $destination, string $clientName, array $payload): bool
    {
        if ($payload['template_id'] !== '') {
            return $this->emailService->sendDynamicTemplateEmail(
                $destination,
                $clientName,
                $payload['template_id'],
                $this->buildTemplateData($clientName, $payload),
            );
        }

        $subject = $payload['subject'] !== '' ? $payload['subject'] : 'Message from FieldWire';

        return $this->emailService->sendEmail(
            $destination,
            $subject,
            $payload['message'],
            $clientName,
            'sendgrid',
        );
    }

    /**
     * @param array{message: string, subject: string} $payload
     * @return array<string, string>
     */
    private function buildTemplateData(string $clientName, array $payload): array
    {
        $data = [
            'client_name' => $clientName,
            'name' => $clientName,
            'recipient_name' => $clientName,
        ];

        if ($payload['message'] !== '') {
            $data['message'] = $payload['message'];
            $data['body'] = $payload['message'];
        }

        if ($payload['subject'] !== '') {
            $data['subject'] = $payload['subject'];
        }

        return $data;
    }

    /**
     * @return array{message: string, subject: string, template_id: string}|null
     */
    private function parseEmailPayload(): ?array
    {
        $body = Flight::request()->data->getData();
        if (!is_array($body)) {
            $body = json_decode(Flight::request()->getBody(), true);
        }
        if (!is_array($body)) {
            $body = [];
        }

        $message = trim((string) ($body['message'] ?? ''));
        $subject = trim((string) ($body['subject'] ?? ''));
        $templateId = trim((string) ($body['template_id'] ?? ''));

        if ($templateId !== '' && !preg_match('/^d-[a-f0-9]+$/', $templateId)) {
            $this->respondError('Invalid template_id', 400);
            return null;
        }

        if ($templateId === '' && $message === '') {
            $this->respondError('Message is required', 400);
            return null;
        }

        return [
            'message' => $message,
            'subject' => $subject,
            'template_id' => $templateId,
        ];
    }

    private function authorize(): bool
    {
        $user = Flight::get('current_user');
        if (!$user) {
            $this->respondError('Unauthorized', 401);
            return false;
        }

        $role = strtolower((string) ($user['role_code'] ?? ''));
        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            $this->respondError('Forbidden', 403);
            return false;
        }

        return true;
    }

    /**
     * @return array{message: string, subject: string}|null
     */
    private function parsePayload(bool $requireMessage, bool $allowSubject = false): ?array
    {
        $body = Flight::request()->data->getData();
        if (!is_array($body)) {
            $body = json_decode(Flight::request()->getBody(), true);
        }
        if (!is_array($body)) {
            $body = [];
        }

        $message = trim((string) ($body['message'] ?? ''));
        $subject = trim((string) ($body['subject'] ?? ''));

        if ($requireMessage && $message === '') {
            $this->respondError('Message is required', 400);
            return null;
        }

        if (!$allowSubject) {
            $subject = '';
        }

        return ['message' => $message, 'subject' => $subject];
    }

    /**
     * @return array{
     *     message: string,
     *     subject: string,
     *     attachment_path: string|null,
     *     attachment_name: string|null
     * }|null
     */
    private function parseFaxPayload(): ?array
    {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($contentType, 'multipart/form-data')) {
            $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
            if ($contentLength > 0 && $_POST === [] && $_FILES === []) {
                $this->respondError(
                    'Upload failed: request exceeds server limit (post_max_size ' . ini_get('post_max_size') . ')',
                    413,
                );
                return null;
            }
        }

        $message = trim((string) ($_POST['message'] ?? ''));
        $subject = trim((string) ($_POST['subject'] ?? ''));

        if ($message === '' && $subject === '' && !isset($_FILES['attachment'])) {
            $body = Flight::request()->data->getData();
            if (!is_array($body)) {
                $body = json_decode(Flight::request()->getBody(), true);
            }
            if (is_array($body)) {
                $message = trim((string) ($body['message'] ?? ''));
                $subject = trim((string) ($body['subject'] ?? ''));
            }
        }

        $attachmentPath = null;
        $attachmentName = null;
        if (isset($_FILES['attachment']) && is_array($_FILES['attachment'])) {
            $uploadError = (int) ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($uploadError === UPLOAD_ERR_OK) {
                $validationError = $this->validateFaxAttachment($_FILES['attachment']);
                if ($validationError !== null) {
                    $this->respondError($validationError, 400);
                    return null;
                }

                $stored = $this->storeFaxAttachment($_FILES['attachment']);
                if ($stored === null) {
                    $this->respondError('Failed to store fax attachment', 500);
                    return null;
                }

                $attachmentPath = $stored['path'];
                $attachmentName = $stored['name'];
            } elseif ($uploadError !== UPLOAD_ERR_NO_FILE) {
                $this->respondError($this->mapUploadError($uploadError), 400);
                return null;
            }
        }

        if ($message === '' && $attachmentPath === null) {
            $this->respondError('Cover text or attachment is required', 400);
            return null;
        }

        return [
            'message' => $message,
            'subject' => $subject,
            'attachment_path' => $attachmentPath,
            'attachment_name' => $attachmentName,
        ];
    }

    /**
     * @param array<string, mixed> $file
     * @return array{path: string, name: string}|null
     */
    private function storeFaxAttachment(array $file): ?array
    {
        $tmpPath = (string) ($file['tmp_name'] ?? '');
        $originalName = trim((string) ($file['name'] ?? 'document.pdf'));
        if ($originalName === '') {
            $originalName = 'document.pdf';
        }

        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($originalName));
        if ($safeName === '') {
            $safeName = 'document.pdf';
        }

        $storedPath = sys_get_temp_dir() . '/fw_fax_' . uniqid('', true) . '_' . $safeName;
        if (!move_uploaded_file($tmpPath, $storedPath)) {
            return null;
        }

        return [
            'path' => $storedPath,
            'name' => $safeName,
        ];
    }

    /**
     * @param array<string, mixed> $file
     */
    private function validateFaxAttachment(array $file): ?string
    {
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            return 'Attachment file is empty';
        }

        if ($size > 50 * 1024 * 1024) {
            return 'Maximum attachment size is 50 MB';
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            return 'Invalid attachment upload';
        }

        $mimeType = mime_content_type($tmpPath) ?: '';
        $allowed = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/tiff',
            'image/bmp',
            'image/webp',
        ];

        if (!in_array($mimeType, $allowed, true)) {
            return 'Attachment must be PDF or an image (JPEG, PNG, GIF, TIFF, BMP, WebP)';
        }

        return null;
    }

    private function mapUploadError(int $code): string
    {
        $phpLimit = ini_get('upload_max_filesize') ?: 'unknown';

        return match ($code) {
            UPLOAD_ERR_INI_SIZE => 'Attachment exceeds PHP upload limit (' . $phpLimit . '). Max fax attachment is 50 MB.',
            UPLOAD_ERR_FORM_SIZE => 'Attachment exceeds maximum upload size (50 MB)',
            UPLOAD_ERR_PARTIAL => 'Attachment was only partially uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Server is missing a temporary folder for uploads',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write attachment to disk',
            UPLOAD_ERR_EXTENSION => 'A server extension blocked the attachment upload',
            default => 'Attachment upload failed',
        };
    }

    /**
     * @return int[]|null
     */
    private function parseIdList(): ?array
    {
        $body = Flight::request()->data->getData();
        if (!is_array($body)) {
            $body = json_decode(Flight::request()->getBody(), true);
        }
        if (!is_array($body)) {
            $body = [];
        }

        $ids = $body['ids'] ?? null;
        if (!is_array($ids) || $ids === []) {
            $this->respondError('ids array is required', 400);
            return null;
        }

        $parsed = [];
        foreach ($ids as $id) {
            $n = (int) $id;
            if ($n > 0) {
                $parsed[] = $n;
            }
        }

        if ($parsed === []) {
            $this->respondError('No valid ids provided', 400);
            return null;
        }

        return array_values(array_unique($parsed));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadClientOrFail(string $type, int $id): ?array
    {
        if (!ClientRegistryContacts::isAllowedType($type)) {
            $this->respondError('Invalid client type', 400);
            return null;
        }

        if ($id <= 0) {
            $this->respondError('Invalid client id', 400);
            return null;
        }

        $row = ClientRegistryContacts::fetchRow($type, $id);
        if ($row === null) {
            $this->respondError('Client not found', 404);
            return null;
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function respondSuccess(string $message, array $data = []): void
    {
        Flight::json([
            'error_code' => 0,
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ]);
    }

    private function respondError(string $message, int $httpStatus): void
    {
        Flight::json([
            'error_code' => $httpStatus,
            'status' => 'error',
            'message' => $message,
            'data' => null,
        ], $httpStatus);
    }
}
