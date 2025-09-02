<?php

declare(strict_types=1);

namespace Glueful;

use Glueful\DI\Container;

class Framework
{
    private string $basePath;
    private string $configPath;
    private string $environment;
    private ?Container $container = null;
    private ?Application $application = null;
    private bool $booted = false;
    private bool $strictMode = true;

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

    public function boot(bool $allowReboot = false): Application
    {
        if ($this->booted) {
            if (!$allowReboot && $this->strictMode) {
                throw new \RuntimeException('Framework already booted');
            }
            return $this->application; // Return existing application
        }

        // Initialize container and core services
        $this->container = $this->createContainer();

        // Create and return Application instance
        $this->application = new Application($this->container);
        $this->application->initialize();

        $this->booted = true;
        return $this->application;
    }

    private function createContainer(): Container
    {
        // Helpers are loaded via Composer autoload (autoload.files)

        // Initialize Dependency Injection Container with config hierarchy
        $container = \Glueful\DI\ContainerBootstrap::initialize(
            $this->basePath,           // /path/to/my-app
            $this->configPath,         // /path/to/my-app/config
            $this->environment         // development/production/etc
        );

        // Make container globally available (for helper functions)
        $GLOBALS['container'] = $container;

        // Log container compilation details for production visibility
        try {
            /** @var \Psr\Log\LoggerInterface $logger */
            $logger = $container->get(\Psr\Log\LoggerInterface::class);
            $info = [
                'env' => (string) config('app.env', $this->environment),
                'compiled' => \Glueful\DI\ContainerFactory::hasCompiledContainer(),
            ];
            if ($info['compiled']) {
                // Derive current compiled file path
                $ref = new \ReflectionClass(\Glueful\DI\ContainerFactory::class);
                $m = $ref->getMethod('getCompiledContainerPath');
                $m->setAccessible(true);
                $info['file'] = $m->invoke(null);
            }
            $logger->info('DI container initialized', ['container' => $info]);
        } catch (\Throwable) {
            // Best-effort logging only
        }

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
            if ((bool) env('ENABLE_QUERY_MONITORING', true)) {
                \Glueful\Database\DevelopmentQueryMonitor::enable();
            }

            if ((bool) env('APP_DEBUG', false) && class_exists(\Symfony\Component\VarDumper\VarDumper::class)) {
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
        if (
            PHP_SAPI !== 'cli' &&
            (bool) env('DB_STARTUP_VALIDATION', true) &&
            !(bool) env('SKIP_DB_VALIDATION', false)
        ) {
            \Glueful\Database\ConnectionValidator::validateOnStartup(
                throwOnFailure: (bool) env('DB_STARTUP_STRICT', false)
            );
        }

        // Configure structured logging with standard fields
        $this->configureStructuredLogging($container);

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

    public function isBooted(): bool
    {
        return $this->booted;
    }

    public function setStrictMode(bool $strict): self
    {
        $this->strictMode = $strict;
        return $this;
    }

    public function getContainer(): ?Container
    {
        return $this->container;
    }

    public function getApplication(): ?Application
    {
        return $this->application;
    }

    private function configureStructuredLogging(Container $container): void
    {
        try {
            $logger = $container->get(\Psr\Log\LoggerInterface::class);

            // Add standard log processor for consistent structured fields
            if ($logger instanceof \Monolog\Logger) {
                $userIdResolver = function (): ?string {
                    try {
                        // Use global function if available (but may not exist yet)
                        if (function_exists('auth')) {
                            /** @var callable():object|null $authFunction */
                            $authFunction = 'auth';
                            $auth = $authFunction();
                            if (
                                is_object($auth) &&
                                method_exists($auth, 'check') &&
                                method_exists($auth, 'id') &&
                                $auth->check()
                            ) {
                                return (string) $auth->id();
                            }
                        }
                    } catch (\Throwable) {
                        // Ignore auth errors during logging
                    }
                    return null;
                };

                $logger->pushProcessor(new \Glueful\Logging\StandardLogProcessor(
                    $this->environment,
                    $this->getFrameworkVersion(),
                    $userIdResolver
                ));
            }
        } catch (\Throwable $e) {
            // Don't let logging configuration break the application
            error_log("Failed to configure structured logging: " . $e->getMessage());
        }
    }

    private function getFrameworkVersion(): string
    {
        if (class_exists('Composer\\InstalledVersions')) {
            try {
                $version = \Composer\InstalledVersions::getPrettyVersion('glueful/framework');
                if (is_string($version) && $version !== '') {
                    return $version;
                }
            } catch (\Throwable) {
                // ignore and fallback
            }
        }
        $v = config('app.version_full', '1.0.0');
        return is_string($v) ? $v : '1.0.0';
    }
}
