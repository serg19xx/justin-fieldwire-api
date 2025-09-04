<?php

namespace App\Middleware;

use App\Config\Config;
use Exception;
use Flight;
use Monolog\Logger;

class ErrorHandlerMiddleware
{
    private Config $config;
    private Logger $logger;

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->logger = Flight::get('logger');
    }

    public function handle(): void
    {
        // Set error handler
        set_error_handler([$this, 'handleError']);
        set_exception_handler([$this, 'handleException']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    public function handleException(\Throwable $exception): void
    {
        $this->logger->error('Uncaught Exception', [
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);

        $statusCode = 500;
        $message = 'Internal Server Error';

        if ($this->config->get('app.debug', false)) {
            $message = $exception->getMessage();
        }

        $this->sendErrorResponse($statusCode, $message);
    }

    public function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        if (!(error_reporting() & $errno)) {
            return false;
        }

        $this->logger->error('PHP Error', [
            'errno' => $errno,
            'errstr' => $errstr,
            'errfile' => $errfile,
            'errline' => $errline,
        ]);

        if ($this->config->get('app.debug', false)) {
            throw new Exception("PHP Error: {$errstr} in {$errfile} on line {$errline}");
        }

        $this->sendErrorResponse(500, 'Internal Server Error');
        return true;
    }



    public function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $this->logger->critical('Fatal Error', [
                'type' => $error['type'],
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line'],
            ]);

            $this->sendErrorResponse(500, 'Internal Server Error');
        }
    }

    private function sendErrorResponse(int $statusCode, string $message): void
    {
        http_response_code($statusCode);

        $response = [
            'error' => [
                'code' => $statusCode,
                'message' => $message,
            ],
        ];

        if ($this->config->get('app.debug', false)) {
            $response['error']['debug'] = [
                'file' => debug_backtrace()[0]['file'] ?? null,
                'line' => debug_backtrace()[0]['line'] ?? null,
            ];
        }

        header('Content-Type: application/json');
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit();
    }
}
