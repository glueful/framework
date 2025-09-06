<?php

declare(strict_types=1);

namespace Glueful\Routing;

class RouteManifest
{
    /**
     * @return array{core_routes: array<string>, generated_at: int}
     */
    public static function generate(): array
    {
        return [
            'core_routes' => [
                '/routes/web.php',
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

        // Load core routes
        foreach ($manifest['core_routes'] as $file) {
            if (file_exists(base_path($file))) {
                require base_path($file);
            }
        }
    }
}
