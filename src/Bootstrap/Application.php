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
        file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Application constructor called' . PHP_EOL, FILE_APPEND);
        try {
            $this->config = $config;
            file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Config assigned' . PHP_EOL, FILE_APPEND);
            
            $this->initializeLogger();
            file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Logger initialized' . PHP_EOL, FILE_APPEND);
            
            $this->initializeFlight();
            file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Flight initialized' . PHP_EOL, FILE_APPEND);
            
            file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Application constructor completed' . PHP_EOL, FILE_APPEND);
        } catch (Exception $e) {
            file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - ERROR in Application constructor: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
            throw $e;
        }
    }

    private function initializeLogger(): void
    {
        file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - initializeLogger started' . PHP_EOL, FILE_APPEND);
        
        try {
            $this->logger = new Logger('fieldwire-api');
            file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Logger created' . PHP_EOL, FILE_APPEND);
            
            $logLevel = $this->getLogLevel();
            $logFile = $this->config->get('LOG_FILE', 'logs/app.log');
            file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Log file: ' . $logFile . ', Level: ' . $logLevel->value . PHP_EOL, FILE_APPEND);
            
            $this->logger->pushHandler(new StreamHandler($logFile, $logLevel));
            file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - StreamHandler added' . PHP_EOL, FILE_APPEND);
            
            // Set logger for database
            \App\Database\Database::setLogger($this->logger);
            file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Database logger set' . PHP_EOL, FILE_APPEND);
        } catch (Exception $e) {
            file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - ERROR in initializeLogger: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
            throw $e;
        }
    }

    private function initializeFlight(): void
    {
        file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - initializeFlight started' . PHP_EOL, FILE_APPEND);
        
        // Initialize Flight framework
        Flight::init();
        file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Flight initialized' . PHP_EOL, FILE_APPEND);
        
        // Register middleware
        Flight::before('start', [new CorsMiddleware($this->config), 'handle']);
        file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - Middleware registered' . PHP_EOL, FILE_APPEND);
        
        // Register routes with logger
        file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - About to create ApiRoutes' . PHP_EOL, FILE_APPEND);
        try {
            (new ApiRoutes($this->logger))->register();
            file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - ApiRoutes created and registered' . PHP_EOL, FILE_APPEND);
        } catch (Exception $e) {
            file_put_contents('logs/app.log', date('Y-m-d H:i:s') . ' - ERROR creating ApiRoutes: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
            throw $e;
        }
    }

    private function getLogLevel(): Level
    {
        $level = strtoupper($this->config->get('LOG_LEVEL', 'INFO'));
        
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
