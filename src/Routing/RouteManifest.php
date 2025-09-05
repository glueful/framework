<?php

declare(strict_types=1);

namespace Glueful\Routing;

class RouteManifest
{
    /**
     * @return array{core_routes: array<string>, extension_routes: array<string, string>, generated_at: int}
     */
    public static function generate(): array
    {
        return [
            'core_routes' => [
                '/routes/web.php',
                '/routes/api.php',
                '/routes/admin.php',
            ],
            'extension_routes' => [
                'user_management' => '/extensions/user_management/routes.php',
                'analytics' => '/extensions/analytics/routes.php',
            ],
            'generated_at' => time(),
        ];
    }

    public static function load(Router $router): void
    {
        $manifest = self::generate();

        // Load core routes
        foreach ($manifest['core_routes'] as $file) {
            if (file_exists(base_path($file))) {
                require base_path($file);
            }
        }

        // Load enabled extension routes
        $enabled = config('extensions.enabled', []);
        foreach ($enabled as $extension) {
            if (isset($manifest['extension_routes'][$extension])) {
                $file = $manifest['extension_routes'][$extension];
                if (file_exists(base_path($file))) {
                    require base_path($file);
                }
            }
        }
    }
}
