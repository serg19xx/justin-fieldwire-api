<?php

namespace App\Services;

use Twilio\Rest\Client;
use Twilio\Exceptions\TwilioException;
use Monolog\Logger;

class TwilioService
{
    private ?Client $client = null;
    private string $fromNumber = '';
    private Logger $logger;

    /**
     * Safely write to log file, creating directory if needed
     */
    private function safeLog(string $message): void
    {
        $logDir = 'logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        file_put_contents($logDir . '/app.log', date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND);
    }

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        
        $accountSid = $_ENV['TWILIO_ACCOUNT_SID'] ?? '';
        $authToken = $_ENV['TWILIO_AUTH_TOKEN'] ?? '';
        $this->fromNumber = $_ENV['TWILIO_PHONE_NUMBER'] ?? '';

        $this->logger->info('TwilioService constructor called', [
            'account_sid_length' => strlen($accountSid),
            'auth_token_length' => strlen($authToken),
            'from_number' => $this->fromNumber,
            'app_env' => $_ENV['APP_ENV'] ?? 'not_set'
        ]);
        
        // Direct file logging for debugging
        $this->safeLog('TwilioService constructor called');

        // Missing credentials: keep API bootable; SMS methods no-op / mock until configured.
        if (empty($accountSid) || empty($authToken) || empty($this->fromNumber)) {
            $this->logger->warning('Twilio credentials not set, SMS features run in mock/disabled mode');
            $this->safeLog('Twilio credentials not set, running in mock mode');
            return;
        }

