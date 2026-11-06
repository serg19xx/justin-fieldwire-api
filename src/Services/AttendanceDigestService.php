<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\FrontendUrl;
use App\Support\PhoneNormalizer;
use Doctrine\DBAL\Connection;
use Monolog\Logger;

/**
 * Daily attendance digests for admins and project managers, at most once per kind per day:
 * a morning SMS about workers who have not clocked in (or clocked in late) and an evening SMS plus
 * a detailed email about every open time issue of the day. Nothing is sent when there are no issues.
 */
class AttendanceDigestService
{
    public const KIND_MORNING = 'morning';
    public const KIND_EVENING = 'evening';

    /** Local send windows [from, until). */
    private const WINDOWS = [
        self::KIND_MORNING => ['09:00', '12:00'],
        self::KIND_EVENING => ['19:00', '23:59'],
    ];

    private const MORNING_CODES = ['no_start', 'late_start'];

    private const TABLE = 'fw_attendance_digests';

    private WorkClockService $clock;
    private AttendanceService $attendance;
    private ?TwilioService $twilio;
    private ?EmailService $email;

    public function __construct(
        private readonly Logger $logger,
        ?WorkClockService $clock = null,
        ?TwilioService $twilio = null,
        ?EmailService $email = null,
    ) {
        $this->clock = $clock ?? new WorkClockService();
        $this->attendance = new AttendanceService($this->clock, $this->logger);
        $this->twilio = $twilio;
        $this->email = $email;
    }

    public function tableExists(Connection $conn): bool
    {
        return (bool) $conn->executeQuery('SHOW TABLES LIKE ' . $conn->quote(self::TABLE))->fetchOne();
    }

    /**
     * @return list<array{kind: string, status: string, issues: int, sms: ?string, recipients: list<string>}>
     */
    public function run(Connection $conn, bool $send, ?\DateTimeImmutable $now = null): array
    {
        $now = ($now ?? $this->clock->now())->setTimezone($this->clock->timezone());
        if (!$this->tableExists($conn) || !$this->attendance->isAvailable($conn)) {
            return [];
        }
        $ymd = $this->clock->todayYmd($now);
        $hm = $now->format('H:i');
        $out = [];

        foreach (self::WINDOWS as $kind => [$from, $until]) {
            if ($hm < $from || $hm >= $until || $this->alreadyRecorded($conn, $ymd, $kind)) {
                continue;
            }
            $entries = array_values(array_filter(
                $this->attendance->report($conn, $ymd, $ymd, $now),
                static fn (array $e): bool => $e['issues'] !== []
            ));
            if ($kind === self::KIND_MORNING) {
                $entries = array_values(array_filter(array_map(static function (array $e): array {
                    $e['issues'] = array_values(array_filter(
                        $e['issues'],
                        static fn (array $i): bool => in_array($i['code'], self::MORNING_CODES, true)
                    ));

                    return $e;
                }, $entries), static fn (array $e): bool => $e['issues'] !== []));
            }
            $issueCount = array_sum(array_map(static fn (array $e): int => count($e['issues']), $entries));
            $sms = $issueCount > 0 ? $this->smsBody($kind, $ymd, $entries, $issueCount) : null;

            if (!$send) {
                $out[] = ['kind' => $kind, 'status' => 'dry_run', 'issues' => $issueCount, 'sms' => $sms, 'recipients' => []];
                continue;
            }
            if (!$this->claim($conn, $ymd, $kind, $issueCount)) {
                continue;
            }
            if ($issueCount === 0) {
                $this->finish($conn, $ymd, $kind, 'no_issues', []);
                $out[] = ['kind' => $kind, 'status' => 'no_issues', 'issues' => 0, 'sms' => null, 'recipients' => []];
                continue;
            }

            $delivered = [];
            $failed = 0;
            [$phones, $emails] = $this->recipients($conn);
            foreach ($phones as $phone) {
                if ($this->twilio()->sendSms($phone, (string) $sms)) {
                    $delivered[] = $phone;
                } else {
                    $failed++;
                }
            }
            if ($kind === self::KIND_EVENING) {
                [$subject, $html, $text] = $this->emailContent($ymd, $entries, $issueCount);
                foreach ($emails as $address => $name) {
                    if ($this->email()->sendEmailWithTemplates($address, $subject, $html, $text, $name)) {
                        $delivered[] = $address;
                    } else {
                        $failed++;
                    }
                }
            }
            $status = $delivered === [] ? 'failed' : ($failed > 0 ? 'partial' : 'sent');
            if ($status !== 'sent') {
                $this->logger->warning('Attendance digest not fully delivered', ['kind' => $kind, 'failed' => $failed]);
            }
            $this->finish($conn, $ymd, $kind, $status, $delivered);
            $out[] = ['kind' => $kind, 'status' => $status, 'issues' => $issueCount, 'sms' => $sms, 'recipients' => $delivered];
        }

        return $out;
    }

