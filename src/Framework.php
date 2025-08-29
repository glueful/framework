<?php

declare(strict_types=1);

namespace Glueful;

use Glueful\DI\Container;
use Glueful\Http\Request;
use Glueful\Http\Response;
use Psr\Log\LoggerInterface;

class Framework
{
    private string $basePath;
    private string $configPath;
    private string $environment;
    private ?Container $container = null;
    private bool $booted = false;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/');
        $this->configPath = $this->basePath . '/config';
        $this->environment = $_ENV['APP_ENV'] ?? 'production';
    }

    public static function create(string $basePath): self
    {
        return new self($basePath);
    }

    public function withConfigDir(string $configDir): self
    {
        $clone = clone $this;
        $clone->configPath = $configDir;
        return $clone;
    }

    public function withEnvironment(string $env): self
    {
        $clone = clone $this;
        $clone->environment = $env;
        return $clone;
    }

    public function boot(): Application
    {
        if ($this->booted) {
            throw new \RuntimeException('Framework already booted');
        }

        // Initialize container and core services
        $this->container = $this->createContainer();

        // Create and return Application instance
        $app = new Application($this->container);
        $app->initialize();

        $this->booted = true;
        return $app;
    }

    private function createContainer(): Container
    {
        // Load global helper functions (env, config, etc.)
        require_once dirname(__DIR__) . '/helpers.php';

        // Initialize Dependency Injection Container with config hierarchy
        $container = \Glueful\DI\ContainerBootstrap::initialize(
            $this->basePath,           // /path/to/my-app
            $this->configPath,         // /path/to/my-app/config
            $this->environment         // development/production/etc
        );

        // Make container globally available (for helper functions)
        $GLOBALS['container'] = $container;

        // Validate security configuration in production
        if ($this->environment === 'production') {
            \Glueful\Security\SecurityManager::validateProductionEnvironment();
        }

        // Register exception handler
        \Glueful\Exceptions\ExceptionHandler::register();

        // Initialize Cache Driver
        \Glueful\Helpers\Utils::initializeCacheDriver();

        // Initialize API versioning
        $apiVersion = config('app.api_version', 'v1');
        \Glueful\Http\Router::setVersion($apiVersion);

        // Enable cache services
        \Glueful\Cache\CacheTaggingService::enable();
        \Glueful\Cache\CacheInvalidationService::enable();
        \Glueful\Cache\CacheInvalidationService::warmupPatterns();

        // Enable development features
        if ($this->environment === 'development') {
            if (env('ENABLE_QUERY_MONITORING', true)) {
                \Glueful\Database\DevelopmentQueryMonitor::enable();
            }

            if (env('APP_DEBUG', false) && class_exists(\Symfony\Component\VarDumper\VarDumper::class)) {
                \Symfony\Component\VarDumper\VarDumper::setHandler(function ($var) {
                    $cloner = new \Symfony\Component\VarDumper\Cloner\VarCloner();
                    $dumper = 'cli' === PHP_SAPI
                        ? new \Symfony\Component\VarDumper\Dumper\CliDumper()
                        : new \Symfony\Component\VarDumper\Dumper\HtmlDumper();
                    $dumper->dump($cloner->cloneVar($var));
                });
            }
        }

        // Validate database connection on startup
        if (PHP_SAPI !== 'cli' && env('DB_STARTUP_VALIDATION', true) && !env('SKIP_DB_VALIDATION', false)) {
            \Glueful\Database\ConnectionValidator::validateOnStartup(
                throwOnFailure: env('DB_STARTUP_STRICT', false)
            );
        }

        return $container;
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    public function getConfigPath(): string
    {
        return $this->configPath;
    }

    public function getEnvironment(): string
    {
        return $this->environment;
    }
}
