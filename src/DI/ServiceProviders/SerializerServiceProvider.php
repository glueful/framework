<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Glueful\DI\Container;
use Glueful\Serialization\Serializer as GluefulSerializer;

/**
 * Serializer Service Provider
 *
 * Registers Symfony Serializer with the dependency injection container.
 * Configures the serializer with JSON/XML encoders and normalizers
 * for common PHP types including DateTime and arrays.
 *
 * @package Glueful\DI\ServiceProviders
 */
class SerializerServiceProvider implements ServiceProviderInterface
{
    /**
     * Register serializer services in Symfony ContainerBuilder
     */
    public function register(ContainerBuilder $container): void
    {
        // Register minimal Glueful Serializer
        $container->register(GluefulSerializer::class)
            ->setPublic(true);
    }

    /**
     * Boot serializer services after container is built
     */
    public function boot(Container $container): void
    {
        // Serializer is ready to use after registration
        // No additional boot configuration needed
    }

    /**
     * Get compiler passes for serializer services
     */
    public function getCompilerPasses(): array
    {
        return [
            // Serializer normalizers will be processed by TaggedServicePass
        ];
    }

    /**
     * Get the provider name for debugging
     */
    public function getName(): string
    {
        return 'serializer';
    }

    /**
     * Factory method for creating Symfony Serializer
     */
    public static function createSerializer(): GluefulSerializer
    {
        return new GluefulSerializer();
    }
}
