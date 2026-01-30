<?php

declare(strict_types=1);

namespace Glueful\Helpers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Services\FileFinder;

class RoutesManager
{
    private static ?ApplicationContext $context = null;

    public static function setContext(?ApplicationContext $context): void
    {
        self::$context = $context;
    }

    /**
     * Load all route files from the routes directory.
     * Skips loading if routes are already loaded from cache for performance.
     */
    public static function loadRoutes(): void
    {
        // New router handles caching automatically - always load route definitions

        $routesDir = self::getBasePath('routes');

        $fileFinder = self::resolveFileFinder();
        $routeFiles = $fileFinder->findRouteFiles([$routesDir]);

        if (!$routeFiles->valid()) {
            throw new \Exception("No route files found in directory: " . $routesDir);
        }

        foreach ($routeFiles as $file) {
            require_once $file->getPathname();
        }
    }

    private static function resolveFileFinder(): FileFinder
    {
        if (self::$context !== null) {
            return container(self::$context)->get(FileFinder::class);
        }

        return new FileFinder();
    }

    private static function getBasePath(string $suffix = ''): string
    {
        if (self::$context !== null) {
            return base_path(self::$context, $suffix);
        }

        $root = rtrim(dirname(__DIR__, 3), DIRECTORY_SEPARATOR);
        return $suffix !== ''
            ? $root . DIRECTORY_SEPARATOR . ltrim($suffix, DIRECTORY_SEPARATOR)
            : $root;
    }
}
