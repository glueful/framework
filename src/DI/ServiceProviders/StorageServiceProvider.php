<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceProviders;

use Glueful\DI\ServiceProviderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Glueful\DI\Container;

class StorageServiceProvider implements ServiceProviderInterface
{
    public function register(ContainerBuilder $container): void
    {
        // Register PathGuard
        $container->register(\Glueful\Storage\PathGuard::class)
            ->setFactory([self::class, 'createPathGuard'])
            ->setPublic(false);

        // Register StorageManager using config('storage')
        $container->register(\Glueful\Storage\StorageManager::class)
            ->setFactory([self::class, 'createStorageManager'])
            ->setPublic(true);

        // Register UrlGenerator
        $container->register(\Glueful\Storage\Support\UrlGenerator::class)
            ->setFactory([self::class, 'createUrlGenerator'])
            ->setPublic(true);

        // String alias
        $container->setAlias('storage', \Glueful\Storage\StorageManager::class);
    }

    public function boot(Container $container): void
    {
        // No-op
    }

    public function getCompilerPasses(): array
    {
        return [];
    }

    public function getName(): string
    {
        return 'storage';
    }

    public static function createPathGuard(): \Glueful\Storage\PathGuard
    {
        /** @var array<string,mixed> $cfg */
        $cfg = (array) (\function_exists('config') ? \config('storage.path_guard', []) : []);
        return new \Glueful\Storage\PathGuard($cfg);
    }

    public static function createStorageManager(): \Glueful\Storage\StorageManager
    {
        /** @var array<string,mixed> $cfg */
        $cfg = (array) (\function_exists('config') ? \config('storage') : []);
        return new \Glueful\Storage\StorageManager($cfg, self::createPathGuard());
    }

    public static function createUrlGenerator(): \Glueful\Storage\Support\UrlGenerator
    {
        /** @var array<string,mixed> $cfg */
        $cfg = (array) (\function_exists('config') ? \config('storage') : []);
        return new \Glueful\Storage\Support\UrlGenerator($cfg, self::createPathGuard());
    }
}

