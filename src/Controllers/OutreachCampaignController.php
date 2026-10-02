<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use App\Services\OutreachRecipientService;
use App\Support\ClientRegistryContacts;
use App\Support\OutreachEmailShell;
use App\Support\PhoneNormalizer;
use Doctrine\DBAL\Connection;
use Flight;
use Monolog\Logger;

/**
 * Collaboration invitation outreach.
 * One shared queue (fw_outreach_recipients) for all 4 client types; client_type marks source table.
 * Channel: email if present, otherwise SMS.
 */
class OutreachCampaignController
{
    private Logger $logger;
    private Database $database;
    private OutreachRecipientService $recipientService;

    private const CLIENT_TYPES = ['pharma', 'physician', 'pharmacist', 'medical_clinic'];

    private const EMAIL_MODES = ['custom', 'sendgrid_full', 'sendgrid_body'];

    private const RECIPIENT_STATUSES = [
        'queued', 'sent', 'replied', 'declined', 'unsubscribed', 'no_response', 'failed', 'skipped',
    ];

    /** Max messages queued/sent per manual Start (one wave). */
    private const MAX_SEND_PER_WAVE = 50;

    private const DEFAULT_EMAIL_SUBJECT = 'Invitation to collaborate with Medical Contractor / FieldWire';

    private const DEFAULT_EMAIL_BODY = "Hello {{client_name}},\n\nWe would like to invite you to collaborate with our team through FieldWire.\n\nPlease reply to this email if you are interested, or reply DECLINE if you prefer not to be contacted.\n\nThank you,\nMedical Contractor Team";

