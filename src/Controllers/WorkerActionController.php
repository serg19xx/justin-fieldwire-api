<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database\Database;
use App\Services\AttendanceService;
use App\Services\WorkClockService;
use App\Services\WorkerActionTokenService;
use App\Services\WorkerDayWorkService;
use App\Support\FrontendUrl;
use Doctrine\DBAL\Connection;
use Flight;
use Monolog\Logger;

/**
 * Public one-tap pages behind worker SMS links (no login). GET only shows the choices, because SMS
 * apps prefetch links; the form POST performs "Working late" or "Arrived at site".
 */
class WorkerActionController
{
    private WorkClockService $clock;

    public function __construct(
        private readonly Logger $logger
    ) {
        $this->clock = new WorkClockService();
    }

    /** GET /api/v1/worker-actions/{token} */
    public function show(string $token): void
    {
        try {
            $conn = Database::getConnection();
            $link = (new WorkerActionTokenService($this->clock))->find($conn, $token);
            if ($link === null) {
                $this->expired();
                return;
            }
            $this->renderAction($conn, $token, $link, null);
        } catch (\Throwable $e) {
            $this->logger->error('Worker action page failed', ['error' => $e->getMessage()]);
            $this->page(500, 'Something went wrong', '<p>Please open the app instead.</p>' . $this->appLink());
        }
    }

    /** POST /api/v1/worker-actions/{token} */
    public function perform(string $token): void
    {
        try {
            $conn = Database::getConnection();
            $tokens = new WorkerActionTokenService($this->clock);
            $link = $tokens->find($conn, $token);
            if ($link === null) {
                $this->expired();
                return;
            }
            $attendance = new AttendanceService($this->clock, $this->logger);
            if ($link['action'] === WorkerActionTokenService::ACTION_EXTEND) {
                $mode = (string) ($_POST['mode'] ?? '');
                $result = $attendance->extend($conn, $link['user_id'], $link['work_date'], $mode);
            } else {
                $lat = isset($_POST['lat']) && is_numeric($_POST['lat']) ? (float) $_POST['lat'] : null;
                $lng = isset($_POST['lng']) && is_numeric($_POST['lng']) ? (float) $_POST['lng'] : null;
                $result = $attendance->recordSiteArrival(
                    $conn,
                    $link['user_id'],
                    $link['work_date'],
                    (int) $link['project_id'],
                    $lat,
                    $lng,
                    'link'
                );
            }
            $tokens->markUsed($conn, $link['id']);
            $this->renderAction($conn, $token, $link, $result['ok'] ? 'Saved.' : $result['message'], !$result['ok']);
        } catch (\Throwable $e) {
            $this->logger->error('Worker action failed', ['error' => $e->getMessage()]);
            $this->page(500, 'Something went wrong', '<p>Please open the app instead.</p>' . $this->appLink());
        }
    }

    /**
     * @param array{user_id: int, work_date: string, action: string, project_id: ?int} $link
     */
    private function renderAction(Connection $conn, string $token, array $link, ?string $notice, bool $isError = false): void
    {
        $firstName = trim((string) $conn->executeQuery(
            'SELECT first_name FROM fw_users WHERE id = ?',
            [$link['user_id']]
        )->fetchOne());
        $hello = $firstName !== '' ? 'Hi ' . $this->e($firstName) . ',' : 'Hi,';
        $noticeHtml = $notice !== null
            ? '<p class="notice' . ($isError ? ' error' : '') . '">' . $this->e($notice) . '</p>'
            : '';
        $action = $this->e(WorkerActionTokenService::url($token));

        if ($link['action'] === WorkerActionTokenService::ACTION_ARRIVE) {
            $this->renderArrive($conn, $link, $hello, $noticeHtml, $action);
            return;
        }

        $attendance = new AttendanceService($this->clock, $this->logger);
        $row = (new WorkerDayWorkService($this->clock))->findDayRow($conn, $link['user_id'], $link['work_date']);
        if ($row === null || empty($row['work_start_at'])) {
            $this->page(200, 'Working late', "{$noticeHtml}<p>{$hello} your day has not been started.</p>" . $this->appLink());
            return;
        }
        if (!empty($row['work_end_at'])) {
            $this->page(200, 'Working late', "{$noticeHtml}<p>{$hello} your day has already ended. Nothing else to do.</p>");
            return;
        }

        $shift = $attendance->shiftFor($conn, $link['user_id'], $link['work_date']);
        $status = $shift !== null ? 'Your shift ends at ' . $this->e($shift['end_time']) . '.' : '';
        if (!empty($row['extended_open'])) {
            $status .= ' Reminders are paused until you tap End day.';
        } elseif (!empty($row['extended_until'])) {
            $status .= ' Reminders are paused until ' . $this->e(substr((string) $row['extended_until'], 0, 5)) . '.';
        }
        $buttons = $this->button($action, 'mode', '1h', '+1 hour')
            . $this->button($action, 'mode', '2h', '+2 hours')
            . $this->button($action, 'mode', 'open', 'Until I finish (no reminders)');
        if (!empty($row['extended_open']) || !empty($row['extended_until'])) {
            $buttons .= $this->button($action, 'mode', 'cancel', 'Back to scheduled end', true);
        }

        $this->page(
            200,
            'Still working?',
            "{$noticeHtml}<p>{$hello} {$status}</p><p>Choose how long you will keep working. Overtime is paid after your project manager approves it.</p>"
            . $buttons
            . '<p class="muted">Done for today? Tap End day in the app.</p>' . $this->appLink()
        );
    }

