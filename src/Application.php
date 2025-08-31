<?php

declare(strict_types=1);

namespace Glueful;

use Glueful\DI\Container;
use Glueful\Http\{Response, Router};
use Psr\Http\Message\ServerRequestInterface;
use Glueful\Extensions\ExtensionManager;
use Glueful\Scheduler\JobScheduler;
use Psr\Log\LoggerInterface;

class Application
{
    private Container $container;
    private LoggerInterface $logger;
    private bool $initialized = false;

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->logger = $container->get(LoggerInterface::class);
    }

    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->logger->info(
            'Framework initialization started',
            [
            'type' => 'framework',
            'message' => 'Glueful framework bootstrap initiated',
            'version' => config('app.version_full'),
            'environment' => config('app.env'),
            'php_version' => PHP_VERSION,
            'timestamp' => date('c')
            ]
        );

        $this->initializeCore();
        $this->registerMiddleware();
        $this->loadExtensions();
        $this->loadRoutes();
        $this->initializeScheduler();
        $this->addDevelopmentMiddleware();

        $this->logger->info(
            'Framework initialization completed',
            [
            'type' => 'framework',
            'message' => 'Glueful framework ready to handle requests',
            'version' => config('app.version_full'),
            'environment' => config('app.env'),
            'timestamp' => date('c')
            ]
        );

        $this->initialized = true;
    }

    public function handle(ServerRequestInterface $request): Response
    {
        $startTime = microtime(true);
        $requestId = request_id(); // Use consistent request_id() function

        $router = Router::getInstance(); // Router uses singleton pattern, not DI
        $response = $router->handleRequest($request);

        $totalTime = round((microtime(true) - $startTime) * 1000, 2);
        $this->logger->info(
            'Framework request completed',
            [
            'type' => 'framework',
            'request_id' => $requestId,
            'method' => $request->getMethod(),
            'uri' => $request->getUri()->getPath(),
            'time_ms' => $totalTime,
            'status' => $response->getStatusCode(),
            'timestamp' => date('c')
            ]
        );

        return $response;
    }

    public function terminate(ServerRequestInterface $request, Response $response): void
    {
        // Cleanup, log final stats, etc.
        // TODO: Implement cleanup logic, garbage collection, final logging

        // Suppress unused parameter warnings - these are part of PSR interface
        unset($request, $response);
    }

    private function initializeCore(): void
    {
        // Initialize configuration first
        $this->logger->debug("Loading configuration...");
        if (!defined('CONFIG_LOADED')) {
            // Load critical configurations
            config('app');        // Application settings
            config('database');   // Database connection
            config('security');   // Security settings
            config('cache');      // Cache configuration
            config('session');    // Session/JWT settings

            define('CONFIG_LOADED', true);
        }

        // Initialize authentication providers
        $this->logger->debug("Initializing authentication services...");
        \Glueful\Auth\AuthBootstrap::initialize();

        // Initialize database connection if needed
        if (!defined('SKIP_DB_INIT') && config('database.auto_connect', true)) {
            $this->logger->debug("Initializing database connection...");
            new \Glueful\Database\Connection();
        }

        // Initialize cache if enabled
        if (config('cache.enabled', true)) {
            $this->logger->debug("Initializing cache services...");
            \Glueful\Helpers\Utils::initializeCacheDriver();
        }

        $this->logger->debug("Core initialization completed without middleware conflicts");
    }

    private function registerMiddleware(): void
    {
        \Glueful\Http\MiddlewareRegistry::registerFromConfig();
    }

    private function loadExtensions(): void
    {
        $extensionManager = $this->container->get(ExtensionManager::class);
        $extensionManager->loadEnabledExtensions();
        $extensionManager->initializeLoadedExtensions();
        $extensionManager->loadExtensionRoutes();
    }

    private function loadRoutes(): void
    {
        // Load application routes from user's routes directory
        $basePath = $this->container->getParameter('app.base_path');
        $routesPath = config('app.routes_path', 'routes'); // User can configure via config/app.php
        $routesDir = $basePath . '/' . $routesPath;

        $environment = (string) config('app.env', 'production');

        // In production, prefer compiled matcher if present
        if ($environment === 'production') {
            $hash = \Glueful\Services\RouteHash::computeEnvHash($environment);
            $cacheDir = $basePath . '/storage/cache';
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0755, true);
            }
            $cacheFile = $cacheDir . "/routes_{$environment}_{$hash}.php";
            if (file_exists($cacheFile)) {
                $factory = require $cacheFile;
                if (is_callable($factory)) {
                    \Glueful\Http\Router::setCompiledMatcherFactory($factory);
                    return;
                }
            }
        }

        if (is_dir($routesDir)) {
            $fileFinder = $this->container->get(\Glueful\Services\FileFinder::class);
            $routeFiles = $fileFinder->findRouteFiles([$routesDir]);

            foreach ($routeFiles as $file) {
                include_once $file->getPathname();
            }
        }

        // After loading, compile routes in production
        if ($environment === 'production') {
            $collection = \Glueful\Http\Router::getRoutes();
            $hash = \Glueful\Services\RouteHash::computeEnvHash($environment);
            $cacheDir = $basePath . '/storage/cache';
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0755, true);
            }
            $cacheFile = $cacheDir . "/routes_{$environment}_{$hash}.php";
            try {
                $dumper = new \Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper($collection);
                $compiled = $dumper->dump();
                $export = var_export($compiled, true);
                $content = <<<PHP
<?php
return function(\Symfony\Component\Routing\RequestContext \$context) {
    \$compiled = {$export};
    return new \Symfony\Component\Routing\Matcher\CompiledUrlMatcher(\$compiled, \$context);
};
PHP;
                file_put_contents($cacheFile, $content);
                $factory = require $cacheFile;
                if (is_callable($factory)) {
                    \Glueful\Http\Router::setCompiledMatcherFactory($factory);
                }
            } catch (\Throwable $e) {
                // Ignore compilation failures; continue with dynamic matching
                unset($e);
            }
        }
    }

    private function computeRoutesChecksum(string $routesDir): string
    {
        // Delegated to RouteHash to avoid drift; keep signature for BC with current calls
        return \Glueful\Services\RouteHash::computeChecksum();
    }

    private function initializeScheduler(): void
    {
        if (PHP_SAPI === 'cli') {
            $this->container->get(JobScheduler::class);
        }
    }

    private function addDevelopmentMiddleware(): void
    {
        $env = (string) config('app.env', 'production');
        if ($env !== 'development') {
            return;
        }

        $devMiddlewares = [
            'Glueful\\Http\\Middleware\\DebugBarMiddleware',
            'Glueful\\Http\\Middleware\\QueryLoggerMiddleware',
            'Glueful\\Http\\Middleware\\DevelopmentExceptionMiddleware',
        ];

        foreach ($devMiddlewares as $class) {
            if (class_exists($class)) {
                try {
                    $instance = new $class();
                    if ($instance instanceof \Glueful\Http\Middleware\MiddlewareInterface) {
                        \Glueful\Http\Router::addMiddleware($instance);
                    }
                } catch (\Throwable) {
                }
            }
        }
    }

    public function getContainer(): Container
    {
        return $this->container;
    }
}
