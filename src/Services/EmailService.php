<?php

namespace App\Services;

use App\Support\FrontendUrl;
use Monolog\Logger;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use SendGrid;

class EmailService
{
    private Logger $logger;
    private ?SendGrid $sendGrid;
    private bool $sendGridAvailable;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->sendGridAvailable = false;
        $this->sendGrid = null;

        // Initialize SendGrid if API key is available
        $apiKey = $_ENV['SENDGRID_API_KEY'] ?? null;
        $this->logger->info('SendGrid API key check', [
            'api_key_exists' => !empty($apiKey),
            'api_key_length' => strlen($apiKey ?? ''),
            'api_key_starts_with' => substr($apiKey ?? '', 0, 3),
            'env_vars_available' => [
                'SENDGRID_API_KEY' => !empty($_ENV['SENDGRID_API_KEY']),
                'SENDGRID_FROM_EMAIL' => !empty($_ENV['SENDGRID_FROM_EMAIL']),
                'SENDGRID_FROM_NAME' => !empty($_ENV['SENDGRID_FROM_NAME']),
                'SMTP_HOST' => !empty($_ENV['SMTP_HOST']),
                'SMTP_USERNAME' => !empty($_ENV['SMTP_USERNAME']),
                'SMTP_PASSWORD' => !empty($_ENV['SMTP_PASSWORD'])
            ]
        ]);
        