    private const DEFAULT_SMS_BODY = 'Hi {{client_name}}: Medical Contractor invites you to collaborate via FieldWire. Reply YES if interested, STOP to opt out.';

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->database = new Database();
        $this->recipientService = new OutreachRecipientService($logger);
    }

    private function conn(): Connection
    {
        return $this->database->getConnection();
    }

    private function currentUser(): ?array
    {
        $user = Flight::get('current_user');

        return is_array($user) ? $user : null;
    }

    private function requireStaff(): ?array
    {
        $user = $this->currentUser();
        $role = strtolower((string) ($user['role_code'] ?? ''));
        if ($user === null || !in_array($role, ['admin', 'project_manager'], true)) {
            Flight::json(['error_code' => 403, 'status' => 'error', 'message' => 'Forbidden', 'data' => null], 403);

            return null;
        }

        return $user;
    }

    private function requireOutreachSecret(): bool
    {
        $expected = trim((string) ($_ENV['OUTREACH_N8N_SECRET'] ?? getenv('OUTREACH_N8N_SECRET') ?: ''));
        if ($expected === '') {
            Flight::json(['error_code' => 503, 'status' => 'error', 'message' => 'Outreach secret not configured', 'data' => null], 503);

            return false;
        }
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $got = '';
        foreach ($headers as $k => $v) {
            if (strcasecmp((string) $k, 'X-Outreach-Secret') === 0) {
                $got = (string) $v;
                break;
            }
        }
        if ($got === '' || !hash_equals($expected, $got)) {
            Flight::json(['error_code' => 401, 'status' => 'error', 'message' => 'Invalid outreach secret', 'data' => null], 401);

            return false;
        }

        return true;
    }

    private function requestData(): array
    {
        $data = Flight::request()->data->getData();
        if (!is_array($data) || $data === []) {
            $raw = Flight::request()->getBody();
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            $data = is_array($decoded) ? $decoded : [];
        }

        return $data;
    }

    private function nullableString(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    /** GET /api/v1/outreach/campaigns?client_type= */
    public function listCampaigns(): void
    {
        if ($this->requireStaff() === null) {
            return;
        }
        try {
            $type = trim((string) (Flight::request()->query->client_type ?? ''));
            $params = [];
            $sql = 'SELECT * FROM fw_outreach_campaigns';
            if ($type !== '' && in_array($type, self::CLIENT_TYPES, true)) {
                $sql .= ' WHERE client_type = ?';
                $params[] = $type;
            }
            $sql .= ' ORDER BY id DESC LIMIT 50';
            $rows = $this->conn()->executeQuery($sql, $params)->fetchAllAssociative();
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Campaigns',
                'data' => ['campaigns' => array_map([$this, 'formatCampaign'], $rows)],
            ]);
        } catch (\Throwable $e) {
            $this->fail($e, 'listCampaigns');
        }
    }

    /** GET /api/v1/outreach/campaigns/@id */
    public function getCampaign(int $id): void
    {
        if ($this->requireStaff() === null) {
            return;
        }
        try {
            $row = $this->findCampaign($id);
            if ($row === null) {
                return;
            }
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Campaign',
                'data' => ['campaign' => $this->formatCampaign($row)],
            ]);
        } catch (\Throwable $e) {
            $this->fail($e, 'getCampaign');
        }
    }

    /** GET /api/v1/outreach/campaigns/@id/recipients */
    public function listRecipients(int $id): void
    {
        if ($this->requireStaff() === null) {
            return;
        }
        try {
            $status = trim((string) (Flight::request()->query->status ?? ''));
            $page = max(1, (int) (Flight::request()->query->page ?? 1));
            $limit = min(max(1, (int) (Flight::request()->query->limit ?? 50)), 500);
            $offset = ($page - 1) * $limit;

            $where = ['campaign_id = ?'];
            $params = [$id];
            if ($status !== '' && in_array($status, self::RECIPIENT_STATUSES, true)) {
                $where[] = 'status = ?';
                $params[] = $status;
            }
            $whereSql = implode(' AND ', $where);
            $total = (int) $this->conn()->executeQuery(
                "SELECT COUNT(*) FROM fw_outreach_recipients WHERE $whereSql",
                $params
            )->fetchOne();
            $rows = $this->conn()->executeQuery(
                "SELECT * FROM fw_outreach_recipients WHERE $whereSql ORDER BY id DESC LIMIT $limit OFFSET $offset",
                $params
            )->fetchAllAssociative();

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Recipients',
                'data' => [
                    'recipients' => array_map([$this, 'formatRecipient'], $rows),
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total' => $total,
                        'pages' => $limit > 0 ? (int) ceil($total / $limit) : 1,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            $this->fail($e, 'listRecipients');
        }
    }

    /** GET /api/v1/outreach/campaigns/@id/events */
    public function listEvents(int $id): void
    {
        if ($this->requireStaff() === null) {
            return;
        }
        try {
            if ($this->findCampaign($id) === null) {
                return;
            }
            $eventType = trim((string) (Flight::request()->query->event_type ?? ''));
            $page = max(1, (int) (Flight::request()->query->page ?? 1));
            $limit = min(max(1, (int) (Flight::request()->query->limit ?? 50)), 200);
            $offset = ($page - 1) * $limit;

            $where = ['e.campaign_id = ?'];
            $params = [$id];
            if ($eventType !== '') {
                $where[] = 'e.event_type = ?';
                $params[] = $eventType;
            }
            $whereSql = implode(' AND ', $where);
            $total = (int) $this->conn()->executeQuery(
                "SELECT COUNT(*) FROM fw_outreach_events e WHERE $whereSql",
                $params
            )->fetchOne();
            $rows = $this->conn()->executeQuery(
                "SELECT e.*, r.client_name, r.destination, r.channel, r.status AS recipient_status
                 FROM fw_outreach_events e
                 LEFT JOIN fw_outreach_recipients r ON r.id = e.recipient_id
                 WHERE $whereSql
                 ORDER BY e.id DESC
                 LIMIT $limit OFFSET $offset",
                $params
            )->fetchAllAssociative();

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Events',
                'data' => [
                    'events' => array_map([$this, 'formatEvent'], $rows),
                    'pagination' => [
                        'page' => $page,
                        'limit' => $limit,
                        'total' => $total,
                        'pages' => $limit > 0 ? (int) ceil($total / $limit) : 1,
                    ],
                ],
            ]);
        } catch (\Throwable $e) {
            $this->fail($e, 'listEvents');
        }
    }

    /**
     * POST /api/v1/outreach/campaigns
     * Requires country and/or region. Optional: category, specialty, batch_size, wait_hours, start.
     */
    public function createCampaign(): void
    {
        $user = $this->requireStaff();
        if ($user === null) {
            return;
        }
        try {
            $data = $this->requestData();
            $type = trim((string) ($data['client_type'] ?? ''));
            if (!in_array($type, self::CLIENT_TYPES, true)) {
                Flight::json(['error_code' => 400, 'status' => 'error', 'message' => 'Invalid client_type', 'data' => null], 400);

                return;
            }
            $country = $this->nullableString($data['country'] ?? null);
            $region = $this->nullableString($data['region'] ?? null);
            if ($country === null && $region === null) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'Location required: set country and/or region',
                    'data' => null,
                ], 400);

                return;
            }

            $category = $this->nullableString($data['category'] ?? $data['sub_type'] ?? $data['clinicType'] ?? null);
            $specialty = $this->nullableString($data['specialty'] ?? null);
            // One Start = one wave of at most 50 messages (manual waves, auto-pause after).
            $batchSize = max(1, min(self::MAX_SEND_PER_WAVE, (int) ($data['batch_size'] ?? 50)));
            $waitHours = max(1, min(720, (int) ($data['wait_hours'] ?? 72)));
            $name = $this->nullableString($data['name'] ?? null)
                ?? sprintf('%s outreach %s', $type, date('Y-m-d H:i'));

            $emailMode = $this->normalizeEmailMode((string) ($data['email_mode'] ?? 'custom'));
            $emailSubject = $this->nullableString($data['email_subject'] ?? null);
            $emailBody = $this->nullableString($data['email_body'] ?? null);
            $sendgridTemplateId = $this->nullableString($data['sendgrid_template_id'] ?? null);
            $smsBody = $this->nullableString($data['sms_body'] ?? null) ?? self::DEFAULT_SMS_BODY;

            if ($emailMode === 'custom') {
                if ($emailSubject === null || $emailSubject === '') {
                    Flight::json([
                        'error_code' => 400,
                        'status' => 'error',
                        'message' => 'email_subject is required for custom mode',
                        'data' => null,
                    ], 400);

                    return;
                }
                if ($emailBody === null || $emailBody === '') {
                    Flight::json([
                        'error_code' => 400,
                        'status' => 'error',
                        'message' => 'email_body is required for custom mode',
                        'data' => null,
                    ], 400);

                    return;
                }
            } elseif ($emailMode === 'sendgrid_full') {
                if ($sendgridTemplateId === null || $sendgridTemplateId === '') {
                    Flight::json([
                        'error_code' => 400,
                        'status' => 'error',
                        'message' => 'sendgrid_template_id is required for sendgrid_full',
                        'data' => null,
                    ], 400);

                    return;
                }
            } elseif ($emailMode === 'sendgrid_body') {
                if ($sendgridTemplateId === null || $sendgridTemplateId === '') {
                    Flight::json([
                        'error_code' => 400,
                        'status' => 'error',
                        'message' => 'sendgrid_template_id is required for sendgrid_body',
                        'data' => null,
                    ], 400);

                    return;
                }
                if ($emailBody === null || $emailBody === '') {
                    Flight::json([
                        'error_code' => 400,
                        'status' => 'error',
                        'message' => 'email_body is required for sendgrid_body (fills template {{{body}}})',
                        'data' => null,
                    ], 400);

                    return;
                }
                if ($emailSubject === null || $emailSubject === '') {
                    $emailSubject = self::DEFAULT_EMAIL_SUBJECT;
                }
            }

            $audience = $this->buildAudience($type, $country, $region, $category, $specialty);
            if ($audience === []) {
                Flight::json([
                    'error_code' => 400,
                    'status' => 'error',
                    'message' => 'No contacts match filters with email or phone',
                    'data' => null,
                ], 400);

                return;
            }

            $wave = array_slice($audience, 0, $batchSize);

            $conn = $this->conn();
            $conn->beginTransaction();
            try {
                $conn->executeStatement(
                    'INSERT INTO fw_outreach_campaigns
                     (client_type, name, status, country, region, category, specialty, batch_size, wait_hours,
                      email_mode, email_subject, email_body, sendgrid_template_id, sms_body,
                      filters_json, total_queued, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $type,
                        $name,
                        'draft',
                        $country,
                        $region,
                        $category,
                        $specialty,
                        $batchSize,
                        $waitHours,
                        $emailMode,
                        $emailSubject,
                        $emailBody,
                        $sendgridTemplateId,
                        $smsBody,
                        json_encode([
                            'country' => $country,
                            'region' => $region,
                            'category' => $category,
                            'specialty' => $specialty,
                        ], JSON_UNESCAPED_UNICODE),
                        count($wave),
                        isset($user['id']) ? (int) $user['id'] : null,
                    ]
                );
                $campaignId = (int) $conn->lastInsertId();
                $this->insertRecipients($conn, $campaignId, $type, $wave);
                $conn->commit();
            } catch (\Throwable $e) {
                $conn->rollBack();
                throw $e;
            }

            if (!empty($data['start']) || !empty($data['auto_start'])) {
                $this->startCampaignInternal($campaignId);
            }

            $row = $conn->executeQuery(
                'SELECT * FROM fw_outreach_campaigns WHERE id = ?',
                [$campaignId]
            )->fetchAssociative();

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Campaign created',
                'data' => ['campaign' => $this->formatCampaign($row ?: [])],
            ], 201);
        } catch (\Throwable $e) {
            $this->fail($e, 'createCampaign');
        }
    }

    /** POST /api/v1/outreach/campaigns/@id/start */
    public function startCampaign(int $id): void
    {
        if ($this->requireStaff() === null) {
            return;
        }
        try {
            if (!$this->startCampaignInternal($id)) {
                return;
            }
            $row = $this->findCampaign($id);
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Campaign started',
                'data' => ['campaign' => $this->formatCampaign($row ?: [])],
            ]);
        } catch (\Throwable $e) {
            $this->fail($e, 'startCampaign');
        }
    }

    /** POST /api/v1/outreach/campaigns/@id/pause */
    public function pauseCampaign(int $id): void
    {
        if ($this->requireStaff() === null) {
            return;
        }
        try {
            $n = $this->conn()->executeStatement(
                "UPDATE fw_outreach_campaigns SET status = 'paused' WHERE id = ? AND status = 'running'",
                [$id]
            );
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => $n > 0 ? 'Campaign paused' : 'Nothing to pause',
                'data' => ['paused' => $n > 0],
            ]);
        } catch (\Throwable $e) {
            $this->fail($e, 'pauseCampaign');
        }
    }

    /** GET /api/v1/outreach/campaigns/@id/batch — n8n claims queued rows */
    public function claimBatch(int $id): void
    {
        if (!$this->requireOutreachSecret()) {
            return;
        }
        try {
            $conn = $this->conn();
            $campaign = $this->findCampaign($id, false);
            if ($campaign === null) {
                return;
            }
            if (($campaign['status'] ?? '') !== 'running') {
                Flight::json([
                    'error_code' => 0,
                    'status' => 'success',
                    'message' => 'Campaign not running',
                    'data' => ['recipients' => [], 'campaign_status' => $campaign['status']],
                ]);

                return;
            }

            $limit = min(
                max(1, (int) (Flight::request()->query->limit ?? $campaign['batch_size'] ?? self::MAX_SEND_PER_WAVE)),
                min(self::MAX_SEND_PER_WAVE, (int) ($campaign['batch_size'] ?? self::MAX_SEND_PER_WAVE))
            );

            $batchNo = 0;
            $ids = [];
            $conn->beginTransaction();
            try {
                $rows = $conn->executeQuery(
                    "SELECT * FROM fw_outreach_recipients
                     WHERE campaign_id = ? AND status = 'queued'
                     ORDER BY id ASC LIMIT $limit FOR UPDATE",
                    [$id]
                )->fetchAllAssociative();
                $batchNo = (int) $conn->executeQuery(
                    'SELECT COALESCE(MAX(batch_no), 0) + 1 FROM fw_outreach_recipients WHERE campaign_id = ?',
                    [$id]
                )->fetchOne();
                foreach ($rows as $r) {
                    $ids[] = (int) $r['id'];
                }
                if ($ids !== []) {
                    $ph = implode(',', array_fill(0, count($ids), '?'));
                    $conn->executeStatement(
                        "UPDATE fw_outreach_recipients SET batch_no = ? WHERE id IN ($ph)",
                        array_merge([$batchNo], $ids)
                    );
                }
                $conn->commit();
            } catch (\Throwable $e) {
                $conn->rollBack();
                throw $e;
            }

            $out = [];
            if ($ids !== []) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $out = $conn->executeQuery(
                    "SELECT * FROM fw_outreach_recipients WHERE id IN ($ph) ORDER BY id ASC",
                    $ids
                )->fetchAllAssociative();
            }

            $left = (int) $conn->executeQuery(
                "SELECT COUNT(*) FROM fw_outreach_recipients WHERE campaign_id = ? AND status = 'queued'",
                [$id]
            )->fetchOne();
            // Empty claim: wave finished — pause so client must click Start for next wave.
            if ($left === 0 && $out === []) {
                $conn->executeStatement(
                    "UPDATE fw_outreach_campaigns SET status = 'paused' WHERE id = ? AND status = 'running'",
                    [$id]
                );
            }

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Batch claimed',
                'data' => [
                    'campaign_id' => $id,
                    'client_type' => $campaign['client_type'] ?? null,
                    'batch_no' => $out !== [] ? $batchNo : null,
                    'wait_hours' => (int) ($campaign['wait_hours'] ?? 72),
                    'wave_mode' => true,
                    'email_mode' => $this->normalizeEmailMode((string) ($campaign['email_mode'] ?? 'custom')),
                    'email_subject' => $campaign['email_subject'] ?? self::DEFAULT_EMAIL_SUBJECT,
                    'email_body' => $campaign['email_body'] ?? self::DEFAULT_EMAIL_BODY,
                    'sendgrid_template_id' => $campaign['sendgrid_template_id'] ?? null,
                    'sms_body' => $campaign['sms_body'] ?? self::DEFAULT_SMS_BODY,
                    'recipients' => array_map(
                        fn (array $r) => $this->formatRecipientForSend($r, $campaign),
                        $out
                    ),
                    'queued_remaining' => $left,
                ],
            ]);
        } catch (\Throwable $e) {
            $this->fail($e, 'claimBatch');
        }
    }

    /** POST /api/v1/outreach/recipients/@id/status — n8n */
    public function updateRecipientStatus(int $id): void
    {
        if (!$this->requireOutreachSecret()) {
            return;
        }
        try {
            $data = $this->requestData();
            $status = trim((string) ($data['status'] ?? ''));
            if (!in_array($status, self::RECIPIENT_STATUSES, true) || $status === 'queued') {
                Flight::json(['error_code' => 400, 'status' => 'error', 'message' => 'Invalid status', 'data' => null], 400);

                return;
            }
            $note = $this->nullableString($data['response_note'] ?? null);
            $err = $this->nullableString($data['error_message'] ?? null);
            $ok = $this->recipientService->applyStatus(
                $id,
                $status,
                'system',
                $note,
                $err,
                ['via' => 'n8n_status'],
                $status === 'sent' ? 'sent' : $status,
            );
            if (!$ok) {
                Flight::json(['error_code' => 404, 'status' => 'error', 'message' => 'Recipient not found or status not applied', 'data' => null], 404);

                return;
            }
            $fresh = $this->recipientService->findById($id);

            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Recipient updated',
                'data' => ['recipient' => $this->formatRecipient($fresh ?: [])],
            ]);
        } catch (\Throwable $e) {
            $this->fail($e, 'updateRecipientStatus');
        }
    }

    /** POST /api/v1/outreach/campaigns/@id/expire-waiting — n8n */
    public function expireWaiting(int $id): void
    {
        if (!$this->requireOutreachSecret()) {
            return;
        }
        try {
            $result = $this->recipientService->expireWaiting($id);
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Expired waiting recipients',
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            $this->fail($e, 'expireWaiting');
        }
    }

    /** POST /api/v1/outreach/expire-waiting-all — n8n hourly */
    public function expireWaitingAll(): void
    {
        if (!$this->requireOutreachSecret()) {
            return;
        }
        try {
            $result = $this->recipientService->expireWaiting(null);
            Flight::json([
                'error_code' => 0,
                'status' => 'success',
                'message' => 'Expired waiting recipients (all campaigns)',
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            $this->fail($e, 'expireWaitingAll');
        }
    }

    private function startCampaignInternal(int $id): bool
    {
        $conn = $this->conn();
        $row = $this->findCampaign($id, false);
        if ($row === null) {
            return false;
        }
        if (!in_array($row['status'], ['draft', 'paused', 'done'], true)) {
            Flight::json([
                'error_code' => 400,
                'status' => 'error',
                'message' => 'Campaign cannot be started from status ' . $row['status'],
                'data' => null,
            ], 400);

            return false;
        }

        // Next Start on paused/done: enqueue another wave (up to batch_size) of not-yet-queued contacts.
        if (in_array($row['status'], ['paused', 'done'], true)) {
            $added = $this->enqueueNextWave($id, $row);
            if ($added === 0) {
                $queued = (int) $conn->executeQuery(
                    "SELECT COUNT(*) FROM fw_outreach_recipients WHERE campaign_id = ? AND status = 'queued'",
                    [$id]
                )->fetchOne();
                if ($queued === 0) {
                    Flight::json([
                        'error_code' => 400,
                        'status' => 'error',
                        'message' => 'No more contacts left to send for these filters',
                        'data' => null,
                    ], 400);

                    return false;
                }
            }
            $row = $this->findCampaign($id, false) ?: $row;
        }

        $conn->executeStatement(
            "UPDATE fw_outreach_campaigns SET status = 'running', started_at = COALESCE(started_at, NOW()), finished_at = NULL, last_error = NULL WHERE id = ?",
            [$id]
        );

        $webhook = trim((string) ($_ENV['OUTREACH_N8N_WEBHOOK_URL'] ?? getenv('OUTREACH_N8N_WEBHOOK_URL') ?: ''));
        if ($webhook === '') {
            $this->logger->warning('OUTREACH_N8N_WEBHOOK_URL not set', ['campaign_id' => $id]);

            return true;
        }

            $payload = json_encode([
                'event' => 'outreach.campaign.start',
                'campaign_id' => $id,
                'client_type' => $row['client_type'],
                'batch_size' => (int) $row['batch_size'],
                'wait_hours' => (int) $row['wait_hours'],
                'wave_mode' => true,
                'email_mode' => $row['email_mode'] ?? 'custom',
                'email_subject' => $row['email_subject'] ?? self::DEFAULT_EMAIL_SUBJECT,
                'sendgrid_template_id' => $row['sendgrid_template_id'] ?? null,
            ], JSON_THROW_ON_ERROR);

        $ch = curl_init($webhook);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Outreach-Secret: ' . (string) ($_ENV['OUTREACH_N8N_SECRET'] ?? ''),
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);
        if ($body === false || $code >= 400) {
            $err = $cerr !== '' ? $cerr : ('HTTP ' . $code);
            $conn->executeStatement(
                'UPDATE fw_outreach_campaigns SET last_error = ? WHERE id = ?',
                [substr($err, 0, 1000), $id]
            );
            $this->logger->error('n8n outreach webhook failed', ['campaign_id' => $id, 'error' => $err]);
        }

        return true;
    }

    /**
     * @return list<array{client_id:int, client_name:string, channel:string, destination:string}>
     */
    private function buildAudience(
        string $type,
        ?string $country,
        ?string $region,
        ?string $category,
        ?string $specialty,
    ): array {
        $params = [];
        if ($type === 'pharmacist') {
            $where = [];
            if ($country !== null) {
                $where[] = 'pa.country = ?';
                $params[] = $country;
            }
            if ($region !== null) {
                $where[] = 'pa.region = ?';
                $params[] = $region;
            }
            $whereSql = $where !== [] ? ('WHERE ' . implode(' AND ', $where)) : '';
            $sql = "SELECT pp.id, pp.fullName AS name, pp.email, pp.cell_phone AS phone
                    FROM fw_pharmacist pp
                    LEFT JOIN fw_pharma pa ON pa.id = pp.pharmId
                    $whereSql";
        } else {
            $table = match ($type) {
                'pharma' => 'fw_pharma',
                'physician' => 'fw_physician',
                'medical_clinic' => 'fw_medical_clinic',
                default => '',
            };
            if ($table === '') {
                return [];
            }
            $where = [];
            if ($country !== null) {
                $where[] = 'country = ?';
                $params[] = $country;
            }
            if ($region !== null) {
                $where[] = 'region = ?';
                $params[] = $region;
            }
            if ($type === 'pharma' && $category !== null) {
                $where[] = 'sub_type = ?';
                $params[] = $category;
            }
            if ($type === 'medical_clinic' && $category !== null) {
                $where[] = 'clinicType = ?';
                $params[] = $category;
            }
            if ($type === 'physician' && $specialty !== null) {
                $where[] = 'specialty = ?';
                $params[] = $specialty;
            }
            $nameCol = match ($type) {
                'pharma' => 'operName',
                'physician' => 'fullName',
                'medical_clinic' => 'clinicName',
                default => 'id',
            };
            $phoneSelect = match ($type) {
                'pharma' => "COALESCE(NULLIF(TRIM(cell), ''), NULLIF(TRIM(phone), '')) AS phone",
                'physician' => "COALESCE(NULLIF(TRIM(cellPhone), ''), NULLIF(TRIM(officePhone), '')) AS phone",
                'medical_clinic' => "NULLIF(TRIM(phone), '') AS phone",
                default => 'NULL AS phone',
            };
            $whereSql = $where !== [] ? ('WHERE ' . implode(' AND ', $where)) : '';
            $sql = "SELECT id, `$nameCol` AS name, email, $phoneSelect FROM `$table` $whereSql";
        }

        $rows = $this->conn()->executeQuery($sql, $params)->fetchAllAssociative();
        $out = [];
        foreach ($rows as $row) {
            $email = ClientRegistryContacts::resolveEmail($row);
            $phoneRaw = trim((string) ($row['phone'] ?? ''));
            $phone = $phoneRaw !== '' ? PhoneNormalizer::toE164($phoneRaw) : null;
            if ($email !== null) {
                $channel = 'email';
                $destination = $email;
            } elseif ($phone !== null) {
                $channel = 'sms';
                $destination = $phone;
            } else {
                continue;
            }
            $out[] = [
                'client_id' => (int) $row['id'],
                'client_name' => trim((string) ($row['name'] ?? '')) ?: ('Client #' . $row['id']),
                'channel' => $channel,
                'destination' => $destination,
            ];
        }

        return $out;
    }

    private function recomputeCampaignCounts(int $campaignId): void
    {
        $this->recipientService->recomputeCampaignCounts($campaignId);
    }

    /**
     * @param list<array{client_id:int, client_name:string, channel:string, destination:string}> $items
     */
    private function insertRecipients(Connection $conn, int $campaignId, string $type, array $items): void
    {
        foreach ($items as $item) {
            $conn->executeStatement(
                'INSERT INTO fw_outreach_recipients
                 (campaign_id, client_type, client_id, client_name, channel, destination, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $campaignId,
                    $type,
                    $item['client_id'],
                    $item['client_name'],
                    $item['channel'],
                    $item['destination'],
                    'queued',
                ]
            );
        }
    }

    /**
     * Enqueue next wave of contacts not already in this campaign.
     *
     * @param array<string, mixed> $campaign
     */
    private function enqueueNextWave(int $campaignId, array $campaign): int
    {
        $type = (string) ($campaign['client_type'] ?? '');
        $batchSize = max(1, min(self::MAX_SEND_PER_WAVE, (int) ($campaign['batch_size'] ?? 50)));
        $audience = $this->buildAudience(
            $type,
            $this->nullableString($campaign['country'] ?? null),
            $this->nullableString($campaign['region'] ?? null),
            $this->nullableString($campaign['category'] ?? null),
            $this->nullableString($campaign['specialty'] ?? null),
        );
        if ($audience === []) {
            return 0;
        }

        $conn = $this->conn();
        $existing = $conn->executeQuery(
            'SELECT client_id FROM fw_outreach_recipients WHERE campaign_id = ?',
            [$campaignId]
        )->fetchFirstColumn();
        $have = [];
        foreach ($existing as $cid) {
            $have[(int) $cid] = true;
        }
        $fresh = [];
        foreach ($audience as $item) {
            if (!isset($have[$item['client_id']])) {
                $fresh[] = $item;
                if (count($fresh) >= $batchSize) {
                    break;
                }
            }
        }
        if ($fresh === []) {
            return 0;
        }
        $this->insertRecipients($conn, $campaignId, $type, $fresh);
        $this->recomputeCampaignCounts($campaignId);

        return count($fresh);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $campaign
     * @return array<string, mixed>
     */
    private function formatRecipientForSend(array $row, array $campaign): array
    {
        $base = $this->formatRecipient($row);
        $recipientId = (int) ($base['id'] ?? 0);
        $vars = [
            'client_name' => (string) ($base['client_name'] ?? 'there'),
            'destination' => (string) ($base['destination'] ?? ''),
            'client_type' => (string) ($base['client_type'] ?? ''),
            'unsubscribe_url' => $recipientId > 0 ? OutreachEmailShell::unsubscribeUrl($recipientId) : '',
            'company_name' => trim((string) ($_ENV['OUTREACH_COMPANY_NAME'] ?? 'Medical Contractor')) ?: 'Medical Contractor',
        ];
        $mode = $this->normalizeEmailMode((string) ($campaign['email_mode'] ?? 'custom'));
        $subjectTpl = (string) ($campaign['email_subject'] ?? self::DEFAULT_EMAIL_SUBJECT);
        $bodyTpl = (string) ($campaign['email_body'] ?? self::DEFAULT_EMAIL_BODY);
        $smsTpl = (string) ($campaign['sms_body'] ?? self::DEFAULT_SMS_BODY);

        $base['email_mode'] = $mode;
        $base['sendgrid_template_id'] = $campaign['sendgrid_template_id'] ?? null;
        $base['email_subject'] = OutreachEmailShell::render($subjectTpl, $vars);
        $base['sms_body'] = OutreachEmailShell::render($smsTpl, $vars);
        $base['custom_args'] = [
            'outreach_recipient_id' => (string) $recipientId,
            'campaign_id' => (string) ($base['campaign_id'] ?? ''),
        ];

        if ($mode === 'custom') {
            $base['email_body'] = OutreachEmailShell::wrapBody($bodyTpl, $vars, $recipientId);
            $base['email_body_plain'] = OutreachEmailShell::render($bodyTpl, $vars);
            $base['template_data'] = $vars;
        } elseif ($mode === 'sendgrid_body') {
            $renderedBody = OutreachEmailShell::render($bodyTpl, $vars);
            $base['email_body'] = $renderedBody;
            $base['template_data'] = array_merge($vars, [
                'body' => $renderedBody,
                'subject' => $base['email_subject'],
            ]);
        } else {
            // sendgrid_full — subject/body come from the template; still pass vars.
            $base['email_body'] = null;
            $base['template_data'] = $vars;
        }

        return $base;
    }

    private function normalizeEmailMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if ($mode === 'sendgrid') {
            return 'sendgrid_full';
        }
        if (!in_array($mode, self::EMAIL_MODES, true)) {
            return 'custom';
        }

        return $mode;
    }

    /** @return array<string, mixed>|null */
    private function findCampaign(int $id, bool $send404 = true): ?array
    {
        $row = $this->conn()->executeQuery(
            'SELECT * FROM fw_outreach_campaigns WHERE id = ? LIMIT 1',
            [$id]
        )->fetchAssociative();
        if (!$row) {
            if ($send404) {
                Flight::json(['error_code' => 404, 'status' => 'error', 'message' => 'Campaign not found', 'data' => null], 404);
            }

            return null;
        }

        return $row;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatCampaign(array $row): array
    {
        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'client_type' => $row['client_type'] ?? null,
            'name' => $row['name'] ?? null,
            'status' => $row['status'] ?? null,
            'country' => $row['country'] ?? null,
            'region' => $row['region'] ?? null,
            'category' => $row['category'] ?? null,
            'specialty' => $row['specialty'] ?? null,
            'batch_size' => isset($row['batch_size']) ? (int) $row['batch_size'] : null,
            'wait_hours' => isset($row['wait_hours']) ? (int) $row['wait_hours'] : null,
            'email_mode' => $this->normalizeEmailMode((string) ($row['email_mode'] ?? 'custom')),
            'email_subject' => $row['email_subject'] ?? null,
            'email_body' => $row['email_body'] ?? null,
            'sendgrid_template_id' => $row['sendgrid_template_id'] ?? null,
            'sms_body' => $row['sms_body'] ?? null,
            'total_queued' => (int) ($row['total_queued'] ?? 0),
            'total_sent' => (int) ($row['total_sent'] ?? 0),
            'total_replied' => (int) ($row['total_replied'] ?? 0),
            'total_declined' => (int) ($row['total_declined'] ?? 0),
            'total_unsubscribed' => (int) ($row['total_unsubscribed'] ?? 0),
            'total_no_response' => (int) ($row['total_no_response'] ?? 0),
            'total_failed' => (int) ($row['total_failed'] ?? 0),
            'total_skipped' => (int) ($row['total_skipped'] ?? 0),
            'started_at' => $row['started_at'] ?? null,
            'finished_at' => $row['finished_at'] ?? null,
            'last_error' => $row['last_error'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatRecipient(array $row): array
    {
        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'campaign_id' => isset($row['campaign_id']) ? (int) $row['campaign_id'] : null,
            'client_type' => $row['client_type'] ?? null,
            'client_id' => isset($row['client_id']) ? (int) $row['client_id'] : null,
            'client_name' => $row['client_name'] ?? null,
            'channel' => $row['channel'] ?? null,
            'destination' => $row['destination'] ?? null,
            'status' => $row['status'] ?? null,
            'batch_no' => isset($row['batch_no']) ? (int) $row['batch_no'] : null,
            'sent_at' => $row['sent_at'] ?? null,
            'wait_until' => $row['wait_until'] ?? null,
            'responded_at' => $row['responded_at'] ?? null,
            'response_note' => $row['response_note'] ?? null,
            'error_message' => $row['error_message'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function formatEvent(array $row): array
    {
        $payload = $row['payload_json'] ?? null;
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : $payload;
        }

        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'campaign_id' => isset($row['campaign_id']) ? (int) $row['campaign_id'] : null,
            'recipient_id' => isset($row['recipient_id']) ? (int) $row['recipient_id'] : null,
            'source' => $row['source'] ?? null,
            'event_type' => $row['event_type'] ?? null,
            'payload' => $payload,
            'client_name' => $row['client_name'] ?? null,
            'destination' => $row['destination'] ?? null,
            'channel' => $row['channel'] ?? null,
            'recipient_status' => $row['recipient_status'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ];
    }

    private function fail(\Throwable $e, string $op): void
    {
        $this->logger->error("Outreach $op failed", ['error' => $e->getMessage()]);
        Flight::json(['error_code' => 500, 'status' => 'error', 'message' => $e->getMessage(), 'data' => null], 500);
    }
}
