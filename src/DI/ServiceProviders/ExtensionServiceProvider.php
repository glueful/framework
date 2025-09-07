<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Glueful\DI\ServiceTags;
use Glueful\DI\Container;

/**
 * Extension Service Provider
 * Uses Symfony DI and tagged services
 */
class ExtensionServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $container): void
    {
        // Register ExtensionMetadataRegistry
        $container->register(\Glueful\Extensions\ExtensionMetadataRegistry::class)
            ->setPublic(true);

        // Register PackageManifest for Composer extension discovery
        $container->register(\Glueful\Extensions\PackageManifest::class)
            ->setPublic(true);

        // Register the main ExtensionManager (updated to match current constructor)
        $container->register('extension.manager', \Glueful\Extensions\ExtensionManager::class)
            ->setArguments([
                new Reference('glueful.container')
            ])
            ->setPublic(true);

        // Register class alias
        $container->setAlias(\Glueful\Extensions\ExtensionManager::class, 'extension.manager')
            ->setPublic(true);

        // Register alias for easy access
        $container->setAlias('extensions', 'extension.manager')
            ->setPublic(true);
    }

    public function boot(Container $container): void
    {
        // Initialize the extension system after all services are registered
        try {
            $extensionManager = $container->get('extension.manager');

            // Discover extensions (this will load from Composer and local paths)
            $extensionManager->discover();
        } catch (\Exception $e) {
            // Log error but don't fail the boot process
            if ($container->has('logger')) {
                $logger = $container->get('logger');
                $logger->error('Failed to discover extensions: ' . $e->getMessage());
            }
        }
    }

    public function getCompilerPasses(): array
    {
        return [
            // Extension services will be processed by ExtensionServicePass
        ];
    }

    public function getName(): string
    {
        return 'extensions';
    }
}