        if ($apiKey && $apiKey !== 'your_sendgrid_api_key_here') {
            try {
                $this->sendGrid = new SendGrid($apiKey);
                $this->sendGridAvailable = true;
                $this->logger->info('SendGrid initialized successfully');
            } catch (\Exception $e) {
                $this->logger->error('Failed to initialize SendGrid', [
                    'error' => $e->getMessage()
                ]);
            }
        } else {
            $this->logger->info('SendGrid API key not configured, will use PHPMailer fallback');
        }
    }

    /**
     * Send email using specified provider or auto-fallback
     * 
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $message Email message
     * @param string $toName Recipient name (optional)
     * @param string $provider Provider to use: 'sendgrid', 'phpmailer', or 'auto' (default)
     * @return bool Success status
     */
    public function sendEmail(string $to, string $subject, string $message, string $toName = '', string $provider = 'auto'): bool
    {
        switch (strtolower($provider)) {
            case 'sendgrid':
                if ($this->sendGridAvailable) {
                    return $this->sendViaSendGrid($to, $subject, $message, $toName);
                } else {
                    $this->logger->warning('SendGrid requested but not available, falling back to PHPMailer');
                    return $this->sendViaPHPMailer($to, $subject, $message, $toName);
                }
                
            case 'phpmailer':
                return $this->sendViaPHPMailer($to, $subject, $message, $toName);
                
            case 'auto':
            default:
                // Use SendGrid API by default, fallback to PHPMailer if not available
                if ($this->sendGridAvailable) {
                    return $this->sendViaSendGrid($to, $subject, $message, $toName);
                } else {
                    $this->logger->info('SendGrid not available, using PHPMailer fallback');
                    return $this->sendViaPHPMailer($to, $subject, $message, $toName);
                }
        }
    }

    /**
     * List active SendGrid dynamic templates (templates with at least one active version).
     *
     * @return array<int, array{id: string, name: string, version_name: string}>
     */
    public function listActiveDynamicTemplates(): array
    {
        if (!$this->sendGridAvailable) {
            $this->logger->warning('SendGrid not available — cannot list dynamic templates');
            return [];
        }

        $response = $this->sendGridGet('/templates?generations=dynamic&page_size=200');
        if ($response === null) {
            $this->logger->warning('SendGrid templates request failed — returning empty list');
            return [];
        }

        $rawTemplates = $response['result'] ?? $response['templates'] ?? [];
        $rawCount = count($rawTemplates);
        $this->logger->info('SendGrid dynamic templates fetched', ['raw_count' => $rawCount]);

        $templates = [];
        foreach ($rawTemplates as $tpl) {
            if (!is_array($tpl)) {
                continue;
            }

            $id = trim((string) ($tpl['id'] ?? ''));
            $name = trim((string) ($tpl['name'] ?? ''));
            if ($id === '' || $name === '') {
                continue;
            }

            $versionName = null;
            foreach ($tpl['versions'] ?? [] as $version) {
                if (!is_array($version)) {
                    continue;
                }
                if (!empty($version['active'])) {
                    $versionName = trim((string) ($version['name'] ?? 'Active'));
                    break;
                }
            }

            if ($versionName === null || $versionName === '') {
                continue;
            }

            $templates[] = [
                'id' => $id,
                'name' => $name,
                'version_name' => $versionName,
            ];
        }

        usort(
            $templates,
            static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']),
        );

        return $templates;
    }

    /**
     * Send email using a SendGrid dynamic template.
     *
     * @param array<string, mixed> $dynamicData Handlebars variables for the template
     */
    public function sendDynamicTemplateEmail(
        string $to,
        string $toName,
        string $templateId,
        array $dynamicData,
    ): bool {
        if (!$this->sendGridAvailable) {
            $this->logger->error('SendGrid not available — cannot send dynamic template email');
            return false;
        }

        if (!preg_match('/^d-[a-f0-9]+$/', $templateId)) {
            $this->logger->error('Invalid SendGrid template id', ['template_id' => $templateId]);
            return false;
        }

        $from = $this->getSendGridFrom();
        $personalization = [
            'to' => [[
                'email' => $to,
                'name' => $toName !== '' ? $toName : $to,
            ]],
        ];

        if ($dynamicData !== []) {
            $personalization['dynamic_template_data'] = $dynamicData;
        }

        $payload = [
            'personalizations' => [$personalization],
            'from' => [
                'email' => $from['email'],
                'name' => $from['name'],
            ],
            'template_id' => $templateId,
            'tracking_settings' => $this->getSendGridTrackingSettings(),
        ];

        $sent = $this->sendGridMailSend($payload);
        if ($sent) {
            $this->logger->info('SendGrid dynamic template email sent', [
                'to' => $to,
                'template_id' => $templateId,
            ]);
        }

        return $sent;
    }

    /**
     * @return array{email: string, name: string}
     */
    private function getSendGridFrom(): array
    {
        return [
            'email' => (string) ($_ENV['SENDGRID_FROM_EMAIL'] ?? 'noreply@fieldwire.com'),
            'name' => (string) ($_ENV['SENDGRID_FROM_NAME'] ?? 'FieldWire'),
        ];
    }

    /**
     * @return array<string, array<string, bool>>
     */
    private function getSendGridTrackingSettings(): array
    {
        return [
            'click_tracking' => [
                'enable' => false,
                'enable_text' => false,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendGridMailSend(array $payload): bool
    {
        $apiKey = $_ENV['SENDGRID_API_KEY'] ?? '';
        if ($apiKey === '') {
            return false;
        }

        $json = json_encode($payload);
        if ($json === false) {
            $this->logger->error('SendGrid mail payload encoding failed');
            return false;
        }

        $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
        ]);

        $body = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($statusCode >= 200 && $statusCode < 300) {
            return true;
        }

        $this->logger->error('SendGrid mail send failed', [
            'status_code' => $statusCode,
            'body' => is_string($body) ? $body : '',
        ]);
        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sendGridGet(string $path): ?array
    {
        $apiKey = $_ENV['SENDGRID_API_KEY'] ?? '';
        if ($apiKey === '') {
            return null;
        }

        $url = 'https://api.sendgrid.com/v3' . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
        ]);

        $body = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (!is_string($body) || $statusCode < 200 || $statusCode >= 300) {
            $this->logger->error('SendGrid GET request failed', [
                'path' => $path,
                'status_code' => $statusCode,
                'body' => is_string($body) ? $body : '',
            ]);
            return null;
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Send email via SendGrid
     */
    private function sendViaSendGrid(string $to, string $subject, string $message, string $toName = ''): bool
    {
        $from = $this->getSendGridFrom();
        $payload = [
            'personalizations' => [[
                'to' => [[
                    'email' => $to,
                    'name' => $toName !== '' ? $toName : $to,
                ]],
                'subject' => $subject,
            ]],
            'from' => [
                'email' => $from['email'],
                'name' => $from['name'],
            ],
            'subject' => $subject,
            'content' => [[
                'type' => 'text/plain',
                'value' => $message,
            ]],
            'tracking_settings' => $this->getSendGridTrackingSettings(),
        ];

        $sent = $this->sendGridMailSend($payload);
        if ($sent) {
            $this->logger->info('Email sent successfully via SendGrid', [
                'to' => $to,
                'subject' => $subject,
            ]);
        }

        return $sent;
    }

    /**
     * Send email via PHPMailer
     */
    private function sendViaPHPMailer(string $to, string $subject, string $message, string $toName = ''): bool
    {
        try {
            $mail = new PHPMailer(true);

            // Server settings
            $mail->isSMTP();
            $mail->Host = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = $_ENV['SMTP_USERNAME'] ?? '';
            $mail->Password = $_ENV['SMTP_PASSWORD'] ?? '';
            $encryption = $_ENV['SMTP_ENCRYPTION'] ?? 'tls';
            $mail->SMTPSecure = $encryption === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = (int)($_ENV['SMTP_PORT'] ?? 587);

            // Recipients
            $mail->setFrom(
                $_ENV['SMTP_FROM_EMAIL'] ?? 'noreply@fieldwire.com',
                $_ENV['SMTP_FROM_NAME'] ?? 'FieldWire'
            );
            $mail->addAddress($to, $toName ?: $to);

            // Content
            $mail->isHTML(false);
            $mail->Subject = $subject;
            $mail->Body = $message;

            $result = $mail->send();

            if ($result) {
                $this->logger->info('Email sent successfully via PHPMailer', [
                    'to' => $to,
                    'subject' => $subject
                ]);
            }

            return $result;

        } catch (PHPMailerException $e) {
            $this->logger->error('PHPMailer error', [
                'error' => $e->getMessage(),
                'to' => $to
            ]);
            return false;
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error in PHPMailer', [
                'error' => $e->getMessage(),
                'to' => $to
            ]);
            return false;
        }
    }

    /**
     * Send verification code email
     * 
     * @param string $email Recipient email
     * @param string $code Verification code
     * @param string $userName Recipient name
     * @param string $provider Email provider to use
     * @return bool Success status
     */
    public function sendVerificationCode(string $email, string $code, string $userName = 'User', string $provider = 'auto'): bool
    {
        $subject = 'Your FieldWire Verification Code';
        
        try {
            // Initialize Twig
            $loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../Templates/Email');
            $twig = new \Twig\Environment($loader);
            
            // Render HTML template
            $htmlTemplate = $twig->load('2fa-verification.html.twig');
            $htmlMessage = $htmlTemplate->render([
                'userName' => $userName,
                'code' => $code,
                'emailTitle' => '2FA Verification - FieldWire'
            ]);
            
            // Plain text version
            $textMessage = "Hello {$userName}!\n\n";
            $textMessage .= "Your FieldWire Verification Code: {$code}\n\n";
            $textMessage .= "This code expires in 1 minute.\n\n";
            $textMessage .= "Enter this code to complete your login.\n\n";
            $textMessage .= "Security Notice: If you didn't request this code, please ignore this email.\n\n";
            $textMessage .= "Best regards,\nFieldWire Team";

            return $this->sendEmailWithTemplates($email, $subject, $htmlMessage, $textMessage, $userName, $provider);
        } catch (\Exception $e) {
            $this->logger->error('Failed to render 2FA verification template', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Send worker invitation email
     * 
     * @param string $email Recipient email
     * @param string $firstName Recipient first name
     * @param string $lastName Recipient last name
     * @param string $invitationToken Invitation token
     * @param string $provider Email provider to use
     * @return bool Success status
     */
    public function sendWorkerInvitation(string $email, string $firstName, string $lastName, string $invitationToken, string $provider = 'auto', string $tempPassword = ''): bool
    {
        $fullName = trim($firstName . ' ' . $lastName);
        $subject = 'Invitation to join FieldWire';
        
        // Login links must target the SPA, not the API (APP_URL).
        $frontendUrl = FrontendUrl::resolve();
        $loginUrl = $frontendUrl . '/login?token=' . urlencode($invitationToken);
        
        // Prepare template data
        $templateData = [
            'firstName' => $firstName,
            'lastName' => $lastName,
            'email' => $email,
            'jobTitle' => 'Team Member', // Default job title
            'tempPassword' => $tempPassword,
            'invitationToken' => $invitationToken, // Add invitation token
            'loginUrl' => $loginUrl,
            'expiryHours' => 24, // 7 days in hours
            'expiryDate' => date('Y-m-d H:i:s', strtotime('+1 days')),
            'attemptNumber' => 1,
            'appUrl' => $frontendUrl
        ];
        
        try {
            // Initialize Twig
            $loader = new \Twig\Loader\FilesystemLoader(__DIR__ . '/../Templates/Email');
            $twig = new \Twig\Environment($loader);
            
            // Render HTML template (use new template)
            $htmlTemplate = $twig->load('invitation-new.html.twig');
            $htmlContent = $htmlTemplate->render($templateData);
            
            // Render text template
            $textTemplate = $twig->load('invitation.txt.twig');
            $textContent = $textTemplate->render($templateData);
            
            // Send email with both HTML and text content
            return $this->sendEmailWithTemplates($email, $subject, $htmlContent, $textContent, $fullName, $provider);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to render email templates', [
                'error' => $e->getMessage(),
                'email' => $email
            ]);
            
            // Fallback to simple text email
            $message = "Hello {$fullName},\n\n";
            $message .= "You have been invited to join FieldWire as a team member.\n\n";
            $message .= "Your temporary password: {$tempPassword}\n\n";
            $message .= "To login to your account, please click the link below:\n";
            $message .= "{$loginUrl}\n\n";
            $message .= "This invitation will expire in 7 days.\n\n";
            $message .= "If you have any questions, please contact your administrator.\n\n";
            $message .= "Best regards,\nFieldWire Team";

            return $this->sendEmail($email, $subject, $message, $fullName, $provider);
        }
    }

    public function isSendGridAvailable(): bool
    {
        return $this->sendGridAvailable;
    }

    /**
     * Check if email service is properly configured
     */
    public function isConfigured(): bool
    {
        // Check if SendGrid is available
        if ($this->sendGridAvailable) {
            return true;
        }

        // Check if PHPMailer is configured
        $smtpHost = $_ENV['SMTP_HOST'] ?? '';
        $smtpUsername = $_ENV['SMTP_USERNAME'] ?? '';
        $smtpPassword = $_ENV['SMTP_PASSWORD'] ?? '';

        return !empty($smtpHost) && !empty($smtpUsername) && !empty($smtpPassword);
    }

    /**
     * Get available email providers
     * 
     * @return array Available providers with their status
     */
    public function getAvailableProviders(): array
    {
        $providers = [
            'sendgrid' => [
                'name' => 'SendGrid',
                'available' => $this->sendGridAvailable,
                'description' => 'Professional email delivery service'
            ],
            'phpmailer' => [
                'name' => 'PHPMailer',
                'available' => $this->isPHPMailerConfigured(),
                'description' => 'Simple SMTP email sending'
            ]
        ];

        return $providers;
    }

    /**
     * Check if PHPMailer is properly configured
     */
    private function isPHPMailerConfigured(): bool
    {
        $smtpHost = $_ENV['SMTP_HOST'] ?? '';
        $smtpUsername = $_ENV['SMTP_USERNAME'] ?? '';
        $smtpPassword = $_ENV['SMTP_PASSWORD'] ?? '';

        return !empty($smtpHost) && !empty($smtpUsername) && !empty($smtpPassword);
    }

    /**
     * Send email with HTML and text templates
     */
    public function sendEmailWithTemplates(string $email, string $subject, string $htmlContent, string $textContent, string $recipientName, string $provider = 'auto'): bool
    {
        try {
            // Use SendGrid by default for templates, fallback to PHPMailer if not available
            if ($provider === 'phpmailer' || ($provider === 'auto' && !$this->sendGridAvailable)) {
                return $this->sendWithPHPMailerTemplates($email, $subject, $htmlContent, $textContent, $recipientName);
            } else {
                // Default to SendGrid for better deliverability
                return $this->sendWithSendGridTemplates($email, $subject, $htmlContent, $textContent, $recipientName);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to send email with templates', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Send email with SendGrid using templates
     */
    private function sendWithSendGridTemplates(string $email, string $subject, string $htmlContent, string $textContent, string $recipientName): bool
    {
        $from = $this->getSendGridFrom();
        $payload = [
            'personalizations' => [[
                'to' => [[
                    'email' => $email,
                    'name' => $recipientName !== '' ? $recipientName : $email,
                ]],
                'subject' => $subject,
            ]],
            'from' => [
                'email' => $from['email'],
                'name' => $from['name'],
            ],
            'subject' => $subject,
            'content' => [
                ['type' => 'text/plain', 'value' => $textContent],
                ['type' => 'text/html', 'value' => $htmlContent],
            ],
            'tracking_settings' => $this->getSendGridTrackingSettings(),
        ];

        $sent = $this->sendGridMailSend($payload);
        if ($sent) {
            $this->logger->info('Email sent via SendGrid with templates', [
                'email' => $email,
            ]);
        }

        return $sent;
    }

    /**
     * Send email with PHPMailer using templates
     */
    private function sendWithPHPMailerTemplates(string $email, string $subject, string $htmlContent, string $textContent, string $recipientName): bool
    {
        try {
            $mail = new PHPMailer(true);

            // Server settings
            $mail->isSMTP();
            $mail->Host = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = $_ENV['SMTP_USERNAME'] ?? '';
            $mail->Password = $_ENV['SMTP_PASSWORD'] ?? '';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $_ENV['SMTP_PORT'] ?? 587;

            // Recipients
            $mail->setFrom($_ENV['SMTP_FROM_EMAIL'] ?? 'noreply@fieldwire.com', $_ENV['SMTP_FROM_NAME'] ?? 'FieldWire Team');
            $mail->addAddress($email, $recipientName);

            // Content
            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            $mail->Subject = $subject;
            $mail->Body = $htmlContent;
            $mail->AltBody = $textContent;

            $mail->send();
            
            $this->logger->info('Email sent via PHPMailer with templates', [
                'email' => $email
            ]);

            return true;
        } catch (PHPMailerException $e) {
            $this->logger->error('PHPMailer template email failed', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
