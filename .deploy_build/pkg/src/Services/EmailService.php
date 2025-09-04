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
     * Send email using SendGrid (primary) or PHPMailer (fallback)
     */
    public function sendEmail(string $to, string $subject, string $message, string $toName = ''): bool
    {
        // Try SendGrid first
        if ($this->sendGridAvailable) {
            $sent = $this->sendViaSendGrid($to, $subject, $message, $toName);
            if ($sent) {
                return true;
            }
            
            $this->logger->warning('SendGrid failed, trying PHPMailer fallback');
        }

        // Fallback to PHPMailer
        return $this->sendViaPHPMailer($to, $subject, $message, $toName);
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
     */
    public function sendVerificationCode(string $email, string $code, string $userName = 'User'): bool
    {
        $subject = 'FieldWire Verification Code';
        $message = "Hello {$userName},\n\n";
        $message .= "Your FieldWire verification code is: {$code}\n\n";
        $message .= "This code is valid for 10 minutes.\n\n";
        $message .= "If you didn't request this code, please ignore this email.\n\n";
        $message .= "Best regards,\nFieldWire Team";

        return $this->sendEmail($email, $subject, $message, $userName);
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
}
