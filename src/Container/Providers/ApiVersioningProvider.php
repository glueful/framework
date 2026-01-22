<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Api\Versioning\VersionManager;
use Glueful\Api\Versioning\Middleware\VersionNegotiationMiddleware;
use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Container\Definition\AliasDefinition;
use Glueful\Container\Definition\DefinitionInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service provider for API versioning components
 *
 * Registers:
 * - VersionManager - Central version management
 * - VersionNegotiationMiddleware - Request/response version handling
 * - api_version - Middleware alias for route configuration
 */
final class ApiVersioningProvider extends BaseServiceProvider
{
    /**
     * @return array<string, mixed|callable|DefinitionInterface>
     */
    public function defs(): array
    {
        $defs = [];

        // Version Manager (core service)
        $defs[VersionManager::class] = new FactoryDefinition(
            VersionManager::class,
            function (ContainerInterface $c): VersionManager {
                $config = function_exists('config')
                    ? (array) config('api.versioning', [])
                    : [];

                $logger = $c->has(LoggerInterface::class)
                    ? $c->get(LoggerInterface::class)
                    : new NullLogger();

                return VersionManager::fromConfig($config, $logger);
            }
        );

        // Alias for convenience
        $defs['api.version_manager'] = new AliasDefinition(
            'api.version_manager',
            VersionManager::class
        );

        // Version Negotiation Middleware
        $defs[VersionNegotiationMiddleware::class] = new FactoryDefinition(
            VersionNegotiationMiddleware::class,
            function (ContainerInterface $c): VersionNegotiationMiddleware {
                $logger = $c->has(LoggerInterface::class)
                    ? $c->get(LoggerInterface::class)
                    : new NullLogger();

                return new VersionNegotiationMiddleware(
                    $c->get(VersionManager::class),
                    $logger
                );
            }
        );

        // Middleware alias for route registration (e.g., middleware: ['api_version'])
        $defs['api_version'] = new AliasDefinition(
            'api_version',
            VersionNegotiationMiddleware::class
        );

        return $defs;
    }
}
