<?php

declare(strict_types=1);

namespace Glueful\Routing;

class RouteManifest
{
    private static bool $loaded = false;
    /**
     * @return array{framework_routes: array<string>, core_routes: array<string>, generated_at: int}
     */
    public static function generate(): array
    {
        return [
            'framework_routes' => [
                '/routes/auth.php',
                '/routes/docs.php',
                '/routes/health.php',
                '/routes/resource.php',
            ],
            'core_routes' => [
                '/routes/api.php',
            ],
            'generated_at' => time(),
        ];
    }

    public static function load(Router $router): void
    {
        // Prevent double-loading routes
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        $manifest = self::generate();

        // Load framework routes first (from framework directory)
        $frameworkPath = dirname(dirname(__DIR__));
        foreach ($manifest['framework_routes'] as $file) {
            $frameworkFile = $frameworkPath . $file;
            if (file_exists($frameworkFile)) {
                require $frameworkFile;
            }
        }

        // Load application core routes (from application directory)
        foreach ($manifest['core_routes'] as $file) {
            if (file_exists(base_path($file))) {
                require base_path($file);
            }
        }
    }

    /**
     * Reset the loaded state (for testing purposes)
     */
    public static function reset(): void
    {
        self::$loaded = false;
    }

    /**
     * Check if routes have been loaded
     */
    public static function isLoaded(): bool
    {
        return self::$loaded;
    }
}
