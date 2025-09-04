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

    public function __construct(Config $config)
    {
        try {
            $this->config = $config;
            
            $this->initializeLogger();
            
            $this->initializeFlight();
            
            file_put_contents(__DIR__ . '/../../logs/app.log', date('Y-m-d H:i:s') . ' - Application constructor completed' . PHP_EOL, FILE_APPEND);
        } catch (\Exception $e) {
            file_put_contents(__DIR__ . '/../../logs/app.log', date('Y-m-d H:i:s') . ' - ERROR in Application constructor: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
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
            file_put_contents(__DIR__ . '/../../logs/app.log', date('Y-m-d H:i:s') . ' - ERROR in initializeLogger: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
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
                file_put_contents(__DIR__ . '/../../logs/app.log', date('Y-m-d H:i:s') . ' - ApiRoutes created and registered' . PHP_EOL, FILE_APPEND);
            } catch (\Exception $e) {
                file_put_contents(__DIR__ . '/../../logs/app.log', date('Y-m-d H:i:s') . ' - ERROR creating ApiRoutes: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
                throw $e;
            }
            
        } catch (\Exception $e) {
            file_put_contents(__DIR__ . '/../../logs/app.log', date('Y-m-d H:i:s') . ' - ERROR in initializeFlight: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
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
