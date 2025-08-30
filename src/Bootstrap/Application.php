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
        $this->config = $config;
        $this->initializeLogger();
        $this->initializeFlight();
    }

    private function initializeLogger(): void
    {
        $this->logger = new Logger('fieldwire-api');
        
        $logLevel = $this->getLogLevel();
        $logFile = $this->config->get('LOG_FILE', 'logs/app.log');
        
        $this->logger->pushHandler(new StreamHandler($logFile, $logLevel));
    }

    private function initializeFlight(): void
    {
        // Initialize Flight framework
        Flight::init();
        
        // Register middleware
        Flight::before('start', [new CorsMiddleware($this->config), 'handle']);
        
        // Register routes
        (new ApiRoutes())->register();
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
