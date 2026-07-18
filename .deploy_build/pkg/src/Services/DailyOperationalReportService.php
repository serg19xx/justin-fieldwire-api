<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\ValueObjects\NotificationRequest;
use Doctrine\DBAL\Connection;
use Monolog\Logger;
use Throwable;

/**
 * Daily operational report per Active project (live data only).
 * generate → save → dry-run print or send via NotificationDispatcher.
 */
class DailyOperationalReportService
{
    /** Sentinel project_id for scope=global rows (unique with report_date). */
    public const GLOBAL_PROJECT_ID = 0;

    private Connection $connection;
    private NotificationDispatcher $dispatcher;
    private static ?bool $projectForemanColumnExists = null;
    private static ?bool $snapshotColumnsExist = null;

    public function __construct(
        private readonly Logger $logger,
        ?NotificationDispatcher $dispatcher = null,
    ) {
        $this->connection = Database::getConnection();
        $this->dispatcher = $dispatcher ?? new NotificationDispatcher($logger);
    }

    /**
     * Generate project snapshots, then one global summary.
     * --send emails ONLY the global summary (Admin/PM); project reports stay archive-only.
     *
     * @return array{date: string, dry_run: bool, generated: int, sent: int, failed: int, reports: list<array<string, mixed>>}
     */
    public function run(string $date, bool $dryRun = true, bool $send = false): array
    {
        $date = $this->normalizeDate($date);
        $generated = $this->generateForDate($date);
        $rows = $this->loadReportsForDate($date);

        $sent = 0;
        $failed = 0;
        $previews = [];

        foreach ($rows as $row) {
            $payload = $this->decodePayload($row['payload_json'] ?? null);
            $scope = (string) ($row['scope'] ?? ($payload['scope'] ?? 'project'));
            $text = $this->renderText($payload);
            $previews[] = [
                'id' => (int) $row['id'],
                'project_id' => (int) $row['project_id'],
                'project_name' => (string) ($payload['project_name'] ?? ($scope === 'global' ? 'All projects' : '')),
                'scope' => $scope,
                'status' => (string) $row['status'],
                'text' => $text,
            ];

            // Email only the global summary — project detail stays on the website.
            if ($dryRun || !$send || $scope !== 'global') {
                continue;
            }

            try {
                $this->sendReport((int) $row['id']);
                $sent++;
            } catch (Throwable $e) {
                $failed++;
                $this->logger->error('Failed to send daily operational report', [
                    'report_id' => $row['id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'date' => $date,
            'dry_run' => $dryRun || !$send,
            'generated' => $generated,
            'sent' => $sent,
            'failed' => $failed,
            'reports' => $previews,
        ];
    }

    /** @return int number of reports upserted (projects + global) */
    public function generateForDate(string $date): int
    {
        $date = $this->normalizeDate($date);
        $projects = $this->resolveProjectsForDate($date);
        $count = 0;
        $projectPayloads = [];

        foreach ($projects as $project) {
            $projectId = (int) $project['id'];
            $payload = $this->buildPayload($date, $project);
            $title = sprintf(
                'Daily report %s — %s',
                $date,
                (string) ($payload['project_name'] ?? ('Project #' . $projectId))
            );
            $html = $this->renderHtml($payload);
            $this->upsertReport($date, $projectId, $payload, $title, $html, 'project');
            $projectPayloads[] = $payload;
            $count++;
        }

        $this->generateGlobalForDate($date, $projectPayloads);
        $count++;

        $this->logger->info('Daily operational reports generated', [
            'date' => $date,
            'project_count' => count($projectPayloads),
            'count' => $count,
        ]);

        return $count;
    }

    /**
     * Build global summary from project payloads (same day). Empty projects → empty summary still stored.
     *
     * @param list<array<string, mixed>> $projectPayloads
     */
    public function generateGlobalForDate(string $date, array $projectPayloads = []): void
    {
        $date = $this->normalizeDate($date);
        if ($projectPayloads === []) {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT payload_json FROM fw_operational_daily_reports
                 WHERE report_date = ? AND project_id > 0
                   AND (scope = 'project' OR scope IS NULL OR scope = '')
                 ORDER BY project_id ASC",
                [$date]
            );
            foreach ($rows as $row) {
                $projectPayloads[] = $this->decodePayload($row['payload_json'] ?? null);
            }
        }

        $payload = $this->buildGlobalPayload($date, $projectPayloads);
        $title = sprintf('Daily summary %s — all projects', $date);
        $html = $this->renderHtml($payload);
        $this->upsertReport($date, self::GLOBAL_PROJECT_ID, $payload, $title, $html, 'global');
    }

    /**
     * @param list<array<string, mixed>> $projectPayloads
     * @return array<string, mixed>
     */
    private function buildGlobalPayload(string $date, array $projectPayloads): array
    {
        $totals = [
            'field_work_starts' => 0,
            'field_work_ends' => 0,
            'urgent' => 0,
            'foreman_submitted' => 0,
            'lifecycle_changes' => 0,
            'events_logged' => 0,
        ];
        $projects = [];

        foreach ($projectPayloads as $payload) {
            $counts = is_array($payload['counts'] ?? null) ? $payload['counts'] : [];
            foreach ($totals as $key => $_) {
                $totals[$key] += (int) ($counts[$key] ?? 0);
            }
            $projects[] = [
                'project_id' => (int) ($payload['project_id'] ?? 0),
                'project_name' => (string) ($payload['project_name'] ?? ''),
                'counts' => [
                    'field_work_starts' => (int) ($counts['field_work_starts'] ?? 0),
                    'field_work_ends' => (int) ($counts['field_work_ends'] ?? 0),
                    'urgent' => (int) ($counts['urgent'] ?? 0),
                    'foreman_submitted' => (int) ($counts['foreman_submitted'] ?? 0),
                    'lifecycle_changes' => (int) ($counts['lifecycle_changes'] ?? 0),
                    'events_logged' => (int) ($counts['events_logged'] ?? 0),
                ],
            ];
        }

        return [
            'report_date' => $date,
            'scope' => 'global',
            'project_id' => self::GLOBAL_PROJECT_ID,
            'project_name' => 'All projects',
            'project_count' => count($projects),
            'counts' => $totals,
            'projects' => $projects,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function renderText(array $payload): string
    {
        if (($payload['scope'] ?? '') === 'global') {
            return $this->renderGlobalText($payload);
        }

        $date = (string) ($payload['report_date'] ?? '');
        $name = (string) ($payload['project_name'] ?? 'Project');
        $lines = [];
        $lines[] = "Daily operational report — {$date}";
        $lines[] = "Project: {$name}";
        $lines[] = str_repeat('-', 40);

        $counts = is_array($payload['counts'] ?? null) ? $payload['counts'] : [];
        $lines[] = 'Summary:';
        $lines[] = '  Field work starts: ' . (int) ($counts['field_work_starts'] ?? 0);
        $lines[] = '  Field work ends: ' . (int) ($counts['field_work_ends'] ?? 0);
        $lines[] = '  Urgent: ' . (int) ($counts['urgent'] ?? 0);
        $lines[] = '  Foreman submitted: ' . (int) ($counts['foreman_submitted'] ?? 0);
        $lines[] = '  Lifecycle changes: ' . (int) ($counts['lifecycle_changes'] ?? 0);
        $lines[] = '  Events logged: ' . (int) ($counts['events_logged'] ?? 0);
        $lines[] = '';

        $lines = array_merge($lines, $this->renderSection(
            'Field work started',
            is_array($payload['field_work_started'] ?? null) ? $payload['field_work_started'] : []
        ));
        $lines = array_merge($lines, $this->renderSection(
            'Field work ended',
            is_array($payload['field_work_ended'] ?? null) ? $payload['field_work_ended'] : []
        ));
        $lines = array_merge($lines, $this->renderSection(
            'Urgent',
            is_array($payload['urgent'] ?? null) ? $payload['urgent'] : []
        ));
        $lines = array_merge($lines, $this->renderSection(
            'Foreman submitted',
            is_array($payload['foreman_submitted'] ?? null) ? $payload['foreman_submitted'] : []
        ));
        $lines = array_merge($lines, $this->renderSection(
            'Project lifecycle',
            is_array($payload['lifecycle'] ?? null) ? $payload['lifecycle'] : []
        ));

        return implode("\n", $lines);
    }

    /**
     * Compact global summary for email (no per-task detail).
     *
     * @param array<string, mixed> $payload
     */
    private function renderGlobalText(array $payload): string
    {
        $date = (string) ($payload['report_date'] ?? '');
        $counts = is_array($payload['counts'] ?? null) ? $payload['counts'] : [];
        $projects = is_array($payload['projects'] ?? null) ? $payload['projects'] : [];
        $lines = [];
        $lines[] = "Daily summary — {$date}";
        $lines[] = 'Projects covered: ' . (int) ($payload['project_count'] ?? count($projects));
        $lines[] = str_repeat('-', 40);
        $lines[] = 'Totals:';
        $lines[] = '  Field work starts: ' . (int) ($counts['field_work_starts'] ?? 0);
        $lines[] = '  Field work ends: ' . (int) ($counts['field_work_ends'] ?? 0);
        $lines[] = '  Urgent: ' . (int) ($counts['urgent'] ?? 0);
        $lines[] = '  Foreman submitted: ' . (int) ($counts['foreman_submitted'] ?? 0);
        $lines[] = '  Lifecycle changes: ' . (int) ($counts['lifecycle_changes'] ?? 0);
        $lines[] = '  Events logged: ' . (int) ($counts['events_logged'] ?? 0);
        $lines[] = '';
        $lines[] = 'By project:';
        if ($projects === []) {
            $lines[] = '  No activity';
        } else {
            foreach ($projects as $project) {
                if (!is_array($project)) {
                    continue;
                }
                $pc = is_array($project['counts'] ?? null) ? $project['counts'] : [];
                $lines[] = sprintf(
                    '  - %s: starts=%d ends=%d urgent=%d submitted=%d events=%d',
                    (string) ($project['project_name'] ?? ('#' . (int) ($project['project_id'] ?? 0))),
                    (int) ($pc['field_work_starts'] ?? 0),
                    (int) ($pc['field_work_ends'] ?? 0),
                    (int) ($pc['urgent'] ?? 0),
                    (int) ($pc['foreman_submitted'] ?? 0),
                    (int) ($pc['events_logged'] ?? 0)
                );
            }
        }
        $lines[] = '';
        $lines[] = 'Open Reports in the app for project detail.';

        return implode("\n", $lines);
    }

    /**
     * Email-safe HTML snapshot (inline CSS, tables, CSS bars — no JS/SVG).
     *
     * @param array<string, mixed> $payload
     */
    public function renderHtml(array $payload): string
    {
        if (($payload['scope'] ?? '') === 'global') {
            return $this->renderGlobalHtml($payload);
        }

        $date = $this->esc((string) ($payload['report_date'] ?? ''));
        $name = $this->esc((string) ($payload['project_name'] ?? 'Project'));
        $counts = is_array($payload['counts'] ?? null) ? $payload['counts'] : [];

        $metrics = [
            ['label' => 'Field work starts', 'value' => (int) ($counts['field_work_starts'] ?? 0), 'color' => '#2563eb'],
            ['label' => 'Field work ends', 'value' => (int) ($counts['field_work_ends'] ?? 0), 'color' => '#0d9488'],
            ['label' => 'Foreman submitted', 'value' => (int) ($counts['foreman_submitted'] ?? 0), 'color' => '#7c3aed'],
            ['label' => 'Urgent', 'value' => (int) ($counts['urgent'] ?? 0), 'color' => '#dc2626'],
            ['label' => 'Lifecycle changes', 'value' => (int) ($counts['lifecycle_changes'] ?? 0), 'color' => '#d97706'],
            ['label' => 'Events logged', 'value' => (int) ($counts['events_logged'] ?? 0), 'color' => '#475569'],
        ];
        $bars = $this->renderMetricBarsHtml($metrics);

        $sections = '';
        $sections .= $this->renderHtmlSection('Field work started', is_array($payload['field_work_started'] ?? null) ? $payload['field_work_started'] : []);
        $sections .= $this->renderHtmlSection('Field work ended', is_array($payload['field_work_ended'] ?? null) ? $payload['field_work_ended'] : []);
        $sections .= $this->renderHtmlSection('Urgent', is_array($payload['urgent'] ?? null) ? $payload['urgent'] : []);
        $sections .= $this->renderHtmlSection('Foreman submitted', is_array($payload['foreman_submitted'] ?? null) ? $payload['foreman_submitted'] : []);
        $sections .= $this->renderHtmlSection('Project lifecycle', is_array($payload['lifecycle'] ?? null) ? $payload['lifecycle'] : []);

        return $this->wrapHtmlDocument(
            $name . ' — ' . $date,
            'Daily operational report',
            $name . ' · ' . $date,
            $bars,
            $sections
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function renderGlobalHtml(array $payload): string
    {
        $date = $this->esc((string) ($payload['report_date'] ?? ''));
        $counts = is_array($payload['counts'] ?? null) ? $payload['counts'] : [];
        $projects = is_array($payload['projects'] ?? null) ? $payload['projects'] : [];
        $projectCount = (int) ($payload['project_count'] ?? count($projects));

        $metrics = [
            ['label' => 'Field work starts', 'value' => (int) ($counts['field_work_starts'] ?? 0), 'color' => '#2563eb'],
            ['label' => 'Field work ends', 'value' => (int) ($counts['field_work_ends'] ?? 0), 'color' => '#0d9488'],
            ['label' => 'Foreman submitted', 'value' => (int) ($counts['foreman_submitted'] ?? 0), 'color' => '#7c3aed'],
            ['label' => 'Urgent', 'value' => (int) ($counts['urgent'] ?? 0), 'color' => '#dc2626'],
            ['label' => 'Lifecycle changes', 'value' => (int) ($counts['lifecycle_changes'] ?? 0), 'color' => '#d97706'],
            ['label' => 'Events logged', 'value' => (int) ($counts['events_logged'] ?? 0), 'color' => '#475569'],
        ];
        $bars = $this->renderMetricBarsHtml($metrics);

        $rows = '';
        if ($projects === []) {
            $rows = '<tr><td colspan="7" style="padding:10px;font:13px Arial,sans-serif;color:#94a3b8;font-style:italic;">No activity</td></tr>';
        } else {
            foreach ($projects as $project) {
                if (!is_array($project)) {
                    continue;
                }
                $pc = is_array($project['counts'] ?? null) ? $project['counts'] : [];
                $rows .= '<tr>'
                    . '<td style="padding:8px 10px;font:13px Arial,sans-serif;color:#0f172a;border-bottom:1px solid #f1f5f9;">'
                    . $this->esc((string) ($project['project_name'] ?? '')) . '</td>'
                    . '<td style="padding:8px 6px;font:13px Arial,sans-serif;text-align:right;border-bottom:1px solid #f1f5f9;">' . (int) ($pc['field_work_starts'] ?? 0) . '</td>'
                    . '<td style="padding:8px 6px;font:13px Arial,sans-serif;text-align:right;border-bottom:1px solid #f1f5f9;">' . (int) ($pc['field_work_ends'] ?? 0) . '</td>'
                    . '<td style="padding:8px 6px;font:13px Arial,sans-serif;text-align:right;border-bottom:1px solid #f1f5f9;">' . (int) ($pc['foreman_submitted'] ?? 0) . '</td>'
                    . '<td style="padding:8px 6px;font:13px Arial,sans-serif;text-align:right;border-bottom:1px solid #f1f5f9;">' . (int) ($pc['urgent'] ?? 0) . '</td>'
                    . '<td style="padding:8px 6px;font:13px Arial,sans-serif;text-align:right;border-bottom:1px solid #f1f5f9;">' . (int) ($pc['lifecycle_changes'] ?? 0) . '</td>'
                    . '<td style="padding:8px 6px;font:13px Arial,sans-serif;text-align:right;border-bottom:1px solid #f1f5f9;">' . (int) ($pc['events_logged'] ?? 0) . '</td>'
                    . '</tr>';
            }
        }

        $table = '<div style="font:bold 14px Arial,sans-serif;color:#0f172a;margin:16px 0 6px 0;">By project</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:6px;border-collapse:separate;overflow:hidden;">'
            . '<tr style="background:#f8fafc;">'
            . '<th style="padding:8px 10px;font:12px Arial,sans-serif;color:#64748b;text-align:left;">Project</th>'
            . '<th style="padding:8px 6px;font:12px Arial,sans-serif;color:#64748b;text-align:right;">Starts</th>'
            . '<th style="padding:8px 6px;font:12px Arial,sans-serif;color:#64748b;text-align:right;">Ends</th>'
            . '<th style="padding:8px 6px;font:12px Arial,sans-serif;color:#64748b;text-align:right;">Submitted</th>'
            . '<th style="padding:8px 6px;font:12px Arial,sans-serif;color:#64748b;text-align:right;">Urgent</th>'
            . '<th style="padding:8px 6px;font:12px Arial,sans-serif;color:#64748b;text-align:right;">Lifecycle</th>'
            . '<th style="padding:8px 6px;font:12px Arial,sans-serif;color:#64748b;text-align:right;">Events</th>'
            . '</tr>'
            . $rows
            . '</table>'
            . '<div style="font:12px Arial,sans-serif;color:#64748b;margin-top:12px;">Open Reports in the app for project detail.</div>';

        return $this->wrapHtmlDocument(
            'Daily summary — ' . $date,
            'Daily summary',
            $projectCount . ' project(s) · ' . $date,
            $bars,
            $table
        );
    }

    /**
     * @param list<array{label:string,value:int,color:string}> $metrics
     */
    private function renderMetricBarsHtml(array $metrics): string
    {
        $maxValue = 1;
        foreach ($metrics as $m) {
            $maxValue = max($maxValue, $m['value']);
        }
        $bars = '';
        foreach ($metrics as $m) {
            $pct = (int) round(($m['value'] / $maxValue) * 100);
            $bars .= '<tr>'
                . '<td style="padding:4px 8px;font:13px Arial,sans-serif;color:#334155;white-space:nowrap;">'
                . $this->esc($m['label']) . '</td>'
                . '<td style="padding:4px 8px;width:100%;">'
                . '<div style="background:#f1f5f9;border-radius:4px;overflow:hidden;height:18px;min-width:120px;">'
                . '<div style="background:' . $m['color'] . ';height:18px;width:' . $pct . '%;border-radius:4px;"></div>'
                . '</div></td>'
                . '<td style="padding:4px 8px;font:bold 13px Arial,sans-serif;color:#0f172a;text-align:right;">'
                . $m['value'] . '</td>'
                . '</tr>';
        }
        return $bars;
    }

    private function wrapHtmlDocument(
        string $docTitle,
        string $headerTitle,
        string $headerSub,
        string $barsHtml,
        string $bodyHtml
    ): string {
        return '<!DOCTYPE html>'
            . '<html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $this->esc($docTitle) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f8fafc;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;">'
            . '<tr><td align="center" style="padding:16px;">'
            . '<table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#ffffff;border-radius:8px;border:1px solid #e2e8f0;overflow:hidden;">'
            . '<tr><td style="background:#0f172a;padding:20px 24px;">'
            . '<div style="font:bold 18px Arial,sans-serif;color:#ffffff;">' . $this->esc($headerTitle) . '</div>'
            . '<div style="font:13px Arial,sans-serif;color:#94a3b8;margin-top:4px;">' . $this->esc($headerSub) . '</div>'
            . '</td></tr>'
            . '<tr><td style="padding:20px 24px;">'
            . '<div style="font:bold 14px Arial,sans-serif;color:#0f172a;margin-bottom:8px;">Summary</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $barsHtml . '</table>'
            . '</td></tr>'
            . '<tr><td style="padding:0 24px 20px 24px;">' . $bodyHtml . '</td></tr>'
            . '<tr><td style="background:#f1f5f9;padding:12px 24px;font:11px Arial,sans-serif;color:#94a3b8;">'
            . 'Generated snapshot · immutable</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function renderHtmlSection(string $title, array $rows): string
    {
        $header = '<div style="font:bold 14px Arial,sans-serif;color:#0f172a;margin:16px 0 6px 0;">'
            . $this->esc($title) . '</div>';

        if ($rows === []) {
            return $header
                . '<div style="font:13px Arial,sans-serif;color:#94a3b8;font-style:italic;">No activity</div>';
        }

        $body = '';
        foreach ($rows as $row) {
            $left = '';
            if (!empty($row['task_name'])) {
                $left = $this->esc((string) $row['task_name']);
            } elseif (!empty($row['task_id'])) {
                $left = 'Task #' . (int) $row['task_id'];
            } elseif (!empty($row['from']) || !empty($row['to'])) {
                $left = $this->esc(trim((string) ($row['from'] ?? '')) . ' → ' . trim((string) ($row['to'] ?? '')));
            } elseif (!empty($row['title'])) {
                $left = $this->esc((string) $row['title']);
            }

            $meta = [];
            if (!empty($row['reason'])) {
                $meta[] = 'reason: ' . $this->esc((string) $row['reason']);
            }
            if (!empty($row['comment'])) {
                $meta[] = $this->esc((string) $row['comment']);
            }
            $metaHtml = $meta === []
                ? ''
                : '<div style="font:12px Arial,sans-serif;color:#64748b;margin-top:2px;">' . implode(' · ', $meta) . '</div>';

            $body .= '<tr>'
                . '<td style="padding:8px 10px;font:12px Arial,sans-serif;color:#64748b;white-space:nowrap;vertical-align:top;border-bottom:1px solid #f1f5f9;">'
                . $this->esc((string) ($row['at'] ?? '')) . '</td>'
                . '<td style="padding:8px 10px;font:13px Arial,sans-serif;color:#0f172a;vertical-align:top;border-bottom:1px solid #f1f5f9;">'
                . $left . $metaHtml . '</td>'
                . '</tr>';
        }

        return $header
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
            . 'style="border:1px solid #e2e8f0;border-radius:6px;border-collapse:separate;overflow:hidden;">'
            . $body . '</table>';
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public function sendReport(int $reportId): void
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM fw_operational_daily_reports WHERE id = ? LIMIT 1',
            [$reportId]
        );
        if (!$row) {
            throw new \RuntimeException('Report not found: ' . $reportId);
        }

        $payload = $this->decodePayload($row['payload_json'] ?? null);
        $scope = (string) ($row['scope'] ?? ($payload['scope'] ?? 'project'));
        $projectId = (int) $row['project_id'];
        $date = (string) $row['report_date'];
        $text = $this->renderText($payload);
        $html = isset($row['rendered_html']) && is_string($row['rendered_html']) && $row['rendered_html'] !== ''
            ? $row['rendered_html']
            : $this->renderHtml($payload);
        $title = isset($row['title']) && is_string($row['title']) && $row['title'] !== ''
            ? $row['title']
            : ($scope === 'global'
                ? sprintf('Daily summary %s — all projects', $date)
                : sprintf(
                    'Daily report %s — %s',
                    $date,
                    (string) ($payload['project_name'] ?? ('Project #' . $projectId))
                ));

        $recipients = $scope === 'global'
            ? $this->resolveAdminPmRecipients()
            : $this->resolveRecipients($projectId);
        if ($recipients === []) {
            $this->markReport($reportId, 'failed', 'No recipients');
            throw new \RuntimeException('No recipients for report ' . $reportId);
        }

        $anySent = false;
        $lastError = null;
        foreach ($recipients as $userId) {
            $correlation = $scope === 'global'
                ? substr(sprintf('daily-op-global:%s:u%d', $date, $userId), 0, 64)
                : substr(sprintf('daily-op:%s:p%d:u%d', $date, $projectId, $userId), 0, 64);
            $result = $this->dispatcher->dispatch(new NotificationRequest(
                recipientUserId: $userId,
                type: 'DAILY_OPERATIONAL_REPORT',
                title: $title,
                message: $text,
                channels: ['email'],
                priority: 'medium',
                senderUserId: null,
                eventLogId: null,
                correlationId: $correlation,
                url: $scope === 'global' ? '/reports' : "/projects/{$projectId}?section=reports",
                data: [
                    'report_id' => $reportId,
                    'project_id' => $projectId,
                    'report_date' => $date,
                    'scope' => $scope,
                ],
                emailSubject: $title,
                emailHtml: $html,
                smsBody: null,
                pushTitle: null,
                pushBody: null,
                bypassPreferences: true,
                idempotencyKey: $correlation,
            ));

            if ($result->hasSent()) {
                $anySent = true;
            } elseif ($result->hasFailures()) {
                $lastError = 'Dispatch failed for user ' . $userId;
            }
        }

        if ($anySent) {
            $this->markReport($reportId, 'sent', null);
            return;
        }

        $this->markReport($reportId, 'failed', $lastError ?? 'No email delivered');
        throw new \RuntimeException($lastError ?? 'Failed to send daily report');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolveProjectsForDate(string $date): array
    {
        // Active projects, plus any project with activity that day even if not Active.
        $active = $this->connection->fetchAllAssociative(
            "SELECT id, prj_name, sys_status, prj_manager, status
             FROM fw_projects
             WHERE LOWER(COALESCE(sys_status, 'draft')) = 'active'
                OR sys_status = 'Active'
             ORDER BY id ASC"
        );

        $activityProjectIds = $this->projectIdsWithActivity($date);
        $byId = [];
        foreach ($active as $row) {
            $byId[(int) $row['id']] = $row;
        }
        foreach ($activityProjectIds as $pid) {
            if (isset($byId[$pid])) {
                continue;
            }
            $row = $this->connection->fetchAssociative(
                'SELECT id, prj_name, sys_status, prj_manager, status FROM fw_projects WHERE id = ? LIMIT 1',
                [$pid]
            );
            if ($row) {
                $byId[$pid] = $row;
            }
        }

        // If nothing matched, still emit Active projects (empty sections).
        if ($byId === [] && $active !== []) {
            return $active;
        }

        return array_values($byId);
    }

    /** @return list<int> */
    private function projectIdsWithActivity(string $date): array
    {
        $ids = [];

        try {
            $fromTasks = $this->connection->fetchFirstColumn(
                "SELECT DISTINCT project_id FROM fw_prj_tasks
                 WHERE (DATE(field_work_started_at) = ? OR DATE(field_work_ended_at) = ?
                        OR DATE(field_submitted_at) = ?)
                   AND project_id IS NOT NULL",
                [$date, $date, $date]
            );
            foreach ($fromTasks as $id) {
                $ids[] = (int) $id;
            }
        } catch (Throwable) {
            // optional columns may be missing on older DBs
        }

        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT entity_type, entity_id, after_data, event_type
                 FROM fw_event_log
                 WHERE DATE(occurred_at) = ?
                   AND event_type IN (
                     'TASK_FIELD_WORK_STARTED','TASK_FIELD_WORK_ENDED',
                     'TASK_FOREMAN_SUBMITTED','PROJECT_SYS_STATUS_CHANGED',
                     'PROJECT_BECAME_ACTIVE','PROJECT_BECAME_INACTIVE'
                   )",
                [$date]
            );
            foreach ($rows as $row) {
                $pid = $this->extractProjectIdFromEvent($row);
                if ($pid > 0) {
                    $ids[] = $pid;
                }
            }
        } catch (Throwable $e) {
            $this->logger->warning('Failed to scan event_log for daily report', [
                'error' => $e->getMessage(),
            ]);
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * @param array<string, mixed> $project
     * @return array<string, mixed>
     */
    private function buildPayload(string $date, array $project): array
    {
        $projectId = (int) $project['id'];
        $started = $this->collectFieldWork($projectId, $date, 'started');
        $ended = $this->collectFieldWork($projectId, $date, 'ended');
        $submitted = $this->collectForemanSubmitted($projectId, $date);
        $lifecycle = $this->collectLifecycle($projectId, $date);
        $urgent = $this->collectUrgent($projectId, $date);
        $eventsLogged = $this->countProjectEvents($projectId, $date);

        return [
            'report_date' => $date,
            'project_id' => $projectId,
            'project_name' => (string) ($project['prj_name'] ?? ('Project #' . $projectId)),
            'sys_status' => $project['sys_status'] ?? null,
            'counts' => [
                'field_work_starts' => count($started),
                'field_work_ends' => count($ended),
                'urgent' => count($urgent),
                'foreman_submitted' => count($submitted),
                'lifecycle_changes' => count($lifecycle),
                'events_logged' => $eventsLogged,
            ],
            'field_work_started' => $started,
            'field_work_ended' => $ended,
            'urgent' => $urgent,
            'foreman_submitted' => $submitted,
            'lifecycle' => $lifecycle,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectFieldWork(int $projectId, string $date, string $phase): array
    {
        $items = [];
        $timeCol = $phase === 'ended' ? 'field_work_ended_at' : 'field_work_started_at';
        $reasonCol = $phase === 'ended' ? 'field_work_end_reason' : 'field_work_start_reason';
        $eventType = $phase === 'ended' ? 'TASK_FIELD_WORK_ENDED' : 'TASK_FIELD_WORK_STARTED';

        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT id, name, `{$timeCol}` AS at_time, `{$reasonCol}` AS reason
                 FROM fw_prj_tasks
                 WHERE project_id = ? AND DATE(`{$timeCol}`) = ?
                 ORDER BY `{$timeCol}` ASC",
                [$projectId, $date]
            );
            foreach ($rows as $row) {
                $items[] = [
                    'task_id' => (int) $row['id'],
                    'task_name' => (string) ($row['name'] ?? ''),
                    'at' => (string) ($row['at_time'] ?? ''),
                    'reason' => (string) ($row['reason'] ?? ''),
                    'source' => 'task_row',
                ];
            }
        } catch (Throwable) {
            // columns may be missing
        }

        // Supplement from event log if task columns empty
        if ($items === []) {
            try {
                $events = $this->connection->fetchAllAssociative(
                    "SELECT entity_id, after_data, occurred_at, comment
                     FROM fw_event_log
                     WHERE DATE(occurred_at) = ? AND event_type = ? AND entity_type = 'task'",
                    [$date, $eventType]
                );
                foreach ($events as $event) {
                    if ($this->extractProjectIdFromEvent($event + ['event_type' => $eventType, 'entity_type' => 'task']) !== $projectId) {
                        continue;
                    }
                    $after = $this->decodeJson($event['after_data'] ?? null);
                    $items[] = [
                        'task_id' => (int) ($event['entity_id'] ?? 0),
                        'task_name' => (string) ($after['task_name'] ?? $after['name'] ?? ''),
                        'at' => (string) ($event['occurred_at'] ?? ''),
                        'reason' => (string) ($event['comment'] ?? ''),
                        'source' => 'event_log',
                    ];
                }
            } catch (Throwable) {
                // ignore
            }
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function collectForemanSubmitted(int $projectId, string $date): array
    {
        $items = [];
        try {
            $events = $this->connection->fetchAllAssociative(
                "SELECT entity_id, after_data, occurred_at, comment
                 FROM fw_event_log
                 WHERE DATE(occurred_at) = ?
                   AND event_type = 'TASK_FOREMAN_SUBMITTED'
                   AND entity_type = 'task'",
                [$date]
            );
            foreach ($events as $event) {
                if ($this->extractProjectIdFromEvent($event + ['event_type' => 'TASK_FOREMAN_SUBMITTED', 'entity_type' => 'task']) !== $projectId) {
                    continue;
                }
                $after = $this->decodeJson($event['after_data'] ?? null);
                $items[] = [
                    'task_id' => (int) ($event['entity_id'] ?? 0),
                    'task_name' => (string) ($after['task_name'] ?? $after['name'] ?? ''),
                    'at' => (string) ($event['occurred_at'] ?? ''),
                    'comment' => (string) ($event['comment'] ?? ''),
                ];
            }
        } catch (Throwable) {
            // ignore
        }

        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT id, name, field_submitted_at
                 FROM fw_prj_tasks
                 WHERE project_id = ? AND DATE(field_submitted_at) = ?
                 ORDER BY field_submitted_at ASC",
                [$projectId, $date]
            );
            $seen = array_column($items, 'task_id');
            foreach ($rows as $row) {
                $tid = (int) $row['id'];
                if (in_array($tid, $seen, true)) {
                    continue;
                }
                $items[] = [
                    'task_id' => $tid,
                    'task_name' => (string) ($row['name'] ?? ''),
                    'at' => (string) ($row['field_submitted_at'] ?? ''),
                    'comment' => 'Field work submitted',
                ];
            }
        } catch (Throwable) {
            // ignore
        }

        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function collectLifecycle(int $projectId, string $date): array
    {
        $items = [];
        try {
            $events = $this->connection->fetchAllAssociative(
                "SELECT after_data, before_data, occurred_at, comment, event_type
                 FROM fw_event_log
                 WHERE DATE(occurred_at) = ?
                   AND entity_type = 'project'
                   AND entity_id = ?
                   AND event_type IN (
                     'PROJECT_SYS_STATUS_CHANGED','PROJECT_BECAME_ACTIVE','PROJECT_BECAME_INACTIVE'
                   )
                 ORDER BY occurred_at ASC",
                [$date, $projectId]
            );
            foreach ($events as $event) {
                $after = $this->decodeJson($event['after_data'] ?? null);
                $before = $this->decodeJson($event['before_data'] ?? null);
                $items[] = [
                    'at' => (string) ($event['occurred_at'] ?? ''),
                    'event_type' => (string) ($event['event_type'] ?? ''),
                    'from' => (string) ($after['previous_sys_status'] ?? $before['sys_status'] ?? ''),
                    'to' => (string) ($after['sys_status'] ?? ''),
                    'comment' => (string) ($event['comment'] ?? ''),
                ];
            }
        } catch (Throwable) {
            // ignore
        }
        return $items;
    }

    /** @return list<array<string, mixed>> */
    private function collectUrgent(int $projectId, string $date): array
    {
        $items = [];
        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT id, type, title, message, created_at, data
                 FROM fw_notifications
                 WHERE DATE(created_at) = ?
                   AND (
                     title LIKE '%Urgent%'
                     OR message LIKE '%[Urgent]%'
                     OR data LIKE '%\"urgent\":true%'
                     OR data LIKE '%\"urgent\":1%'
                   )
                 ORDER BY created_at ASC",
                [$date]
            );
            foreach ($rows as $row) {
                $data = $this->decodeJson($row['data'] ?? null);
                $pid = (int) ($data['project_id'] ?? 0);
                $isUrgent = !empty($data['urgent'])
                    || str_contains((string) ($row['title'] ?? ''), 'Urgent')
                    || str_contains((string) ($row['message'] ?? ''), '[Urgent]');
                if (!$isUrgent || $pid !== $projectId) {
                    continue;
                }
                $items[] = [
                    'at' => (string) ($row['created_at'] ?? ''),
                    'type' => (string) ($row['type'] ?? ''),
                    'title' => (string) ($row['title'] ?? ''),
                    'task_id' => (int) ($data['task_id'] ?? 0),
                ];
            }
        } catch (Throwable) {
            // ignore missing table/columns
        }
        return $items;
    }

    private function countProjectEvents(int $projectId, string $date): int
    {
        try {
            $direct = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM fw_event_log
                 WHERE DATE(occurred_at) = ?
                   AND entity_type = 'project' AND entity_id = ?",
                [$date, $projectId]
            );
            $taskEvents = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM fw_event_log el
                 INNER JOIN fw_prj_tasks t ON el.entity_type = 'task' AND el.entity_id = t.id
                 WHERE DATE(el.occurred_at) = ? AND t.project_id = ?",
                [$date, $projectId]
            );
            return $direct + $taskEvents;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function upsertReport(
        string $date,
        int $projectId,
        array $payload,
        ?string $title = null,
        ?string $html = null,
        string $scope = 'project'
    ): void {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode report payload');
        }

        $scope = $scope === 'global' ? 'global' : 'project';

        if ($this->snapshotColumnsPresent()) {
            $this->connection->executeStatement(
                'INSERT INTO fw_operational_daily_reports
                    (report_date, project_id, report_type, scope, title, payload_json, rendered_html,
                     status, generated_at, sent_at, last_error)
                 VALUES (?, ?, \'daily\', ?, ?, ?, ?, \'generated\', NOW(), NULL, NULL)
                 ON DUPLICATE KEY UPDATE
                    report_type = \'daily\',
                    scope = VALUES(scope),
                    title = VALUES(title),
                    payload_json = VALUES(payload_json),
                    rendered_html = VALUES(rendered_html),
                    status = \'generated\',
                    generated_at = NOW(),
                    sent_at = NULL,
                    last_error = NULL',
                [$date, $projectId, $scope, $title, $json, $html]
            );
            return;
        }

        $this->connection->executeStatement(
            'INSERT INTO fw_operational_daily_reports
                (report_date, project_id, payload_json, status, generated_at, sent_at, last_error)
             VALUES (?, ?, ?, \'generated\', NOW(), NULL, NULL)
             ON DUPLICATE KEY UPDATE
                payload_json = VALUES(payload_json),
                status = \'generated\',
                generated_at = NOW(),
                sent_at = NULL,
                last_error = NULL',
            [$date, $projectId, $json]
        );
    }

    private function snapshotColumnsPresent(): bool
    {
        if (self::$snapshotColumnsExist !== null) {
            return self::$snapshotColumnsExist;
        }
        try {
            self::$snapshotColumnsExist = (bool) $this->connection->fetchOne(
                "SHOW COLUMNS FROM fw_operational_daily_reports LIKE 'rendered_html'"
            );
        } catch (Throwable) {
            self::$snapshotColumnsExist = false;
        }
        return self::$snapshotColumnsExist;
    }

    /** @return list<array<string, mixed>> */
    private function loadReportsForDate(string $date): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM fw_operational_daily_reports WHERE report_date = ? ORDER BY project_id ASC',
            [$date]
        );
    }

    /**
     * List report metadata (no rendered_html) for the archive UI.
     *
     * @param array{report_type?:string, scope?:string, project_id?:int, from?:string, to?:string, limit?:int} $filters
     * @param list<int>|null $allowedProjectIds  null = no restriction (admin)
     * @return list<array<string, mixed>>
     */
    public function listReportsMeta(array $filters, ?array $allowedProjectIds = null): array
    {
        $hasSnapshot = $this->snapshotColumnsPresent();
        $titleExpr = $hasSnapshot ? 'r.title' : 'NULL AS title';
        $typeExpr = $hasSnapshot ? 'r.report_type' : "'daily' AS report_type";
        $scopeExpr = $hasSnapshot ? 'r.scope' : "'project' AS scope";

        $sql = "SELECT r.id, r.report_date, r.project_id, {$typeExpr}, {$scopeExpr}, {$titleExpr},
                       r.status, r.generated_at, r.sent_at, p.prj_name AS project_name
                FROM fw_operational_daily_reports r
                LEFT JOIN fw_projects p ON p.id = r.project_id
                WHERE 1=1";
        $params = [];

        if ($hasSnapshot && !empty($filters['report_type'])) {
            $sql .= ' AND r.report_type = ?';
            $params[] = (string) $filters['report_type'];
        }
        if ($hasSnapshot && !empty($filters['scope'])) {
            $sql .= ' AND r.scope = ?';
            $params[] = (string) $filters['scope'];
        }
        if (!empty($filters['project_id'])) {
            $sql .= ' AND r.project_id = ?';
            $params[] = (int) $filters['project_id'];
        }
        if (!empty($filters['from'])) {
            $sql .= ' AND r.report_date >= ?';
            $params[] = (string) $filters['from'];
        }
        if (!empty($filters['to'])) {
            $sql .= ' AND r.report_date <= ?';
            $params[] = (string) $filters['to'];
        }
        if ($allowedProjectIds !== null) {
            if ($allowedProjectIds === []) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($allowedProjectIds), '?'));
            // Global summaries (project_id=0 / scope=global) are visible to Admin/PM alongside their projects.
            if ($hasSnapshot) {
                $sql .= " AND (r.scope = 'global' OR r.project_id IN ({$placeholders}))";
            } else {
                $sql .= " AND r.project_id IN ({$placeholders})";
            }
            foreach ($allowedProjectIds as $pid) {
                $params[] = (int) $pid;
            }
        }

        $limit = isset($filters['limit']) ? max(1, min(500, (int) $filters['limit'])) : 200;
        $sql .= " ORDER BY r.report_date DESC, r.project_id ASC LIMIT {$limit}";

        return $this->connection->fetchAllAssociative($sql, $params);
    }

    /** @return array<string, mixed>|null */
    public function getReportRow(int $reportId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM fw_operational_daily_reports WHERE id = ? LIMIT 1',
            [$reportId]
        );
        return $row ?: null;
    }

    /**
     * Return HTML snapshot; regenerate from payload if snapshot not stored (legacy rows).
     */
    public function getReportHtml(int $reportId): ?string
    {
        $row = $this->getReportRow($reportId);
        if ($row === null) {
            return null;
        }
        if (isset($row['rendered_html']) && is_string($row['rendered_html']) && $row['rendered_html'] !== '') {
            return $row['rendered_html'];
        }
        return $this->renderHtml($this->decodePayload($row['payload_json'] ?? null));
    }

    /** @return list<int> */
    private function resolveAdminPmRecipients(): array
    {
        try {
            $ids = $this->connection->fetchFirstColumn(
                "SELECT u.id
                 FROM fw_users u
                 INNER JOIN fw_glob_roles r ON u.role_id = r.id
                 WHERE r.code IN ('admin', 'project_manager')
                   AND u.status = 1 AND u.archived_at IS NULL"
            );
            return array_values(array_unique(array_map(static fn ($id): int => (int) $id, $ids)));
        } catch (Throwable $e) {
            $this->logger->error('Failed to resolve Admin/PM recipients', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /** @return list<int> */
    private function resolveRecipients(int $projectId): array
    {
        $ids = [];
        $project = $this->connection->fetchAssociative(
            'SELECT prj_manager' .
            ($this->projectForemanColumnPresent() ? ', project_foreman_id' : '') .
            ' FROM fw_projects WHERE id = ? LIMIT 1',
            [$projectId]
        );

        if ($project) {
            $managerId = (int) ($project['prj_manager'] ?? 0);
            if ($managerId > 0 && $this->isActiveUser($managerId)) {
                $ids[] = $managerId;
            }
            $foremanId = (int) ($project['project_foreman_id'] ?? 0);
            if ($foremanId > 0 && $this->isActiveUser($foremanId)) {
                $ids[] = $foremanId;
            }
        }

        try {
            $admins = $this->connection->fetchFirstColumn(
                "SELECT u.id
                 FROM fw_users u
                 INNER JOIN fw_glob_roles r ON u.role_id = r.id
                 WHERE r.code = 'admin' AND u.status = 1 AND u.archived_at IS NULL"
            );
            foreach ($admins as $adminId) {
                $ids[] = (int) $adminId;
            }
        } catch (Throwable) {
            // ignore
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    private function isActiveUser(int $userId): bool
    {
        try {
            $id = $this->connection->fetchOne(
                'SELECT id FROM fw_users WHERE id = ? AND status = 1 AND archived_at IS NULL LIMIT 1',
                [$userId]
            );
            return $id !== false && $id !== null;
        } catch (Throwable) {
            return false;
        }
    }

    private function projectForemanColumnPresent(): bool
    {
        if (self::$projectForemanColumnExists !== null) {
            return self::$projectForemanColumnExists;
        }
        try {
            self::$projectForemanColumnExists = (bool) $this->connection->fetchOne(
                "SHOW COLUMNS FROM fw_projects LIKE 'project_foreman_id'"
            );
        } catch (Throwable) {
            self::$projectForemanColumnExists = false;
        }
        return self::$projectForemanColumnExists;
    }

    private function markReport(int $reportId, string $status, ?string $error): void
    {
        if ($status === 'sent') {
            $this->connection->executeStatement(
                'UPDATE fw_operational_daily_reports
                 SET status = ?, sent_at = NOW(), last_error = NULL
                 WHERE id = ?',
                [$status, $reportId]
            );
            return;
        }
        $this->connection->executeStatement(
            'UPDATE fw_operational_daily_reports
             SET status = ?, last_error = ?
             WHERE id = ?',
            [$status, $error, $reportId]
        );
    }

    /**
     * @param array<string, mixed> $event
     */
    private function extractProjectIdFromEvent(array $event): int
    {
        $after = $this->decodeJson($event['after_data'] ?? null);
        if (isset($after['project_id'])) {
            return (int) $after['project_id'];
        }
        if (($event['entity_type'] ?? '') === 'project') {
            return (int) ($event['entity_id'] ?? 0);
        }
        if (($event['entity_type'] ?? '') === 'task') {
            $taskId = (int) ($event['entity_id'] ?? 0);
            if ($taskId > 0) {
                try {
                    return (int) ($this->connection->fetchOne(
                        'SELECT project_id FROM fw_prj_tasks WHERE id = ? LIMIT 1',
                        [$taskId]
                    ) ?: 0);
                } catch (Throwable) {
                    return 0;
                }
            }
        }
        return 0;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function renderSection(string $title, array $rows): array
    {
        $lines = [$title . ':'];
        if ($rows === []) {
            $lines[] = '  No activity';
            $lines[] = '';
            return $lines;
        }
        foreach ($rows as $row) {
            $bits = [];
            if (!empty($row['at'])) {
                $bits[] = (string) $row['at'];
            }
            if (!empty($row['task_name'])) {
                $bits[] = (string) $row['task_name'];
            } elseif (!empty($row['task_id'])) {
                $bits[] = 'Task #' . (int) $row['task_id'];
            }
            if (!empty($row['from']) || !empty($row['to'])) {
                $bits[] = trim((string) ($row['from'] ?? '')) . ' → ' . trim((string) ($row['to'] ?? ''));
            }
            if (!empty($row['title'])) {
                $bits[] = (string) $row['title'];
            }
            if (!empty($row['reason'])) {
                $bits[] = 'reason: ' . (string) $row['reason'];
            }
            if (!empty($row['comment'])) {
                $bits[] = (string) $row['comment'];
            }
            $lines[] = '  - ' . implode(' | ', array_filter($bits, static fn ($b) => $b !== ''));
        }
        $lines[] = '';
        return $lines;
    }

    private function normalizeDate(string $date): string
    {
        $date = trim($date);
        if ($date === '') {
            return (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if (!$dt) {
            throw new \InvalidArgumentException('Invalid date, expected YYYY-MM-DD');
        }
        return $dt->format('Y-m-d');
    }

    /** @return array<string, mixed> */
    private function decodePayload(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<string, mixed> */
    private function decodeJson(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