    /** @param list<array<string, mixed>> $entries */
    private function smsBody(string $kind, string $ymd, array $entries, int $issueCount): string
    {
        $date = (new \DateTimeImmutable($ymd))->format('M j');
        $link = $this->reviewLink($ymd);
        if ($kind === self::KIND_MORNING) {
            $byCode = ['no_start' => [], 'late_start' => []];
            foreach ($entries as $e) {
                foreach ($e['issues'] as $issue) {
                    $byCode[$issue['code']][] = $this->shortName((string) $e['worker_name']);
                }
            }
            $parts = [];
            if ($byCode['no_start'] !== []) {
                $parts[] = 'Not clocked in: ' . $this->nameList($byCode['no_start']) . '.';
            }
            if ($byCode['late_start'] !== []) {
                $parts[] = 'Late start: ' . $this->nameList($byCode['late_start']) . '.';
            }

            return "FieldWire attendance {$date}: " . implode(' ', $parts) . " Review: {$link}";
        }

        $workers = count($entries);

        return sprintf(
            'FieldWire attendance %s: %d time issue%s for %d worker%s. Details in your email and here: %s',
            $date,
            $issueCount,
            $issueCount === 1 ? '' : 's',
            $workers,
            $workers === 1 ? '' : 's',
            $link
        );
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return array{0: string, 1: string, 2: string}
     */
    private function emailContent(string $ymd, array $entries, int $issueCount): array
    {
        $dateLabel = (new \DateTimeImmutable($ymd))->format('D, M j, Y');
        $link = $this->reviewLink($ymd);
        $subject = "Attendance issues - {$dateLabel} ({$issueCount})";
        $e = static fn (?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $rowsHtml = '';
        $rowsText = '';
        foreach ($entries as $entry) {
            $day = $entry['day'] ?? null;
            $shift = $entry['shift'] !== null ? $entry['shift']['start_time'] . '–' . $entry['shift']['end_time'] : '—';
            $sites = implode(', ', array_map(
                static fn (array $s): string => (string) ($s['project_name'] ?? ('Project #' . $s['project_id'])),
                $entry['sites']
            ));
            $in = $this->clockLabel($day['work_start_at'] ?? null);
            $outLabel = $this->clockLabel($day['work_end_at'] ?? null);
            $paid = $day !== null
                ? ($day['adjusted_start_time'] ?? $in) . '–' . ($day['adjusted_end_time'] ?? $outLabel)
                : '—';
            $issues = array_map(static fn (array $i): string => (string) $i['label'], $entry['issues']);

            $rowsHtml .= '<tr>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;font-weight:600;">' . $e((string) $entry['worker_name']) . '</td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;">' . $e($sites !== '' ? $sites : '—') . '</td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;white-space:nowrap;">' . $e($shift) . '</td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;white-space:nowrap;">' . $e($in) . ' / ' . $e($outLabel) . '</td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;white-space:nowrap;">' . $e($paid) . '</td>'
                . '<td style="padding:6px 8px;border-bottom:1px solid #e5e7eb;color:#92400e;">' . implode('<br>', array_map($e, $issues)) . '</td>'
                . '</tr>';
            $rowsText .= sprintf(
                "- %s | %s | shift %s | punches %s / %s | paid %s\n  %s\n",
                $entry['worker_name'],
                $sites !== '' ? $sites : '—',
                $shift,
                $in,
                $outLabel,
                $paid,
                implode("\n  ", $issues)
            );
        }

        $th = 'style="text-align:left;padding:6px 8px;border-bottom:2px solid #d1d5db;font-size:12px;color:#6b7280;"';
        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#111827;">'
            . '<h2 style="margin:0 0 8px;">Attendance issues - ' . $e($dateLabel) . '</h2>'
            . '<p style="margin:0 0 12px;">' . $issueCount . ' open issue' . ($issueCount === 1 ? '' : 's')
            . '. Paid times follow the rules: on-time start pays from the scheduled start, a day without End day closes at the scheduled end, overtime waits for your approval.</p>'
            . '<table style="border-collapse:collapse;width:100%;font-size:13px;">'
            . "<thead><tr><th {$th}>Worker</th><th {$th}>Site</th><th {$th}>Shift</th><th {$th}>Clock in / out</th><th {$th}>Paid</th><th {$th}>Issues</th></tr></thead>"
            . '<tbody>' . $rowsHtml . '</tbody></table>'
            . '<p style="margin:16px 0 0;"><a href="' . $e($link) . '" style="color:#c2410c;font-weight:600;">Open Attendance in FieldWire</a> to fix times, approve overtime or lock the day.</p>'
            . '</div>';
        $text = "Attendance issues - {$dateLabel} ({$issueCount})\n\n{$rowsText}\nReview: {$link}\n";

        return [$subject, $html, $text];
    }

    /**
     * Admins and project managers with a phone (SMS) and email.
     *
     * @return array{0: list<string>, 1: array<string, string>}
     */
    private function recipients(Connection $conn): array
    {
        $rows = $conn->executeQuery(
            "SELECT u.first_name, u.last_name, u.phone, u.email
             FROM fw_users u
             INNER JOIN fw_glob_roles r ON r.id = u.role_id
             WHERE r.code IN ('admin', 'project_manager')
               AND u.status = 1
               AND u.archived_at IS NULL"
        )->fetchAllAssociative();
        $phones = [];
        $emails = [];
        foreach ($rows as $r) {
            $phone = PhoneNormalizer::toE164($r['phone'] !== null ? (string) $r['phone'] : null, false);
            if ($phone !== null) {
                $phones[$phone] = $phone;
            }
            $email = strtolower(trim((string) ($r['email'] ?? '')));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[$email] = trim((string) ($r['first_name'] ?? '') . ' ' . (string) ($r['last_name'] ?? ''));
            }
        }

        return [array_values($phones), $emails];
    }

    private function reviewLink(string $ymd): string
    {
        return FrontendUrl::resolve() . '/schedule/attendance?date=' . $ymd;
    }

    private function clockLabel(?string $iso): string
    {
        if ($iso === null) {
            return '—';
        }
        try {
            return (new \DateTimeImmutable($iso))->setTimezone($this->clock->timezone())->format('H:i');
        } catch (\Throwable) {
            return '—';
        }
    }

    private function shortName(string $full): string
    {
        $parts = preg_split('/\s+/', trim($full)) ?: [];
        if (count($parts) < 2) {
            return trim($full);
        }

        return $parts[0] . ' ' . mb_substr((string) end($parts), 0, 1) . '.';
    }

    /** @param list<string> $names */
    private function nameList(array $names): string
    {
        $names = array_values(array_unique($names));
        $shown = array_slice($names, 0, 5);
        $more = count($names) - count($shown);

        return implode(', ', $shown) . ($more > 0 ? " +{$more} more" : '');
    }

    private function alreadyRecorded(Connection $conn, string $ymd, string $kind): bool
    {
        return (bool) $conn->executeQuery(
            'SELECT 1 FROM ' . self::TABLE . ' WHERE digest_date = ? AND kind = ?',
            [$ymd, $kind]
        )->fetchOne();
    }

    private function claim(Connection $conn, string $ymd, string $kind, int $issueCount): bool
    {
        return $conn->executeStatement(
            'INSERT IGNORE INTO ' . self::TABLE . ' (digest_date, kind, issue_count, status) VALUES (?, ?, ?, ?)',
            [$ymd, $kind, $issueCount, 'pending']
        ) > 0;
    }

    /** @param list<string> $recipients */
    private function finish(Connection $conn, string $ymd, string $kind, string $status, array $recipients): void
    {
        $conn->executeStatement(
            'UPDATE ' . self::TABLE . ' SET status = ?, recipients = ? WHERE digest_date = ? AND kind = ?',
            [$status, $recipients === [] ? null : implode(', ', $recipients), $ymd, $kind]
        );
    }

    private function twilio(): TwilioService
    {
        return $this->twilio ??= new TwilioService($this->logger);
    }

    private function email(): EmailService
    {
        return $this->email ??= new EmailService($this->logger);
    }
}