        try {
            $this->client = new Client($accountSid, $authToken);
            $this->logger->info('Twilio client initialized successfully');
            $this->safeLog('Twilio client initialized successfully');
        } catch (\Exception $e) {
            $this->client = null;
            $this->logger->error('Failed to initialize Twilio client, SMS features run in mock/disabled mode', [
                'error' => $e->getMessage(),
            ]);
            $this->safeLog('Failed to initialize Twilio client (mock mode): ' . $e->getMessage());
        }
    }

    /**
     * Generate a 6-digit verification code
     */
    public function generateVerificationCode(): string
    {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Send SMS with verification code
     */
    public function sendVerificationCode(string $phoneNumber, string $code): bool
    {
        $body = "Your FieldWire verification code is: {$code}. Valid for 10 minutes.";
        return $this->sendSms($phoneNumber, $body);
    }

    /**
     * Send a plain SMS message.
     */
    public function sendSms(string $phoneNumber, string $body): bool
    {
        try {
            $formattedPhone = $this->formatPhoneNumber($phoneNumber);
            $body = trim($body);
            if ($body === '') {
                $this->logger->warning('Refusing to send empty SMS body', ['to' => $phoneNumber]);
                return false;
            }

            $this->logger->info('Attempting to send SMS', [
                'original_phone' => $phoneNumber,
                'formatted_phone' => $formattedPhone,
                'from_number' => $this->fromNumber,
                'client_available' => $this->client !== null,
            ]);

            $accountSid = $_ENV['TWILIO_ACCOUNT_SID'] ?? '';
            $authToken = $_ENV['TWILIO_AUTH_TOKEN'] ?? '';
            if ($accountSid === '' || $authToken === '' || $this->fromNumber === '') {
                $this->logger->info('MOCK SMS: Message would be sent', [
                    'to' => $formattedPhone,
                    'body' => $body,
                ]);
                $this->safeLog('MOCK SMS: Message would be sent to ' . $formattedPhone);
                return true;
            }

            return $this->sendSmsViaRestApi($accountSid, $authToken, $formattedPhone, $this->fromNumber, $body);
        } catch (TwilioException $e) {
            $this->logger->error('Failed to send SMS', [
                'to' => $phoneNumber,
                'error' => $e->getMessage(),
                'code' => $e->getCode(),
            ]);
            return false;
        } catch (\Throwable $e) {
            $this->logger->error('Unexpected error sending SMS', [
                'to' => $phoneNumber,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function sendSmsViaRestApi(
        string $accountSid,
        string $authToken,
        string $to,
        string $from,
        string $body,
    ): bool {
        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($accountSid) . '/Messages.json';
        $postFields = http_build_query([
            'To' => $to,
            'From' => $from,
            'Body' => $body,
        ]);

        $ch = curl_init($url);
        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_USERPWD => $accountSid . ':' . $authToken,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $response = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($statusCode >= 200 && $statusCode < 300 && is_string($response)) {
            $data = json_decode($response, true);
            $this->logger->info('SMS sent successfully', [
                'to' => $to,
                'message_sid' => is_array($data) ? ($data['sid'] ?? null) : null,
                'status' => is_array($data) ? ($data['status'] ?? null) : null,
            ]);
            return true;
        }

        $errorMessage = is_string($response) ? $response : '';
        if (is_string($response)) {
            $data = json_decode($response, true);
            if (is_array($data) && isset($data['message'])) {
                $errorMessage = (string) $data['message'];
            }
        }

        $this->logger->error('Failed to send SMS via Twilio REST', [
            'to' => $to,
            'status_code' => $statusCode,
            'error' => $errorMessage,
        ]);
        return false;
    }

    /**
     * Send welcome SMS
     */
    public function sendWelcomeSMS(string $phoneNumber, string $userName): bool
    {
        try {
            $formattedPhone = $this->formatPhoneNumber($phoneNumber);
            
            $message = $this->client->messages->create(
                $formattedPhone,
                [
                    'from' => $this->fromNumber,
                    'body' => "Welcome to FieldWire, {$userName}! Your account has been successfully created."
                ]
            );

            $this->logger->info('Welcome SMS sent successfully', [
                'to' => $formattedPhone,
                'user_name' => $userName,
                'message_sid' => $message->sid
            ]);

            return true;

        } catch (TwilioException $e) {
            $this->logger->error('Failed to send welcome SMS', [
                'to' => $phoneNumber,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Send password reset SMS
     */
    public function sendPasswordResetSMS(string $phoneNumber, string $resetCode): bool
    {
        try {
            $formattedPhone = $this->formatPhoneNumber($phoneNumber);
            
            $message = $this->client->messages->create(
                $formattedPhone,
                [
                    'from' => $this->fromNumber,
                    'body' => "Your FieldWire password reset code is: {$resetCode}. Valid for 15 minutes."
                ]
            );

            $this->logger->info('Password reset SMS sent successfully', [
                'to' => $formattedPhone,
                'message_sid' => $message->sid
            ]);

            return true;

        } catch (TwilioException $e) {
            $this->logger->error('Failed to send password reset SMS', [
                'to' => $phoneNumber,
                'error' => $e->getMessage()
            ]);

            return false;
        }
    }

    /**
     * Format phone number to international format
     */
    private function formatPhoneNumber(string $phoneNumber): string
    {
        // Remove all non-digit characters
        $cleaned = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        // If it starts with 1 and has 11 digits, it's a US number
        if (strlen($cleaned) === 11 && substr($cleaned, 0, 1) === '1') {
            return '+' . $cleaned;
        }
        
        // If it has 10 digits, assume it's a US number and add +1
        if (strlen($cleaned) === 10) {
            return '+1' . $cleaned;
        }
        
        // If it already starts with +, return as is
        if (substr($phoneNumber, 0, 1) === '+') {
            return $phoneNumber;
        }
        
        // Otherwise, add + prefix
        return '+' . $cleaned;
    }

    /**
     * Validate phone number format
     */
    public function validatePhoneNumber(string $phoneNumber): bool
    {
        $formatted = $this->formatPhoneNumber($phoneNumber);
        
        // Basic validation: should start with + and have at least 10 digits
        return preg_match('/^\+[1-9]\d{9,14}$/', $formatted);
    }

    /**
     * Get message status
     */
    public function getMessageStatus(string $messageSid): ?string
    {
        try {
            $message = $this->client->messages($messageSid)->fetch();
            return $message->status;
        } catch (TwilioException $e) {
            $this->logger->error('Failed to get message status', [
                'message_sid' => $messageSid,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
