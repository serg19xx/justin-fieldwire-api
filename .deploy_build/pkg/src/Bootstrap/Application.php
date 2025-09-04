<?php

namespace App\Bootstrap;

use App\Config\Config;
use App\Middleware\CorsMiddleware;
use App\Routes\ApiRoutes;
use Flight;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

class Application
{
    private Config $config;
    private Logger $logger;

    /**
     * Safely write to log file, creating directory if needed
     */
    private function safeLog(string $message): void
    {
        $logDir = __DIR__ . '/../../logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        file_put_contents($logDir . '/app.log', date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND);
    }

    public function __construct(Config $config)
    {
        try {
            $this->config = $config;
            
            $this->initializeLogger();
            
            $this->initializeFlight();
            
            $this->safeLog('Application constructor completed');
        } catch (\Exception $e) {
            $this->safeLog('ERROR in Application constructor: ' . $e->getMessage());
            throw $e;
        }
    }

    private function initializeLogger(): void
    {
        try {
            $this->logger = new Logger('fieldwire-api');
            
            $logLevel = $this->getLogLevel();
            $logFile = $this->config->get('logging.log_file', 'logs/app.log');
            // Используем абсолютный путь для лог файла
            $absoluteLogFile = __DIR__ . '/../../' . $logFile;
            
            $this->logger->pushHandler(new StreamHandler($absoluteLogFile, $logLevel));
            
            // Set logger for database
            \App\Database\Database::setLogger($this->logger);
        } catch (\Exception $e) {
            $this->safeLog('ERROR in initializeLogger: ' . $e->getMessage());
            throw $e;
        }
    }

    private function initializeFlight(): void
    {
        try {
            // Initialize Flight framework
            Flight::init();
            
            // Register CORS middleware BEFORE routes
            Flight::before('start', [new CorsMiddleware($this->config), 'handle']);
            
            // Register routes with logger
            try {
                $apiRoutes = new \App\Routes\ApiRoutes($this->logger);
                $this->safeLog('ApiRoutes created and registered');
            } catch (\Exception $e) {
                $this->safeLog('ERROR creating ApiRoutes: ' . $e->getMessage());
                throw $e;
            }
            
        } catch (\Exception $e) {
            $this->safeLog('ERROR in initializeFlight: ' . $e->getMessage());
            throw $e;
        }
    }

    private function getLogLevel(): Level
    {
        $level = strtoupper($this->config->get('logging.level', 'INFO'));
        
        return match ($level) {
            'DEBUG' => Level::Debug,
            'INFO' => Level::Info,
            'WARNING', 'WARN' => Level::Warning,
            'ERROR' => Level::Error,
            'CRITICAL' => Level::Critical,
            'ALERT' => Level::Alert,
            'EMERGENCY' => Level::Emergency,
            default => Level::Info,
        };
    }

    public function getLogger(): Logger
    {
        return $this->logger;
    }

    public function getConfig(): Config
    {
        return $this->config;
    }
}
