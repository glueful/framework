<?php

declare(strict_types=1);

namespace Glueful\Services;

final class RouteHash
{
    /**
     * Compute a stable checksum of route source files (app + extensions)
     */
    public static function computeChecksum(): string
    {
        $routesDir = base_path(config('app.routes_path', 'routes'));
        $parts = [];
        // App routes
        if (is_dir($routesDir)) {
            foreach (glob($routesDir . '/*.php') as $file) {
                $parts[] = md5_file($file) ?: '';
            }
        }
        // Extension routes
        foreach (glob(base_path('extensions/*/routes.php')) ?: [] as $file) {
            $parts[] = md5_file($file) ?: '';
        }
        foreach (glob(base_path('extensions/*/src/routes.php')) ?: [] as $file) {
            $parts[] = md5_file($file) ?: '';
        }
        return md5(implode('|', $parts));
    }

    /**
     * Compute the short hash used in cache filenames (env + checksum)
     */
    public static function computeEnvHash(?string $env = null): string
    {
        $env = $env ?? (string) config('app.env', env('APP_ENV', 'production'));
        $checksum = self::computeChecksum();
        return substr(sha1($checksum . '|' . $env), 0, 8);
    }

    /**
     * Count extension route files included in checksum (for diagnostics)
     */
    public static function countExtensionRouteFiles(): int
    {
        $count = 0;
        $a = glob(base_path('extensions/*/routes.php')) ?: [];
        $b = glob(base_path('extensions/*/src/routes.php')) ?: [];
        $count += count($a) + count($b);
        return $count;
    }
}
