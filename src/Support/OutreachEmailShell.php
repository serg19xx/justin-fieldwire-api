<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Fixed Header + BODY + Footer HTML wrapper for custom outreach emails.
 */
final class OutreachEmailShell
{
    public static function unsubscribeToken(int $recipientId): string
    {
        $secret = self::tokenSecret();
        $sig = hash_hmac('sha256', (string) $recipientId, $secret);

        return rtrim(strtr(base64_encode($recipientId . '.' . $sig), '+/', '-_'), '=');
    }

    public static function parseUnsubscribeToken(string $token): ?int
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $pad = strlen($token) % 4;
        if ($pad > 0) {
            $token .= str_repeat('=', 4 - $pad);
        }
        $raw = base64_decode(strtr($token, '-_', '+/'), true);
        if (!is_string($raw) || !str_contains($raw, '.')) {
            return null;
        }
        [$idPart, $sig] = explode('.', $raw, 2);
        $id = (int) $idPart;
        if ($id < 1 || $sig === '') {
            return null;
        }
        $expected = hash_hmac('sha256', (string) $id, self::tokenSecret());
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        return $id;
    }

    public static function unsubscribeUrl(int $recipientId): string
    {
        return ApiUrl::base() . '/api/v1/outreach/unsubscribe?token=' . rawurlencode(self::unsubscribeToken($recipientId));
    }

    /**
     * @param array<string, string> $vars rendered placeholders (client_name, destination, …)
     */
    public static function wrapBody(string $bodyText, array $vars, int $recipientId): string
    {
        $company = trim((string) ($_ENV['OUTREACH_COMPANY_NAME'] ?? 'Medical Contractor'));
        if ($company === '') {
            $company = 'Medical Contractor';
        }
        $logo = trim((string) ($_ENV['OUTREACH_EMAIL_LOGO_URL'] ?? ''));
        $headerText = trim((string) ($_ENV['OUTREACH_EMAIL_HEADER_TEXT'] ?? 'Invitation to collaborate'));
        if ($headerText === '') {
            $headerText = 'Invitation to collaborate';
        }
        $footerExtra = trim((string) ($_ENV['OUTREACH_EMAIL_FOOTER_TEXT'] ?? ''));
        if ($footerExtra === '') {
            $footerExtra = $company . ' uses this mailing to invite partners to collaborate via FieldWire.';
        }

        $unsub = self::unsubscribeUrl($recipientId);
        $allVars = array_merge($vars, [
            'unsubscribe_url' => $unsub,
            'company_name' => $company,
        ]);

        $headerText = self::render($headerText, $allVars);
        $footerExtra = self::render($footerExtra, $allVars);
        $bodyHtml = nl2br(htmlspecialchars(self::render($bodyText, $allVars), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);

        $logoBlock = '';
        if ($logo !== '') {
            $logoEsc = htmlspecialchars($logo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $logoBlock = '<img src="' . $logoEsc . '" alt="' . htmlspecialchars($company, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" style="max-height:48px;margin-bottom:12px;" />';
        }

        $companyEsc = htmlspecialchars($company, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $headerEsc = htmlspecialchars($headerText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $footerEsc = nl2br(htmlspecialchars($footerExtra, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
        $unsubEsc = htmlspecialchars($unsub, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f3f4f6;padding:24px 12px;">
    <tr><td align="center">
      <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;">
        <tr><td style="padding:24px 28px;border-bottom:1px solid #e5e7eb;background:#0f172a;color:#ffffff;">
          {$logoBlock}
          <div style="font-size:18px;font-weight:700;line-height:1.3;">{$headerEsc}</div>
          <div style="font-size:12px;opacity:.85;margin-top:6px;">{$companyEsc}</div>
        </td></tr>
        <tr><td style="padding:28px;font-size:15px;line-height:1.55;color:#111827;">
          {$bodyHtml}
        </td></tr>
        <tr><td style="padding:20px 28px;border-top:1px solid #e5e7eb;background:#f9fafb;font-size:12px;line-height:1.5;color:#6b7280;">
          <div>{$footerEsc}</div>
          <p style="margin:14px 0 0;">
            This message is a business invitation. If you no longer wish to receive these emails,
            <a href="{$unsubEsc}" style="color:#2563eb;">unsubscribe here</a>.
          </p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }

    /** @param array<string, string> $vars */
    public static function render(string $template, array $vars): string
    {
        $out = $template;
        foreach ($vars as $key => $value) {
            $out = str_replace(['{{' . $key . '}}', '{{ ' . $key . ' }}'], $value, $out);
        }

        return $out;
    }

    private static function tokenSecret(): string
    {
        $secret = trim((string) ($_ENV['OUTREACH_UNSUBSCRIBE_SECRET'] ?? ''));
        if ($secret !== '') {
            return $secret;
        }
        $fallback = trim((string) ($_ENV['OUTREACH_N8N_SECRET'] ?? ''));

        return $fallback !== '' ? $fallback : 'outreach-unsub-dev-secret';
    }
}
