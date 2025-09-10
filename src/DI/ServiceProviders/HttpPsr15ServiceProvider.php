<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\Container;
use Glueful\DI\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Registers optional PSR-15 middleware aliases from config.
 * This does not enforce the PSR-7 bridge; it only exposes configured classes
 * as services (lazy) so routes can reference them by alias (e.g., 'psr15.cors').
 */
class HttpPsr15ServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $container): void
    {
        $config = (array) (\function_exists('config') ? config('http.psr15', []) : []);

        if (($config['enabled'] ?? true) === false) {
            return;
        }

        // Register popular PSR-15 middleware packages if configured
        foreach (($config['popular_packages'] ?? []) as $alias => $middlewareClass) {
            $this->registerPsr15Middleware($container, (string) $alias, (string) $middlewareClass);
        }
    }

    public function boot(Container $container): void
    {
        // No boot logic needed
    }

    public function getCompilerPasses(): array
    {
        return [];
    }

    public function getName(): string
    {
        return 'http.psr15';
    }

    protected function registerPsr15Middleware(
        ContainerBuilder $container,
        string $alias,
        string $middlewareClass
    ): void {
        if (!class_exists($middlewareClass)) {
            // Skip registration if class doesn't exist
            return;
        }

        $container->register('psr15.' . $alias, $middlewareClass)
            ->setLazy(true)
            ->setPublic(true);
    }
}
