<?php

declare(strict_types=1);

namespace Glueful\Routing;

use Glueful\Bootstrap\ApplicationContext;

/**
 * Route manifest for managing framework and application routes
 *
 * Handles loading routes with proper API prefixing based on configuration.
 * Separates API routes (which get versioned prefix) from public routes
 * (health checks, docs) that don't need the prefix.
 */
class RouteManifest
{
    private static bool $loaded = false;

    /**
     * Generate the route manifest
     *
     * @return array{
     *     api_routes: array<string>,
     *     public_routes: array<string>,
     *     core_routes: array<string>,
     *     generated_at: int
     * }
     */
    public static function generate(): array
    {
        return [
            // Routes that get the API prefix (e.g., /api/v1/auth/login)
            'api_routes' => [
                '/routes/auth.php',
                '/routes/resource.php',
            ],
            // Routes that don't get the API prefix (public endpoints)
            'public_routes' => [
                '/routes/health.php',
                '/routes/docs.php',
            ],
            // Application routes (loaded without automatic prefix - app controls its own prefixing)
            'core_routes' => [
                '/routes/api.php',
            ],
            'generated_at' => time(),
        ];
    }

    /**
     * Load routes into the router
     *
     * Loads application routes FIRST (highest priority), then framework API routes,
     * then public routes. This ensures application-specific routes like /parps/{uuid}
     * are matched before generic framework routes like /{resource}/{uuid}.
     */
    public static function load(Router $router, ApplicationContext $context): void
    {
        // Prevent double-loading routes
        if (self::$loaded) {
            return;
        }
        self::$loaded = true;

        $manifest = self::generate();
        $frameworkPath = dirname(dirname(__DIR__));

        // Get API prefix from helper (uses consolidated versioning config)
        $fullPrefix = function_exists('api_prefix')
            ? api_prefix($context)
            : self::buildPrefixFromConfig($context);

        // Load application core routes FIRST (highest priority)
        // These are loaded without automatic prefix - app controls its own prefixing
        // This ensures app routes like /parps/{uuid} match before generic /{resource}/{uuid}
        $router->enableUnprefixedRouteWarnings(true);
        foreach ($manifest['core_routes'] as $file) {
            if (file_exists(base_path($context, $file))) {
                self::requireRouteFile(base_path($context, $file), $router, $context);
            }
        }
        $router->enableUnprefixedRouteWarnings(false);

        // Load framework API routes with prefix (lower priority, acts as fallback)
        if (count($manifest['api_routes']) > 0) {
            $callback = function (Router $r) use ($manifest, $frameworkPath, $context) {
                foreach ($manifest['api_routes'] as $file) {
                    $frameworkFile = $frameworkPath . $file;
                    if (file_exists($frameworkFile)) {
                        self::requireRouteFile($frameworkFile, $r, $context);
                    }
                }
            };
            $router->group(['prefix' => $fullPrefix], $callback);
        }

        // Load public routes WITHOUT API prefix (health, docs)
        foreach ($manifest['public_routes'] as $file) {
            $frameworkFile = $frameworkPath . $file;
            if (file_exists($frameworkFile)) {
                self::requireRouteFile($frameworkFile, $router, $context);
            }
        }
    }

    /**
     * Require a route file with $router and $context available in scope
     */
    private static function requireRouteFile(string $file, Router $router, ApplicationContext $context): void
    {
        require $file;
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

    /**
     * Build API prefix from config when helper isn't available
     *
     * Fallback for early bootstrap when helpers.php hasn't been loaded yet.
     */
    private static function buildPrefixFromConfig(ApplicationContext $context): string
    {
        /** @var array<string, mixed> $versionConfig */
        $versionConfig = function_exists('config') ? config($context, 'api.versioning', []) : [];
        $parts = [];

        // Add prefix if configured (e.g., "/api")
        $applyPrefix = (bool) ($versionConfig['apply_prefix_to_routes'] ?? true);
        $prefix = (string) ($versionConfig['prefix'] ?? '/api');

        if ($applyPrefix && $prefix !== '') {
            $parts[] = rtrim($prefix, '/');
        }

        // Add version if configured (e.g., "/v1")
        $versionInPath = (bool) ($versionConfig['version_in_path'] ?? true);
        $version = (string) ($versionConfig['default'] ?? '1');

        if ($versionInPath) {
            $parts[] = '/v' . $version;
        }

        return implode('', $parts) !== '' ? implode('', $parts) : '';
    }
}
