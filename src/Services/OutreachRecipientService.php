<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use Doctrine\DBAL\Connection;
use Monolog\Logger;

/**
 * Shared recipient status updates + outreach event log.
 */
class OutreachRecipientService
{
    public function __construct(private readonly Logger $logger)
    {
    }

    private function conn(): Connection
    {
        return (new Database())->getConnection();
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    public function logEvent(
        int $campaignId,
        ?int $recipientId,
        string $source,
        string $eventType,
        ?array $payload = null,
    ): void {
        try {
            $this->conn()->executeStatement(
                'INSERT INTO fw_outreach_events (campaign_id, recipient_id, source, event_type, payload_json)
                 VALUES (?, ?, ?, ?, ?)',
                [
                    $campaignId,
                    $recipientId,
                    $source,
                    substr($eventType, 0, 64),
                    $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->error('Failed to log outreach event', [
                'campaign_id' => $campaignId,
                'recipient_id' => $recipientId,
                'event_type' => $eventType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Apply terminal / progress status. Returns false if recipient missing or no-op.
     *
     * @param array<string, mixed>|null $eventPayload
     */
    public function applyStatus(
        int $recipientId,
        string $status,
        string $source,
        ?string $responseNote = null,
        ?string $errorMessage = null,
        ?array $eventPayload = null,
        ?string $eventType = null,
    ): bool {
        $allowed = ['sent', 'replied', 'declined', 'unsubscribed', 'no_response', 'failed', 'skipped'];
        if (!in_array($status, $allowed, true)) {
            return false;
        }

        $conn = $this->conn();
        $row = $conn->executeQuery(
            'SELECT * FROM fw_outreach_recipients WHERE id = ? LIMIT 1',
            [$recipientId]
        )->fetchAssociative();
        if (!$row) {
            return false;
        }

        $current = (string) ($row['status'] ?? '');
        // Do not downgrade terminal engagement statuses.
        $terminal = ['replied', 'declined', 'unsubscribed'];
        if (in_array($current, $terminal, true) && $status !== $current) {
            if (in_array($status, ['sent', 'no_response'], true)) {
                return false;
            }
        }

        $waitHours = (int) $conn->executeQuery(
            'SELECT wait_hours FROM fw_outreach_campaigns WHERE id = ?',
            [(int) $row['campaign_id']]
        )->fetchOne();

        $sets = ['status = ?'];
        $params = [$status];
        if ($status === 'sent') {
            $sets[] = 'sent_at = NOW()';
            $sets[] = 'wait_until = DATE_ADD(NOW(), INTERVAL ? HOUR)';
            $params[] = max(1, $waitHours ?: 72);
        }
        if (in_array($status, ['replied', 'declined', 'unsubscribed'], true)) {
            $sets[] = 'responded_at = NOW()';
        }
        if ($responseNote !== null && $responseNote !== '') {
            $sets[] = 'response_note = ?';
            $params[] = substr($responseNote, 0, 512);
        }
        if ($errorMessage !== null && $errorMessage !== '') {
            $sets[] = 'error_message = ?';
            $params[] = substr($errorMessage, 0, 512);
        }
        $params[] = $recipientId;
        $conn->executeStatement(
            'UPDATE fw_outreach_recipients SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        );

        $this->recomputeCampaignCounts((int) $row['campaign_id']);
        $this->logEvent(
            (int) $row['campaign_id'],
            $recipientId,
            $source,
            $eventType ?? $status,
            $eventPayload ?? ['status' => $status, 'note' => $responseNote]
        );

        return true;
    }

    public function recomputeCampaignCounts(int $campaignId): void
    {
        $conn = $this->conn();
        $c = $conn->executeQuery(
            "SELECT
                SUM(status = 'queued') AS total_queued,
                SUM(status = 'sent') AS total_sent,
                SUM(status = 'replied') AS total_replied,
                SUM(status = 'declined') AS total_declined,
                SUM(status = 'unsubscribed') AS total_unsubscribed,
                SUM(status = 'no_response') AS total_no_response,
                SUM(status = 'failed') AS total_failed,
                SUM(status = 'skipped') AS total_skipped
             FROM fw_outreach_recipients WHERE campaign_id = ?",
            [$campaignId]
        )->fetchAssociative() ?: [];

        $conn->executeStatement(
            'UPDATE fw_outreach_campaigns SET
                total_queued = ?, total_sent = ?, total_replied = ?, total_declined = ?,
                total_unsubscribed = ?, total_no_response = ?, total_failed = ?, total_skipped = ?
             WHERE id = ?',
            [
                (int) ($c['total_queued'] ?? 0),
                (int) ($c['total_sent'] ?? 0),
                (int) ($c['total_replied'] ?? 0),
                (int) ($c['total_declined'] ?? 0),
                (int) ($c['total_unsubscribed'] ?? 0),
                (int) ($c['total_no_response'] ?? 0),
                (int) ($c['total_failed'] ?? 0),
                (int) ($c['total_skipped'] ?? 0),
                $campaignId,
            ]
        );
        if ((int) ($c['total_queued'] ?? 0) === 0 && (int) ($c['total_sent'] ?? 0) === 0) {
            $conn->executeStatement(
                "UPDATE fw_outreach_campaigns SET status = 'done', finished_at = NOW() WHERE id = ? AND status = 'running'",
                [$campaignId]
            );
        } elseif ((int) ($c['total_queued'] ?? 0) === 0) {
            $conn->executeStatement(
                "UPDATE fw_outreach_campaigns SET status = 'paused' WHERE id = ? AND status = 'running'",
                [$campaignId]
            );
        }
    }

    /**
     * Expire sent recipients past wait_until. Returns number updated.
     *
     * @return array{updated: int, campaign_ids: list<int>}
     */
    public function expireWaiting(?int $campaignId = null): array
    {
        $conn = $this->conn();
        $sql = "SELECT id, campaign_id FROM fw_outreach_recipients
                WHERE status = 'sent' AND wait_until IS NOT NULL AND wait_until < NOW()";
        $params = [];
        if ($campaignId !== null) {
            $sql .= ' AND campaign_id = ?';
            $params[] = $campaignId;
        }
        $rows = $conn->executeQuery($sql, $params)->fetchAllAssociative();
        $campaignIds = [];
        foreach ($rows as $row) {
            $rid = (int) $row['id'];
            $cid = (int) $row['campaign_id'];
            $conn->executeStatement(
                "UPDATE fw_outreach_recipients SET status = 'no_response' WHERE id = ? AND status = 'sent'",
                [$rid]
            );
            $this->logEvent($cid, $rid, 'system', 'no_response', ['reason' => 'wait_hours_elapsed']);
            $campaignIds[$cid] = true;
        }
        foreach (array_keys($campaignIds) as $cid) {
            $this->recomputeCampaignCounts((int) $cid);
        }

        return [
            'updated' => count($rows),
            'campaign_ids' => array_map('intval', array_keys($campaignIds)),
        ];
    }

    public function findById(int $recipientId): ?array
    {
        $row = $this->conn()->executeQuery(
            'SELECT * FROM fw_outreach_recipients WHERE id = ? LIMIT 1',
            [$recipientId]
        )->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * Find latest awaiting SMS outreach recipient by phone (E.164 or digits).
     */
    public function findAwaitingSmsByPhone(string $e164OrRaw): ?array
    {
        $digits = preg_replace('/\D+/', '', $e164OrRaw) ?? '';
        if ($digits === '') {
            return null;
        }
        $e164 = strlen($digits) === 10 ? '+1' . $digits : ('+' . ltrim($digits, '+'));
        $variants = array_values(array_unique([$e164OrRaw, $e164, $digits, '+1' . substr($digits, -10)]));

        $ph = implode(',', array_fill(0, count($variants), '?'));
        $row = $this->conn()->executeQuery(
            "SELECT * FROM fw_outreach_recipients
             WHERE channel = 'sms'
               AND status = 'sent'
               AND destination IN ($ph)
             ORDER BY id DESC
             LIMIT 1",
            $variants
        )->fetchAssociative();

        return is_array($row) ? $row : null;
    }
}
