<?php

declare(strict_types=1);

namespace Glueful\Routing;

class RouteManifest
{
    /**
     * @return array{framework_routes: array<string>, core_routes: array<string>, generated_at: int}
     */
    public static function generate(): array
    {
        return [
            'framework_routes' => [
                '/routes/auth.php',
                '/routes/health.php',
                '/routes/extensions.php',
                '/routes/files.php',
                '/routes/notifications.php',
                '/routes/resource.php',
            ],
            'core_routes' => [
                '/routes/api.php',
                '/routes/admin.php',
            ],
            'generated_at' => time(),
        ];
    }

    public static function load(Router $router): void
    {
        // Router parameter reserved for future use
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
}
