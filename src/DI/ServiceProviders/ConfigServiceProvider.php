<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Glueful\DI\Container;
use Glueful\Config\Contracts\ConfigValidatorInterface as NewConfigValidatorInterface;
use Glueful\Config\Validation\ConfigValidator as NewConfigValidator;
// Legacy Configuration imports removed (file-based schemas are used)

/**
 * Configuration Service Provider
 *
 * Registers Symfony Config components and Glueful's configuration services
 * with the dependency injection container. Handles automatic registration
 * of built-in configuration schemas.
 *
 * @package Glueful\DI\ServiceProviders
 */
class ConfigServiceProvider implements ServiceProviderInterface
{
    /**
     * Register configuration services in Symfony ContainerBuilder
     */
    public function register(ContainerBuilder $container): void
    {
        // Register new lightweight Config validator
        $container->register(NewConfigValidatorInterface::class, NewConfigValidator::class)
            ->setPublic(true);
    }

    /**
     * Boot configuration services after container is built and register schemas
     */
    public function boot(Container $container): void
    {
        // No-op
    }

    /**
     * Get compiler passes for configuration services
     */
    public function getCompilerPasses(): array
    {
        return [
            // Configuration services don't need custom compiler passes
        ];
    }

    /**
     * Get the provider name for debugging
     */
    public function getName(): string
    {
        return 'config';
    }

    /**
     * Register all built-in configuration schemas
     */
    private function registerBuiltInSchemas(): void {}
}
