<?php

declare(strict_types=1);

namespace Glueful;

use Glueful\Bootstrap\ConfigurationCache;
use Glueful\Bootstrap\ConfigurationLoader;
use Glueful\Bootstrap\BootProfiler;
use Psr\Container\ContainerInterface;
use Glueful\Container\Support\LazyInitializer;
use Glueful\Container\Bootstrap\ContainerFactory;
use Glueful\Routing\RouteManifest;
use Glueful\Cache\CacheTaggingService;
use Glueful\Cache\CacheInvalidationService;
use Glueful\Database\ConnectionValidator;
use Glueful\Database\DevelopmentQueryMonitor;
use Glueful\Exceptions\ExceptionHandler;
use Glueful\Helpers\Utils;
use Glueful\Security\SecurityManager;
use Psr\Log\LoggerInterface;

/**
 * Consolidated Framework class - handles all bootstrapping logic
 * Merges functionality from Framework, FrameworkBootstrap, and ApplicationKernel
 */
class Framework
{
    private string $basePath;
    private string $configPath;
    private string $environment;
    private ?ContainerInterface $container = null;
    private ?Application $application = null;
    private bool $booted = false;
    private bool $strictMode = true;
    private ?LazyInitializer $lazyInitializer = null;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/');
        $this->configPath = $this->basePath . '/config';
        $this->environment = $_ENV['APP_ENV'] ?? 'production';
    }

    /**
     * Create a new Framework instance
     */
    public static function create(string $basePath): self
    {
        return new self($basePath);
    }

    /**
     * Set configuration directory
     */
    public function withConfigDir(string $configDir): self
    {
        $clone = clone $this;
        $clone->configPath = $configDir;
        return $clone;
    }

    /**
     * Set environment
     */
    public function withEnvironment(string $env): self
    {
        $clone = clone $this;
        $clone->environment = $env;
        return $clone;
    }

    /**
     * Boot the framework with optimized phased initialization
     */
    public function boot(bool $allowReboot = false): Application
    {
        if ($this->booted) {
            if (!$allowReboot && $this->strictMode) {
                throw new \RuntimeException('Framework already booted');
            }
            return $this->application;
        }

        $profiler = new BootProfiler();

        // Phase 1: Environment & Globals (0-2ms)
        $profiler->time('environment', fn() => $this->initializeEnvironment());

        // Phase 2: Configuration (now instant!) - Just initialize lazy loading
        $profiler->time('config', fn() => $this->initializeConfiguration());

        // Phase 3: Container (5-8ms) - Now config() will work during container build
        $profiler->time('container', fn() => $this->buildContainer());

        // Phase 4: Core Services (8-10ms)
        $profiler->time('core', fn() => $this->initializeCoreServices());

        // Phase 5: HTTP Layer (10-13ms)
        $profiler->time('http', fn() => $this->initializeHttpLayer());

        // Phase 6: Lazy Registration (13-15ms)
        $profiler->time('services', fn() => $this->registerLazyServices());

        // Phase 7: Framework Validation (15-17ms)
        $profiler->time('validation', fn() => $this->validateFramework());

        // Create Application instance
        $this->application = new Application($this->container);

        $this->booted = true;

        // Log boot performance
        $profiler->logSummary();

        // Schedule background initialization (after HTTP response)
        $this->scheduleBackgroundTasks();

        return $this->application;
    }

    /**
     * Phase 1: Initialize environment and globals
     */
    private function initializeEnvironment(): void
    {
        // Load .env file if not already loaded
        if (file_exists($this->basePath . '/.env') && !isset($_ENV['APP_ENV'])) {
            $dotenv = \Dotenv\Dotenv::createImmutable($this->basePath);
            $dotenv->load();
        }

        // Set globals FIRST to prevent circular dependencies
        $GLOBALS['framework_booting'] = true;
        $GLOBALS['base_path'] = $this->basePath;
        $GLOBALS['app_environment'] = $this->environment;
        $GLOBALS['config_paths'] = [
            'framework' => dirname(__DIR__) . '/config',
            'application' => $this->configPath
        ];

        // Production security validation
        if ($this->environment === 'production') {
            $validation = SecurityManager::validateProductionEnvironment();
            try {
                if (
                    isset($validation['warnings']) && is_array($validation['warnings']) &&
                    count($validation['warnings']) > 0
                ) {
                    foreach ($validation['warnings'] as $warning) {
                        error_log('[security] WARNING: ' . $warning);
                    }
                }
                if (
                    isset($validation['recommendations']) && is_array($validation['recommendations']) &&
                    count($validation['recommendations']) > 0
                ) {
                    foreach ($validation['recommendations'] as $rec) {
                        error_log('[security] RECOMMENDATION: ' . $rec);
                    }
                }
            } catch (\Throwable) {
                // best-effort logging only
            }
        }
    }

    /**
     * Phase 2: Initialize configuration system (lazy loading)
     */
    private function initializeConfiguration(): void
    {
        // Don't load any configs yet - just set up the loader
        $loader = new ConfigurationLoader($this->basePath, $this->environment, $this->configPath);

        // Store the loader for lazy loading
        $GLOBALS['config_loader'] = $loader;
        $GLOBALS['configs_loaded'] = true;

        // Initialize empty cache - configs will be loaded on demand
        ConfigurationCache::setLoader($loader);
    }

    /**
     * Phase 3: Build and initialize container
     */
    private function buildContainer(): void
    {
        // Build new PSR-11 container. Use compiled container in production when not debugging.
        $debug = (bool) (env('APP_DEBUG', $_ENV['APP_DEBUG'] ?? false));
        $prod = ($this->environment === 'production') && ($debug === false);

        $this->container = ContainerFactory::create($prod);

        // Make container globally available
        $GLOBALS['container'] = $this->container;
        $GLOBALS['framework_bootstrapped'] = true;

        // Bootstrap Event facade if available (PSR-14)
        try {
            /** @var \Psr\EventDispatcher\EventDispatcherInterface $dispatcher */
            $dispatcher = $this->container->get(\Psr\EventDispatcher\EventDispatcherInterface::class);
            /** @var \Glueful\Events\ListenerProvider $provider */
            $provider = $this->container->get(\Glueful\Events\ListenerProvider::class);
            \Glueful\Events\Event::bootstrap($dispatcher, $provider, $this->container);
        } catch (\Throwable) {
            // Events are optional; ignore if not available
        }
    }

    /**
     * Phase 4: Initialize core services
     */
    private function initializeCoreServices(): void
    {
        // Register exception handler
        ExceptionHandler::register();

        // Initialize Cache Driver
        Utils::initializeCacheDriver();

        // Enable cache services
        CacheTaggingService::enable();
        CacheInvalidationService::enable();
        CacheInvalidationService::warmupPatterns();

        // Enable development features
        if ($this->environment === 'development') {
            $this->initializeDevelopmentTools();
        }

        // Configure structured logging
        $this->configureStructuredLogging();

        // Initialize authentication providers
        $this->initializeAuth();

        // Initialize extensions system
        $this->initializeExtensions();
    }

    /**
     * Phase 5: Initialize HTTP layer (Next-Gen Router and routes)
     */
    private function initializeHttpLayer(): void
    {
        try {
            // Get the Next-Gen Router instance
            $router = $this->container->get(\Glueful\Routing\Router::class);

            // Load routes using a single manifest to keep sources centralized
            RouteManifest::load($router);

            // Auto-discover controllers with attributes if directory exists
            if (is_dir($this->basePath . '/app/Controllers')) {
                $attributeLoader = $this->container->get(\Glueful\Routing\AttributeRouteLoader::class);
                $attributeLoader->scanDirectory($this->basePath . '/app/Controllers');
            }

            // Cache routes in production
            if ($this->environment === 'production') {
                $cache = $this->container->get(\Glueful\Routing\RouteCache::class);
                $cache->save($router);
            }
        } catch (\Throwable $e) {
            // Log but don't fail the boot process
            error_log("HTTP layer initialization failed: " . $e->getMessage());
        }
    }

    /**
     * Phase 6: Register lazy-loaded services
     */
    private function registerLazyServices(): void
    {
        try {
            if ($this->container !== null) {
                /** @var LazyInitializer $li */
                $li = $this->container->get(LazyInitializer::class);
                $this->lazyInitializer = $li;
                $GLOBALS['lazy_initializer'] = $li; // optional debug access
            }
        } catch (\Throwable) {
            // Best-effort; lazy warming is optional
        }
    }

    /**
     * Phase 7: Validate framework configuration
     */
    private function validateFramework(): void
    {
        // Validate database connection on startup
        if (
            PHP_SAPI !== 'cli' &&
            (bool) env('DB_STARTUP_VALIDATION', true) &&
            !(bool) env('SKIP_DB_VALIDATION', false)
        ) {
            ConnectionValidator::validateOnStartup(
                throwOnFailure: (bool) env('DB_STARTUP_STRICT', false)
            );
        }

        // Log container compilation details
        $this->logContainerStatus();
    }

    /**
     * Initialize development tools
     */
    private function initializeDevelopmentTools(): void
    {
        if ((bool) env('ENABLE_QUERY_MONITORING', true)) {
            DevelopmentQueryMonitor::enable();
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

    /**
     * Initialize authentication providers
     */
    private function initializeAuth(): void
    {
        try {
            if (class_exists(\Glueful\Auth\AuthBootstrap::class)) {
                \Glueful\Auth\AuthBootstrap::initialize();
            }
        } catch (\Throwable $e) {
            error_log("Auth initialization failed: " . $e->getMessage());
        }
    }

    /**
     * Initialize extensions system
     */
    private function initializeExtensions(): void
    {
        try {
            $extensions = $this->container->get(\Glueful\Extensions\ExtensionManager::class);

            // Discover providers before boot so runtime list is populated
            $extensions->discover();
            $extensions->boot();
        } catch (\Throwable $e) {
            error_log("Extensions initialization failed: " . $e->getMessage());
        }
    }

    /**
     * Configure structured logging
     */
    private function configureStructuredLogging(): void
    {
        try {
            $logger = $this->container->get(LoggerInterface::class);

            if ($logger instanceof \Monolog\Logger) {
                $userIdResolver = function (): ?string {
                    try {
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

    /**
     * Log container compilation status
     */
    private function logContainerStatus(): void
    {
        try {
            /** @var LoggerInterface $logger */
            $logger = $this->container->get(LoggerInterface::class);
            $info = [
                'env' => $this->environment,
                'compiled' => ($this->environment === 'production') && !(bool) env('APP_DEBUG', false),
            ];
            $logger->info('DI container initialized', ['container' => $info]);
        } catch (\Throwable) {
            // Best-effort logging only
        }
    }

    /**
     * Schedule background tasks to run after response
     */
    private function scheduleBackgroundTasks(): void
    {
        if (function_exists('fastcgi_finish_request')) {
            register_shutdown_function(function () {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                $this->runBackgroundTasks();
            });
        }
    }

    /**
     * Run background initialization tasks
     */
    private function runBackgroundTasks(): void
    {
        try {
            if ($this->lazyInitializer !== null) {
                $this->lazyInitializer->initializeBackground();
            }
        } catch (\Throwable $e) {
            // Log but don't fail the application
            error_log("Background task initialization failed: " . $e->getMessage());
        }
    }

    /**
     * Get framework version
     */
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

        // Prefer configured version if available
        try {
            if (function_exists('config')) {
                $v = config('app.version_full', null);
                if (is_string($v) && $v !== '') {
                    return $v;
                }
            }
        } catch (\Throwable) {
            // ignore and fallback
        }

        return '1.0.0';
    }

    // ===== Getters and utility methods =====

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

    public function getContainer(): ?ContainerInterface
    {
        return $this->container;
    }

    public function getApplication(): ?Application
    {
        return $this->application;
    }
}
