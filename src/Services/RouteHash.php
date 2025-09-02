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
            $appRoutes = glob($routesDir . '/*.php');
            foreach (($appRoutes !== false ? $appRoutes : []) as $file) {
                $hash = md5_file($file);
                $parts[] = $hash !== false ? $hash : '';
            }
        }
        // Extension routes
        $extensionRoutes = glob(base_path('extensions/*/routes.php'));
        foreach (($extensionRoutes !== false ? $extensionRoutes : []) as $file) {
            $hash = md5_file($file);
            $parts[] = $hash !== false ? $hash : '';
        }
        $extensionSrcRoutes = glob(base_path('extensions/*/src/routes.php'));
        foreach (($extensionSrcRoutes !== false ? $extensionSrcRoutes : []) as $file) {
            $hash = md5_file($file);
            $parts[] = $hash !== false ? $hash : '';
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
        $aGlob = glob(base_path('extensions/*/routes.php'));
        $a = $aGlob !== false ? $aGlob : [];
        $bGlob = glob(base_path('extensions/*/src/routes.php'));
        $b = $bGlob !== false ? $bGlob : [];
        $count += count($a) + count($b);
        return $count;
    }
}
