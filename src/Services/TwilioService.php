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
        file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - TwilioService constructor called' . PHP_EOL, FILE_APPEND);

        // In development, allow mock mode if Twilio credentials are not set
        if (empty($accountSid) || empty($authToken) || empty($this->fromNumber)) {
            if (($_ENV['APP_ENV'] ?? '') === 'development') {
                $this->logger->warning('Twilio credentials not set, running in mock mode');
                file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Twilio credentials not set, running in mock mode' . PHP_EOL, FILE_APPEND);
                return;
            } else {
                throw new \RuntimeException('Twilio configuration is incomplete. Please check TWILIO_ACCOUNT_SID, TWILIO_AUTH_TOKEN, and TWILIO_PHONE_NUMBER environment variables.');
            }
        }

        try {
            $this->client = new Client($accountSid, $authToken);
            $this->logger->info('Twilio client initialized successfully');
            file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Twilio client initialized successfully' . PHP_EOL, FILE_APPEND);
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize Twilio client', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Failed to initialize Twilio client: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
            throw $e;
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
        try {
            // Format phone number (ensure it starts with +)
            $formattedPhone = $this->formatPhoneNumber($phoneNumber);
            
            $this->logger->info('Attempting to send SMS verification code', [
                'original_phone' => $phoneNumber,
                'formatted_phone' => $formattedPhone,
                'code' => $code,
                'from_number' => $this->fromNumber,
                'client_available' => $this->client !== null
            ]);
            
            // If Twilio client is not available (development mode), just log the SMS
            if ($this->client === null) {
                $this->logger->info('MOCK SMS: Verification code would be sent', [
                    'to' => $formattedPhone,
                    'code' => $code,
                    'message' => "Your FieldWire verification code is: {$code}. Valid for 10 minutes."
                ]);
                file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - MOCK SMS: Verification code would be sent to ' . $formattedPhone . ' with code ' . $code . PHP_EOL, FILE_APPEND);
                return true;
            }
            
            $this->logger->info('Creating Twilio message', [
                'to' => $formattedPhone,
                'from' => $this->fromNumber,
                'body' => "Your FieldWire verification code is: {$code}. Valid for 10 minutes."
            ]);
            
            $message = $this->client->messages->create(
                $formattedPhone,
                [
                    'from' => $this->fromNumber,
                    'body' => "Your FieldWire verification code is: {$code}. Valid for 10 minutes."
                ]
            );

            $this->logger->info('SMS verification code sent successfully', [
                'to' => $formattedPhone,
                'message_sid' => $message->sid,
                'status' => $message->status,
                'error_code' => $message->errorCode ?? null,
                'error_message' => $message->errorMessage ?? null
            ]);

            return true;

        } catch (TwilioException $e) {
            $this->logger->error('Failed to send SMS verification code', [
                'to' => $phoneNumber,
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);

            return false;
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error sending SMS', [
                'to' => $phoneNumber,
                'error' => $e->getMessage()
            ]);

            return false;
        }
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
