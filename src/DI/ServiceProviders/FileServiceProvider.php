<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Glueful\DI\Container;
use Glueful\Services\FileFinder;

/**
 * File Service Provider
 *
 * Registers the FileFinder service with the legacy DI container.
 * Note: FileManager has been deprecated in favor of StorageManager (Flysystem).
 */
class FileServiceProvider implements ServiceProviderInterface
{
    /**
     * Register file services with the container
     *
     * @param ContainerBuilder $container DI container
     */
    public function register(ContainerBuilder $container): void
    {
        // Register FileFinder service
        $container->register(FileFinder::class)
            ->setFactory([$this, 'createFileFinder'])
            ->setArguments([new Reference('logger'), '%filesystem.file_finder%'])
            ->setPublic(true);

        $container->setAlias('file.finder', FileFinder::class);
    }

    /**
     * Boot method called after all services are registered
     *
     * @param Container $container DI container
     */
    public function boot(Container $container): void
    {
        // No additional boot logic needed for file services
    }

    /**
     * Get compiler passes for file services
     */
    public function getCompilerPasses(): array
    {
        return [];
    }

    /**
     * Get the provider name for debugging
     */
    public function getName(): string
    {
        return 'file';
    }

    /**
     * Factory method for creating FileFinder
     *
     * @param \Psr\Log\LoggerInterface $logger
     * @param array<string, mixed> $config
     */
    public static function createFileFinder(\Psr\Log\LoggerInterface $logger, array $config): FileFinder
    {
        return new FileFinder($logger, $config);
    }
}
