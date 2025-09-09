<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Glueful\DI\ServiceTags;
use Glueful\DI\Container;

class CoreServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $container): void
    {
        // Register the Glueful Container wrapper
        $container->register('glueful.container', \Glueful\DI\Container::class)
            ->setArguments([new Reference('service_container')])
            ->setPublic(true);

        $container->setAlias(\Glueful\DI\Container::class, 'glueful.container')
            ->setPublic(true);

        // Database services
        $container->register('database', \Glueful\Database\Connection::class)
            ->setPublic(true);

        $container->register(\Glueful\Database\QueryBuilder::class)
            ->setFactory([new Reference('database'), 'createQueryBuilder'])
            ->setPublic(true);

        $container->register(\Glueful\Database\Schema\Interfaces\SchemaBuilderInterface::class)
            ->setFactory([new Reference('database'), 'getSchemaBuilder'])
            ->setPublic(true);

        // Cache services
        $container->register('cache.store', \Glueful\Cache\CacheStore::class)
            ->setFactory([\Glueful\Cache\CacheFactory::class, 'create'])
            ->setPublic(true)
            ->addTag(ServiceTags::CACHE_POOL);

        // Alias for CacheStore class name resolution
        $container->setAlias(\Glueful\Cache\CacheStore::class, 'cache.store')
            ->setPublic(true);

        // Next-Gen Router services
        $container->register(\Glueful\Routing\Router::class)
            ->setArguments([new Reference('glueful.container')])
            ->setPublic(true);

        $container->register(\Glueful\Routing\RouteCache::class)
            ->setPublic(true);

        $container->register(\Glueful\Routing\RouteCompiler::class)
            ->setPublic(true);

        $container->register(\Glueful\Routing\AttributeRouteLoader::class)
            ->setArguments([new Reference(\Glueful\Routing\Router::class)])
            ->setPublic(true);

        // Routing Middleware
        $container->register(\Glueful\Routing\Middleware\AuthMiddleware::class)
            ->setArguments([new Reference(\Glueful\Auth\AuthenticationManager::class)])
            ->setPublic(true);

        $container->register(\Glueful\Routing\Middleware\RateLimiterMiddleware::class)
            ->setPublic(true);

        $container->register(\Glueful\Routing\Middleware\CSRFMiddleware::class)
            ->setArguments([
                [], // exemptRoutes - will use defaults
                7200, // tokenLifetime - 2 hours
                false, // useDoubleSubmit
                true, // enabled
                true, // validateOrigin
                false, // autoRotateTokens
                false, // useStatelessTokens
                [], // allowedOrigins - will use defaults
                new Reference('glueful.container'),
                new Reference('cache.store'),
                new Reference('logger')
            ])
            ->setPublic(true);

        $container->register(\Glueful\Routing\Middleware\SecurityHeadersMiddleware::class)
            ->setArguments([
                [], // config - will use defaults from security.php
                true, // enabled
                null, // environment - will auto-detect
                false, // generateNonces
                null, // reportUri - will get from env
                [], // exemptPaths
                new Reference('logger')
            ])
            ->setPublic(true);

        $container->register(\Glueful\Routing\Middleware\AdminPermissionMiddleware::class)
            ->setArguments([
                'admin.access', // adminPermission
                'admin', // resource
                [], // context
                [], // allowedIps
                [], // blockedIps
                true, // requireElevated
                false, // requireMfa
                900, // sessionTimeout - 15 minutes
                [], // allowedHours
                [], // allowedCountries
                'warning', // logLevel
                new Reference('permission.manager'),
                new Reference(\Glueful\Repository\UserRepository::class),
                new Reference('logger'),
                new Reference('glueful.container')
            ])
            ->setPublic(true);

        $container->register(\Glueful\Routing\Middleware\RequestResponseLoggingMiddleware::class)
            ->setArguments([
                'both', // logMode - log both requests and responses
                true, // logHeaders
                false, // logBodies - disabled by default for security
                'info', // logLevel
                2000, // slowThreshold - 2 seconds
                10240, // bodySizeLimit - 10KB
                false, // anonymizeIps - disabled by default
                new Reference('logger'),
                new Reference('glueful.container')
            ])
            ->setPublic(true);

        $container->register(\Glueful\Routing\Middleware\LockdownMiddleware::class)
            ->setArguments([
                new Reference('logger'),
                new Reference('glueful.container')
            ])
            ->setPublic(true);

        // Register middleware aliases
        $container->setAlias('auth', \Glueful\Routing\Middleware\AuthMiddleware::class)
            ->setPublic(true);

        $container->setAlias('rate_limit', \Glueful\Routing\Middleware\RateLimiterMiddleware::class)
            ->setPublic(true);

        $container->setAlias('csrf', \Glueful\Routing\Middleware\CSRFMiddleware::class)
            ->setPublic(true);

        $container->setAlias('security_headers', \Glueful\Routing\Middleware\SecurityHeadersMiddleware::class)
            ->setPublic(true);

        $container->setAlias('admin', \Glueful\Routing\Middleware\AdminPermissionMiddleware::class)
            ->setPublic(true);

        $container->setAlias('request_logging', \Glueful\Routing\Middleware\RequestResponseLoggingMiddleware::class)
            ->setPublic(true);

        $container->setAlias('lockdown', \Glueful\Routing\Middleware\LockdownMiddleware::class)
            ->setPublic(true);

        // Logger service
        $container->register('logger', \Psr\Log\LoggerInterface::class)
            ->setFactory([$this, 'createLogger'])
            ->setArguments([new Reference('service_container')])
            ->setPublic(true);

        // Add alias for PSR LoggerInterface
        $container->setAlias(\Psr\Log\LoggerInterface::class, 'logger')
            ->setPublic(true);

        // Security services
        $container->register(\Glueful\Security\RandomStringGenerator::class)
            ->setPublic(true);

        $container->register(\Glueful\Auth\TokenManager::class)
            ->setFactory([$this, 'createTokenManager'])
            ->setArguments([new Reference('cache.store')])
            ->setPublic(true);

        $container->register(\Glueful\Auth\AuthenticationManager::class)
            ->setPublic(true);

        // Request service
        $container->register('request', \Symfony\Component\HttpFoundation\Request::class)
            ->setFactory([\Symfony\Component\HttpFoundation\Request::class, 'createFromGlobals'])
            ->setPublic(true);

        // Permission services
        $container->register('permission.manager', \Glueful\Permissions\PermissionManager::class)
            ->setFactory([\Glueful\Permissions\PermissionManager::class, 'getInstance'])
            ->setArguments([new Reference(\Glueful\Auth\SessionCacheManager::class)])
            ->setPublic(true);

        $container->register(\Glueful\Permissions\PermissionCache::class)
            ->setArguments([new Reference('cache.store')])
            ->setPublic(true);

        // Session services
        $container->register(\Glueful\Auth\SessionCacheManager::class)
            ->setArguments([new Reference('cache.store')])
            ->setPublic(true);

        $container->register(\Glueful\Auth\SessionAnalytics::class)
            ->setArguments([
                new Reference('cache.store'),
                new Reference(\Glueful\Auth\SessionCacheManager::class)
            ])
            ->setPublic(true);

        $container->register(\Glueful\Auth\AuthenticationService::class)
            ->setArguments([
                null, // TokenStorageInterface will use default
                new Reference(\Glueful\Auth\SessionCacheManager::class)
            ])
            ->setPublic(true);

        // Token Storage Service
        $container->register(\Glueful\Auth\TokenStorageService::class)
            ->setArguments([
                new Reference('cache.store'),
                new Reference('database'),
                null, // RequestContext - will use default
                true  // useTransactions
            ])
            ->setPublic(true);

        // Performance services
        $container->register(\Glueful\Performance\MemoryManager::class)
            ->setPublic(true);

        // API services
        $container->register(\Glueful\Services\ApiMetricsService::class)
            ->setArguments([
                new Reference('cache.store'),
                new Reference('database'),
                new Reference(\Glueful\Database\Schema\Interfaces\SchemaBuilderInterface::class)
            ])
            ->setPublic(true);

        $container->register(\Glueful\Services\HealthService::class)
            ->setArguments([new Reference('cache.store')])
            ->setPublic(true);

        // Security services
        $container->register(\Glueful\Security\SecurityManager::class)
            ->setArguments([new Reference('cache.store')])
            ->setPublic(true);

        // Cache services
        $container->register(\Glueful\Cache\CacheWarmupService::class)
            ->setArguments([new Reference('cache.store')])
            ->setPublic(true);

        $distributedCacheDefinition = new Definition(\Glueful\Cache\DistributedCacheService::class);
        $distributedCacheDefinition->setArguments([
            new Reference('cache.store'),
            [] // Empty config array as default
        ]);
        $distributedCacheDefinition->setPublic(true);
        $container->setDefinition(\Glueful\Cache\DistributedCacheService::class, $distributedCacheDefinition);

        $container->register(\Glueful\Cache\EdgeCacheService::class)
            ->setArguments([new Reference('cache.store')])
            ->setPublic(true);

        $container->register(\Glueful\Database\QueryCacheService::class)
            ->setArguments([new Reference('cache.store')])
            ->setPublic(true);

        // Migration services
        $container->register(\Glueful\Database\Migrations\MigrationManager::class)
            ->setPublic(true);
    }

    public function boot(Container $container): void
    {
        // Post-compilation initialization if needed
    }

    public function getCompilerPasses(): array
    {
        return [
            // Core services don't need custom compiler passes
        ];
    }

    public function getName(): string
    {
        return 'core';
    }

    /**
     * Factory method for creating logger
     *
     * @param mixed $container
     */
    public static function createLogger(mixed $container): \Psr\Log\LoggerInterface
    {
        // Framework logging should be optional and configurable
        $config = config('logging.framework', []);

        $enabled = $config['enabled'] ?? true;
        if ($enabled !== true) {
            return new \Psr\Log\NullLogger();
        }

        if (env('APP_ENV') === 'testing') {
            return new \Psr\Log\NullLogger();
        }

        // Use framework-specific logging configuration
        $channelConfig = config('logging.channels.framework', []);
        $logLevel = \Monolog\Logger::toMonologLevel($config['level'] ?? 'info');

        $debug = env('APP_DEBUG', false);
        if ($debug === true) {
            $logLevel = \Monolog\Logger::toMonologLevel('debug');
        }

        // Create framework logger with proper channel
        $logger = new \Monolog\Logger($config['channel'] ?? 'framework');

        // Set up framework log file with proper path
        $logPath = $channelConfig['path'] ?? (
            base_path('storage/logs/framework-' . date('Y-m-d') . '.log')
        );
        $handler = new \Monolog\Handler\StreamHandler($logPath, $logLevel);
        $logger->pushHandler($handler);

        // Push standardized log processor
        try {
            $env = (string) (config('app.env', (string)($_ENV['APP_ENV'] ?? 'production')));
            $version = 'dev';
            if (class_exists('Composer\\InstalledVersions')) {
                try {
                    $version = (string) \Composer\InstalledVersions::getPrettyVersion('glueful/framework');
                } catch (\Throwable) {
                    $version = (string) config('app.version_full', '1.0.0');
                }
            } else {
                $version = (string) config('app.version_full', '1.0.0');
            }
            $logger->pushProcessor(new \Glueful\Logging\StandardLogProcessor($env, $version));
        } catch (\Throwable) {
            // Processor is optional; ignore failures
        }

        return $logger;
    }

    /**
     * Factory method for creating token manager
     */
    public static function createTokenManager(): \Glueful\Auth\TokenManager
    {
        \Glueful\Auth\TokenManager::initialize();
        return new \Glueful\Auth\TokenManager();
    }
}
