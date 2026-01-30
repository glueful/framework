<?php

declare(strict_types=1);

namespace Glueful\Services;

use Glueful\Bootstrap\ApplicationContext;

final class RouteHash
{
    /**
     * Compute a stable checksum of route source files (app + extensions)
     */
    public static function computeChecksum(?ApplicationContext $context = null): string
    {
        $routesDir = self::getBasePath(
            $context,
            (string) self::getConfig($context, 'app.routes_path', 'routes')
        );
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
        $extensionRoutes = glob(self::getBasePath($context, 'extensions/*/routes.php'));
        foreach (($extensionRoutes !== false ? $extensionRoutes : []) as $file) {
            $hash = md5_file($file);
            $parts[] = $hash !== false ? $hash : '';
        }
        $extensionSrcRoutes = glob(self::getBasePath($context, 'extensions/*/src/routes.php'));
        foreach (($extensionSrcRoutes !== false ? $extensionSrcRoutes : []) as $file) {
            $hash = md5_file($file);
            $parts[] = $hash !== false ? $hash : '';
        }
        return md5(implode('|', $parts));
    }

    /**
     * Compute the short hash used in cache filenames (env + checksum)
     */
    public static function computeEnvHash(?string $env = null, ?ApplicationContext $context = null): string
    {
        $env = $env ?? (string) self::getConfig($context, 'app.env', env('APP_ENV', 'production'));
        $checksum = self::computeChecksum($context);
        return substr(sha1($checksum . '|' . $env), 0, 8);
    }

    /**
     * Count extension route files included in checksum (for diagnostics)
     */
    public static function countExtensionRouteFiles(?ApplicationContext $context = null): int
    {
        $count = 0;
        $aGlob = glob(self::getBasePath($context, 'extensions/*/routes.php'));
        $a = $aGlob !== false ? $aGlob : [];
        $bGlob = glob(self::getBasePath($context, 'extensions/*/src/routes.php'));
        $b = $bGlob !== false ? $bGlob : [];
        $count += count($a) + count($b);
        return $count;
    }

    private static function getConfig(
        ?ApplicationContext $context,
        string $key,
        mixed $default = null
    ): mixed {
        if ($context === null) {
            return $default;
        }

        return config($context, $key, $default);
    }

    private static function getBasePath(?ApplicationContext $context, string $path = ''): string
    {
        if ($context !== null) {
            return base_path($context, $path);
        }

        $root = getcwd() ?: '.';
        if ($path === '') {
            return $root;
        }

        return rtrim($root, '/') . '/' . ltrim($path, '/');
    }
}
