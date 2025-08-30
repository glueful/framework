<?php

declare(strict_types=1);

namespace Glueful\Helpers;

use Glueful\Services\FileFinder;

class RoutesManager
{
    /**
     * Load all route files from the routes directory.
     * Skips loading if routes are already loaded from cache for performance.
     */
    public static function loadRoutes(): void
    {
        // Skip loading if routes are already loaded from cache
        if (\Glueful\Http\Router::isUsingCachedRoutes()) {
            return;
        }

        $routesDir = base_path('routes');

        $fileFinder = container()->get(FileFinder::class);
        $routeFiles = $fileFinder->findRouteFiles([$routesDir]);

        if (!$routeFiles->valid()) {
            throw new \Exception("No route files found in directory: " . $routesDir);
        }

        foreach ($routeFiles as $file) {
            require_once $file->getPathname();
        }
    }
}
