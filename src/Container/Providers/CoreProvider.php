<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Container\Definition\AliasDefinition;
use Glueful\Container\Definition\DefinitionInterface;

final class CoreProvider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        // Logger service (Monolog)
        $defs['logger'] = new FactoryDefinition('logger', function (): \Psr\Log\LoggerInterface {
            $logger = new \Monolog\Logger('app');
            $level = \Monolog\Level::Info;
            $handler = new \Monolog\Handler\StreamHandler('php://stdout', $level);
            $logger->pushHandler($handler);
            return $logger;
        });

        // Alias PSR LoggerInterface to the same logger instance
        $defs[\Psr\Log\LoggerInterface::class] = new AliasDefinition(\Psr\Log\LoggerInterface::class, 'logger');

        // Cache store via existing CacheFactory (transitional factory)
        $defs['cache.store'] = new FactoryDefinition(
            'cache.store',
            /** @return \Glueful\Cache\CacheStore */
            function () {
                return \Glueful\Cache\CacheFactory::create();
            }
        );

        // Tag cache store so consumers can get a tagged iterator if needed
        $this->tag('cache.store', 'cache.pool', 0);

        // Alias for CacheStore class name resolution
        $defs[\Glueful\Cache\CacheStore::class] = new AliasDefinition(\Glueful\Cache\CacheStore::class, 'cache.store');

        // Database services (transitional factory for connection)
        $defs['database'] = new FactoryDefinition(
            'database',
            /** @return \Glueful\Database\Connection|\Glueful\Database\PooledConnection */
            fn() => \Glueful\DI\ServiceFactories\DatabaseFactory::create()
        );

        // QueryBuilder and SchemaBuilder via database
        $defs[\Glueful\Database\QueryBuilder::class] = new FactoryDefinition(
            \Glueful\Database\QueryBuilder::class,
            fn(\Psr\Container\ContainerInterface $c) => $c->get('database')->createQueryBuilder()
        );
        $defs[\Glueful\Database\Schema\Interfaces\SchemaBuilderInterface::class] = new FactoryDefinition(
            \Glueful\Database\Schema\Interfaces\SchemaBuilderInterface::class,
            fn(\Psr\Container\ContainerInterface $c) => $c->get('database')->getSchemaBuilder()
        );

        // Security utilities
        $defs[\Glueful\Security\RandomStringGenerator::class] =
            $this->autowire(\Glueful\Security\RandomStringGenerator::class);

        // Auth core services
        $defs[\Glueful\Auth\TokenManager::class] = new FactoryDefinition(
            \Glueful\Auth\TokenManager::class,
            function () {
                \Glueful\Auth\TokenManager::initialize();
                return new \Glueful\Auth\TokenManager();
            }
        );
        $defs[\Glueful\Auth\AuthenticationManager::class] = $this->autowire(\Glueful\Auth\AuthenticationManager::class);

        $defs[\Glueful\Auth\AuthenticationGuard::class] = new FactoryDefinition(
            \Glueful\Auth\AuthenticationGuard::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Auth\AuthenticationGuard(
                $c->get(\Glueful\Auth\AuthenticationService::class)
            )
        );

        // HTTP request
        $defs['request'] = new FactoryDefinition(
            'request',
            fn() => \Symfony\Component\HttpFoundation\Request::createFromGlobals()
        );

        // Permission services
        $defs['permission.manager'] = new FactoryDefinition(
            'permission.manager',
            fn(\Psr\Container\ContainerInterface $c) => \Glueful\Permissions\PermissionManager::getInstance(
                $c->get(\Glueful\Auth\SessionCacheManager::class)
            )
        );
        $defs[\Glueful\Permissions\PermissionCache::class] =
            $this->autowire(\Glueful\Permissions\PermissionCache::class);

        // Session services
        $defs[\Glueful\Auth\SessionCacheManager::class] = $this->autowire(\Glueful\Auth\SessionCacheManager::class);
        $defs[\Glueful\Auth\SessionAnalytics::class] = $this->autowire(\Glueful\Auth\SessionAnalytics::class);

        // Authentication service (explicit factory to handle optional param)
        $defs[\Glueful\Auth\AuthenticationService::class] = new FactoryDefinition(
            \Glueful\Auth\AuthenticationService::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Auth\AuthenticationService(
                null,
                $c->get(\Glueful\Auth\SessionCacheManager::class)
            )
        );

        // Token storage
        $defs[\Glueful\Auth\TokenStorageService::class] = new FactoryDefinition(
            \Glueful\Auth\TokenStorageService::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Auth\TokenStorageService(
                $c->get('cache.store'),
                $c->get('database'),
                null,
                true
            )
        );

        // Performance
        $defs[\Glueful\Performance\MemoryManager::class] = $this->autowire(\Glueful\Performance\MemoryManager::class);

        // Core services
        $defs[\Glueful\Services\ApiMetricsService::class] = $this->autowire(\Glueful\Services\ApiMetricsService::class);
        $defs[\Glueful\Services\HealthService::class] = $this->autowire(\Glueful\Services\HealthService::class);
        $defs[\Glueful\Security\SecurityManager::class] = $this->autowire(\Glueful\Security\SecurityManager::class);

        // Cache-related services
        $defs[\Glueful\Cache\CacheWarmupService::class] = $this->autowire(\Glueful\Cache\CacheWarmupService::class);
        $defs[\Glueful\Cache\DistributedCacheService::class] = new FactoryDefinition(
            \Glueful\Cache\DistributedCacheService::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Cache\DistributedCacheService(
                $c->get('cache.store'),
                []
            )
        );
        $defs[\Glueful\Cache\EdgeCacheService::class] = $this->autowire(\Glueful\Cache\EdgeCacheService::class);
        $defs[\Glueful\Database\QueryCacheService::class] = $this->autowire(\Glueful\Database\QueryCacheService::class);

        // Database migrations
        $defs[\Glueful\Database\Migrations\MigrationManager::class] =
            $this->autowire(\Glueful\Database\Migrations\MigrationManager::class);

        // Field selection
        $defs[\Glueful\Support\FieldSelection\Projector::class] = new FactoryDefinition(
            \Glueful\Support\FieldSelection\Projector::class,
            function () {
                $cfg = \function_exists('config') ? (array)\config('api.field_selection', []) : [];
                $whitelist = (array)($cfg['whitelists'] ?? []);
                return new \Glueful\Support\FieldSelection\Projector(
                    whitelist: $whitelist,
                    strictDefault: (bool)($cfg['strict'] ?? false),
                    maxDepthDefault: (int)($cfg['maxDepth'] ?? 6),
                    maxFieldsDefault: (int)($cfg['maxFields'] ?? 200),
                    maxItemsDefault: (int)($cfg['maxItems'] ?? 1000),
                );
            }
        );
        $defs[\Glueful\Routing\Middleware\FieldSelectionMiddleware::class] =
            $this->autowire(\Glueful\Routing\Middleware\FieldSelectionMiddleware::class);
        // Alias as string ID for convenience
        $defs['field_selection'] = new FactoryDefinition(
            'field_selection',
            fn(\Psr\Container\ContainerInterface $c) =>
                $c->get(\Glueful\Routing\Middleware\FieldSelectionMiddleware::class)
        );

        return $defs;
    }
}
