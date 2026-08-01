<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use App\Services\BoldSignService;
use Flight;
use Monolog\Logger;
use Throwable;

class BoldSignController
{
    public function __construct(
        private readonly Logger $logger,
        private readonly BoldSignService $boldSign,
    ) {
    }

    /** GET /api/v1/esign/status — safe to call without API key. */
    public function status(): void
    {
        $base = $this->boldSign->status();
        $ping = null;
        if ($base['configured']) {
            $ping = $this->boldSign->ping();
        }

        Flight::json([
            'success' => true,
            'data' => [
                'provider' => 'boldsign',
                'configured' => $base['configured'],
                'sandbox' => $base['sandbox'],
                'base_url' => $base['base_url'],
                'has_webhook_secret' => $base['has_webhook_secret'],
                'ready' => $base['configured'] && ($ping['ok'] ?? false),
                'ping' => $ping,
                'setup_hint' => $base['configured']
                    ? null
                    : 'Create a free BoldSign API sandbox, copy the API key into BOLDSIGN_API_KEY, then call GET /api/v1/esign/status again.',
            ],
        ]);
    }

    /** POST /api/v1/esign/send — send PDF for one signer. */
    public function send(): void
    {
        if (!$this->boldSign->isConfigured()) {
            Flight::json([
                'success' => false,
                'message' => 'BoldSign is not configured yet. Set BOLDSIGN_API_KEY in the API environment.',
            ], 503);
            return;
        }

        $data = Flight::request()->data->getData();
        if (!is_array($data)) {
            $data = [];
        }

        $title = trim((string) ($data['title'] ?? ''));
        $signerName = trim((string) ($data['signer_name'] ?? ''));
        $signerEmail = trim((string) ($data['signer_email'] ?? ''));
        $message = isset($data['message']) ? trim((string) $data['message']) : null;
        $projectId = isset($data['project_id']) ? (int) $data['project_id'] : null;
        if ($projectId !== null && $projectId <= 0) {
            $projectId = null;
        }

        $fileName = trim((string) ($data['file_name'] ?? 'document.pdf'));
        if ($fileName === '' || !str_ends_with(strtolower($fileName), '.pdf')) {
            $fileName = 'document.pdf';
        }

        $fileBase64 = (string) ($data['file_base64'] ?? '');
        if ($title === '' || $signerName === '' || $signerEmail === '' || $fileBase64 === '') {
            Flight::json([
                'success' => false,
                'message' => 'Required: title, signer_name, signer_email, file_base64 (PDF).',
            ], 400);
            return;
        }
        if (!filter_var($signerEmail, FILTER_VALIDATE_EMAIL)) {
            Flight::json(['success' => false, 'message' => 'Invalid signer_email.'], 400);
            return;
        }

        $binary = base64_decode($fileBase64, true);
        if ($binary === false || strlen($binary) < 100) {
            Flight::json(['success' => false, 'message' => 'Invalid file_base64 PDF payload.'], 400);
            return;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'bsign_');
        if ($tmp === false) {
            Flight::json(['success' => false, 'message' => 'Could not create temp file.'], 500);
            return;
        }
        $tmpPdf = $tmp . '.pdf';
        @unlink($tmp);
        if (file_put_contents($tmpPdf, $binary) === false) {
            Flight::json(['success' => false, 'message' => 'Could not write temp PDF.'], 500);
            return;
        }

        try {
            $sent = $this->boldSign->sendDocument($tmpPdf, $title, $signerName, $signerEmail, $message);
            $userId = $this->currentUserId();

            $conn = Database::getConnection();
            $conn->insert('fw_esign_envelopes', [
                'project_id' => $projectId,
                'created_by_user_id' => $userId > 0 ? $userId : null,
                'boldsign_document_id' => $sent['documentId'],
                'title' => $title,
                'status' => 'sent',
                'signer_email' => $signerEmail,
                'signer_name' => $signerName,
                'source_file_name' => $fileName,
                'meta_json' => json_encode(['boldsign_raw' => $sent['raw']], JSON_UNESCAPED_UNICODE),
                'last_event' => 'Send',
            ]);
            $localId = (int) $conn->lastInsertId();

            Flight::json([
                'success' => true,
                'data' => [
                    'id' => $localId,
                    'boldsign_document_id' => $sent['documentId'],
                    'status' => 'sent',
                    'sandbox' => $this->boldSign->isSandbox(),
                ],
            ], 201);
        } catch (Throwable $e) {
            $this->logger->error('BoldSign send failed', ['error' => $e->getMessage()]);
            Flight::json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 502);
        } finally {
            @unlink($tmpPdf);
        }
    }

    /** GET /api/v1/esign/envelopes — list local records. */
    public function listEnvelopes(): void
    {
        $projectId = isset($_GET['project_id']) ? (int) $_GET['project_id'] : 0;
        $conn = Database::getConnection();
        $qb = $conn->createQueryBuilder()
            ->select('*')
            ->from('fw_esign_envelopes')
            ->orderBy('id', 'DESC')
            ->setMaxResults(100);
        if ($projectId > 0) {
            $qb->andWhere('project_id = :pid')->setParameter('pid', $projectId);
        }
        $rows = $qb->executeQuery()->fetchAllAssociative();

        Flight::json([
            'success' => true,
            'data' => [
                'envelopes' => array_map([$this, 'mapEnvelopeRow'], $rows),
            ],
        ]);
    }

    /** GET /api/v1/esign/envelopes/@id */
    public function getEnvelope(string $id): void
    {
        $localId = (int) $id;
        if ($localId <= 0) {
            Flight::json(['success' => false, 'message' => 'Invalid id.'], 400);
            return;
        }
        $conn = Database::getConnection();
        $row = $conn->fetchAssociative('SELECT * FROM fw_esign_envelopes WHERE id = ?', [$localId]);
        if (!$row) {
            Flight::json(['success' => false, 'message' => 'Envelope not found.'], 404);
            return;
        }

        $remote = null;
        if ($this->boldSign->isConfigured() && !empty($row['boldsign_document_id'])) {
            try {
                $remote = $this->boldSign->getDocumentProperties((string) $row['boldsign_document_id']);
                $status = $this->normalizeRemoteStatus($remote);
                if ($status !== '' && $status !== (string) $row['status']) {
                    $conn->update('fw_esign_envelopes', [
                        'status' => $status,
                        'last_event' => 'PropertiesSync',
                    ], ['id' => $localId]);
                    $row['status'] = $status;
                }
            } catch (Throwable $e) {
                $this->logger->warning('BoldSign properties sync failed', ['error' => $e->getMessage()]);
            }
        }

        Flight::json([
            'success' => true,
            'data' => [
                'envelope' => $this->mapEnvelopeRow($row),
                'boldsign' => $remote,
            ],
        ]);
    }

    /** POST /api/v1/esign/webhook — BoldSign → us (no JWT). */
    public function webhook(): void
    {
        $raw = file_get_contents('php://input') ?: '';
        $sig = $_SERVER['HTTP_X_BOLDSIGN_SIGNATURE']
            ?? $_SERVER['HTTP_X_BOLDSIGN_SIGNED']
            ?? null;

        if (!$this->boldSign->verifyWebhookSignature($raw, is_string($sig) ? $sig : null)) {
            $this->logger->warning('BoldSign webhook signature rejected');
            Flight::halt(403, 'Forbidden');
            return;
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            Flight::json(['success' => false, 'message' => 'Invalid JSON'], 400);
            return;
        }

        $event = (string) ($payload['event']['eventType'] ?? $payload['eventType'] ?? $payload['EventType'] ?? '');
        $docId = (string) (
            $payload['data']['documentId']
            ?? $payload['documentId']
            ?? $payload['DocumentId']
            ?? ''
        );

        $this->logger->info('BoldSign webhook', [
            'event' => $event,
            'document_id' => $docId,
        ]);

        if ($docId !== '') {
            try {
                $conn = Database::getConnection();
                $row = $conn->fetchAssociative(
                    'SELECT id, status FROM fw_esign_envelopes WHERE boldsign_document_id = ?',
                    [$docId],
                );
                if ($row) {
                    $status = $this->mapWebhookEventToStatus($event, (string) $row['status']);
                    $fields = [
                        'last_event' => $event !== '' ? $event : 'Webhook',
                        'last_webhook_at' => date('Y-m-d H:i:s'),
                        'meta_json' => json_encode(['last_webhook' => $payload], JSON_UNESCAPED_UNICODE),
                    ];
                    if ($status !== '') {
                        $fields['status'] = $status;
                        if (strtolower($status) === 'completed') {
                            $fields['completed_at'] = date('Y-m-d H:i:s');
                        }
                    }
                    $conn->update('fw_esign_envelopes', $fields, ['id' => (int) $row['id']]);
                }
            } catch (Throwable $e) {
                $this->logger->error('BoldSign webhook DB update failed', ['error' => $e->getMessage()]);
            }
        }

        Flight::json(['success' => true]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapEnvelopeRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'project_id' => isset($row['project_id']) ? (int) $row['project_id'] : null,
            'created_by_user_id' => isset($row['created_by_user_id']) ? (int) $row['created_by_user_id'] : null,
            'boldsign_document_id' => (string) ($row['boldsign_document_id'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'signer_email' => (string) ($row['signer_email'] ?? ''),
            'signer_name' => (string) ($row['signer_name'] ?? ''),
            'source_file_name' => (string) ($row['source_file_name'] ?? ''),
            'last_event' => $row['last_event'] ?? null,
            'last_webhook_at' => $row['last_webhook_at'] ?? null,
            'completed_at' => $row['completed_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $remote
     */
    private function normalizeRemoteStatus(array $remote): string
    {
        $s = (string) ($remote['status'] ?? $remote['Status'] ?? '');
        return strtolower($s);
    }

    private function mapWebhookEventToStatus(string $event, string $fallback): string
    {
        $e = strtolower($event);
        return match (true) {
            str_contains($e, 'completed') => 'completed',
            str_contains($e, 'declined') => 'declined',
            str_contains($e, 'revoked') || str_contains($e, 'expired') => 'voided',
            str_contains($e, 'signed') => 'signed',
            str_contains($e, 'viewed') || str_contains($e, 'delivery') => 'delivered',
            str_contains($e, 'sent') || str_contains($e, 'send') => 'sent',
            default => $fallback,
        };
    }

    private function currentUserId(): int
    {
        $user = Flight::get('user');
        if (is_array($user) && isset($user['id'])) {
            return (int) $user['id'];
        }
        return 0;
    }
}
