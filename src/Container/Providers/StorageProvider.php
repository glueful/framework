<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Container\Definition\DefinitionInterface;
use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Container\Definition\AliasDefinition;

final class StorageProvider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        // PathGuard (configurable via storage config path_guard if present)
        $defs[\Glueful\Storage\PathGuard::class] = new FactoryDefinition(
            \Glueful\Storage\PathGuard::class,
            function (): \Glueful\Storage\PathGuard {
                /** @var array<string,mixed> $cfg */
                $cfg = (array) (\function_exists('config') ? \config('storage.path_guard', []) : []);
                return new \Glueful\Storage\PathGuard($cfg);
            }
        );

        // StorageManager built from config/storage.php
        $defs[\Glueful\Storage\StorageManager::class] = new FactoryDefinition(
            \Glueful\Storage\StorageManager::class,
            function (): \Glueful\Storage\StorageManager {
                /** @var array<string,mixed> $cfg */
                $cfg = (array) (\function_exists('config') ? \config('storage') : []);
                return new \Glueful\Storage\StorageManager($cfg, new \Glueful\Storage\PathGuard());
            }
        );

        // Url generator for public links
        $defs[\Glueful\Storage\Support\UrlGenerator::class] = new FactoryDefinition(
            \Glueful\Storage\Support\UrlGenerator::class,
            function (): \Glueful\Storage\Support\UrlGenerator {
                /** @var array<string,mixed> $cfg */
                $cfg = (array) (\function_exists('config') ? \config('storage') : []);
                return new \Glueful\Storage\Support\UrlGenerator($cfg, new \Glueful\Storage\PathGuard());
            }
        );

        // String alias for convenience
        $defs['storage'] = new AliasDefinition('storage', \Glueful\Storage\StorageManager::class);

        return $defs;
    }
}
