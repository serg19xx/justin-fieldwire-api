<?php

namespace App\Services;

use Monolog\Logger;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use SendGrid\Mail\Mail;
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
                // Always use PHPMailer by default
                return $this->sendViaPHPMailer($to, $subject, $message, $toName);
        }
    }

    /**
     * Send email via SendGrid
     */
    private function sendViaSendGrid(string $to, string $subject, string $message, string $toName = ''): bool
    {
        try {
            $email = new Mail();
            $email->setFrom(
                $_ENV['SENDGRID_FROM_EMAIL'] ?? 'noreply@fieldwire.com',
                $_ENV['SENDGRID_FROM_NAME'] ?? 'FieldWire'
            );
            $email->setSubject($subject);
            $email->addTo($to, $toName ?: $to);
            $email->addContent("text/plain", $message);

            $response = $this->sendGrid->send($email);

            if ($response->statusCode() === 202) {
                $this->logger->info('Email sent successfully via SendGrid', [
                    'to' => $to,
                    'subject' => $subject,
                    'status_code' => $response->statusCode()
                ]);
                return true;
            } else {
                $this->logger->error('SendGrid email failed', [
                    'to' => $to,
                    'status_code' => $response->statusCode(),
                    'body' => $response->body()
                ]);
                return false;
            }

        } catch (\Exception $e) {
            $this->logger->error('SendGrid error', [
                'error' => $e->getMessage(),
                'to' => $to
            ]);
            return false;
        }
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
        $subject = 'FieldWire Verification Code';
        $message = "Hello {$userName},\n\n";
        $message .= "Your FieldWire verification code is: {$code}\n\n";
        $message .= "This code is valid for 10 minutes.\n\n";
        $message .= "If you didn't request this code, please ignore this email.\n\n";
        $message .= "Best regards,\nFieldWire Team";

        return $this->sendEmail($email, $subject, $message, $userName, $provider);
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
        
        // Create login URL with invitation code - use frontend URL, not API URL
        $frontendUrl = $_ENV['FRONTEND_URL'] ?? $_ENV['APP_URL'] ?? 'http://localhost:3000';
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
            
            // Render HTML template
            $htmlTemplate = $twig->load('invitation.html.twig');
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
    private function sendEmailWithTemplates(string $email, string $subject, string $htmlContent, string $textContent, string $recipientName, string $provider = 'auto'): bool
    {
        try {
            // Always use PHPMailer by default, only use SendGrid if explicitly requested
            if ($provider === 'sendgrid' && $this->sendGridAvailable) {
                return $this->sendWithSendGridTemplates($email, $subject, $htmlContent, $textContent, $recipientName);
            } else {
                return $this->sendWithPHPMailerTemplates($email, $subject, $htmlContent, $textContent, $recipientName);
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
        try {
            $fromEmail = $_ENV['SENDGRID_FROM_EMAIL'] ?? 'noreply@fieldwire.com';
            $fromName = $_ENV['SENDGRID_FROM_NAME'] ?? 'FieldWire Team';

            $mail = new Mail();
            $mail->setFrom($fromEmail, $fromName);
            $mail->setSubject($subject);
            $mail->addTo($email, $recipientName);
            $mail->addContent("text/plain", $textContent);
            $mail->addContent("text/html", $htmlContent);

            $response = $this->sendGrid->send($mail);
            
            $this->logger->info('Email sent via SendGrid with templates', [
                'email' => $email,
                'status_code' => $response->statusCode()
            ]);

            return $response->statusCode() >= 200 && $response->statusCode() < 300;
        } catch (\Exception $e) {
            $this->logger->error('SendGrid template email failed', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            return false;
        }
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