    /**
     * @param array{user_id: int, work_date: string, project_id: ?int} $link
     */
    private function renderArrive(Connection $conn, array $link, string $hello, string $noticeHtml, string $action): void
    {
        $sites = (new AttendanceService($this->clock, $this->logger))->daySites($conn, $link['user_id'], $link['work_date']);
        $site = null;
        foreach ($sites as $s) {
            if ($s['project_id'] === $link['project_id']) {
                $site = $s;
            }
        }
        if ($site === null) {
            $this->page(200, 'Arrived at site', "{$noticeHtml}<p>{$hello} this site is no longer on your schedule today.</p>" . $this->appLink());
            return;
        }
        $name = $this->e((string) ($site['project_name'] ?? ('Project #' . $site['project_id'])));
        $address = $site['project_address'] !== null ? '<p class="muted">' . $this->e((string) $site['project_address']) . '</p>' : '';
        if ($site['arrived_at'] !== null) {
            $at = (new \DateTimeImmutable((string) $site['arrived_at']))->setTimezone($this->clock->timezone())->format('H:i');
            $this->page(200, 'Arrived at site', "{$noticeHtml}<p>{$hello} your arrival at <strong>{$name}</strong> was recorded at {$at}.</p>{$address}");
            return;
        }

        $form = '<form method="post" action="' . $action . '" id="arrive-form">'
            . '<input type="hidden" name="lat" id="lat"><input type="hidden" name="lng" id="lng">'
            . '<button type="submit" id="arrive-btn">I am at ' . $name . '</button></form>'
            . '<script>'
            . 'document.getElementById("arrive-form").addEventListener("submit",function(e){'
            . 'var f=this;if(f.dataset.ready||!navigator.geolocation){return;}e.preventDefault();'
            . 'document.getElementById("arrive-btn").textContent="Saving...";'
            . 'var done=function(){f.dataset.ready="1";f.submit();};var t=setTimeout(done,6000);'
            . 'navigator.geolocation.getCurrentPosition(function(p){clearTimeout(t);'
            . 'document.getElementById("lat").value=p.coords.latitude;document.getElementById("lng").value=p.coords.longitude;done();},'
            . 'function(){clearTimeout(t);done();},{timeout:5000,maximumAge:60000});});'
            . '</script>';

        $this->page(
            200,
            'Arrived at site?',
            "{$noticeHtml}<p>{$hello} you are scheduled at <strong>{$name}</strong>"
            . ($site['expected_start_time'] !== null ? ' from ' . $this->e((string) $site['expected_start_time']) : '')
            . '.</p>' . $address . $form
            . '<p class="muted">Your location is saved with the arrival if you allow it.</p>'
        );
    }

    private function button(string $action, string $name, string $value, string $label, bool $secondary = false): string
    {
        return '<form method="post" action="' . $action . '">'
            . '<input type="hidden" name="' . $this->e($name) . '" value="' . $this->e($value) . '">'
            . '<button type="submit"' . ($secondary ? ' class="secondary"' : '') . '>' . $this->e($label) . '</button></form>';
    }

    private function expired(): void
    {
        $this->page(410, 'Link expired', '<p>This link has expired or is not valid. Open the app to update your day.</p>' . $this->appLink());
    }

    private function appLink(): string
    {
        $url = $this->e(FrontendUrl::resolve() . '/tasks/timesheet');

        return '<p><a href="' . $url . '">Open Timesheet</a></p>';
    }

    private function page(int $code, string $title, string $bodyHtml): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        $t = $this->e($title);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . "<title>{$t} - FieldWire</title><style>"
            . 'body{font-family:-apple-system,Segoe UI,Arial,sans-serif;margin:0;padding:24px;background:#f9fafb;color:#111827}'
            . 'main{max-width:440px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:20px}'
            . 'h1{font-size:20px;margin:0 0 12px}p{line-height:1.45}'
            . 'form{margin:10px 0}button{width:100%;padding:14px;font-size:16px;font-weight:600;border:0;border-radius:10px;background:#ea580c;color:#fff}'
            . 'button.secondary{background:#e5e7eb;color:#111827}'
            . '.notice{background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;padding:8px 10px;color:#065f46}'
            . '.notice.error{background:#fef2f2;border-color:#fecaca;color:#991b1b}'
            . '.muted{color:#6b7280;font-size:13px}a{color:#c2410c;font-weight:600}'
            . "</style></head><body><main><h1>{$t}</h1>{$bodyHtml}</main></body></html>";
        Flight::stop($code);
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
