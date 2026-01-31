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
 *
 * Application routes are auto-discovered from the app's routes/ directory.
 * Files are loaded in alphabetical order for deterministic behavior.
 * Files starting with underscore (_) are excluded (convention for partials).
 */
class RouteManifest
{
    private static bool $loaded = false;

    /** @var array<string> Track loaded files to prevent double-loading */
    private static array $loadedFiles = [];

    /**
     * Generate the route manifest
     *
     * @return array{
     *     api_routes: array<string>,
     *     public_routes: array<string>,
     *     app_routes_dir: string,
     *     app_routes_exclude: array<string>,
     *     generated_at: int
     * }
     */
    public static function generate(): array
    {
        return [
            // Framework routes that get the API prefix (e.g., /api/v1/auth/login)
            'api_routes' => [
                '/routes/auth.php',
                '/routes/blobs.php',
                '/routes/resource.php',
            ],
            // Framework routes that don't get the API prefix (public endpoints)
            'public_routes' => [
                '/routes/health.php',
                '/routes/docs.php',
            ],
            // Application routes directory (all *.php files are auto-discovered)
            'app_routes_dir' => '/routes',
            // Exclusion patterns for app routes (underscore prefix = partials/includes)
            'app_routes_exclude' => ['_*.php'],
            'generated_at' => time(),
        ];
    }

    /**
     * Load routes into the router
     *
     * Loads application routes FIRST (highest priority), then framework API routes,
     * then public routes. This ensures application-specific routes like /parps/{uuid}
     * are matched before generic framework routes like /{resource}/{uuid}.
     *
     * Application routes are auto-discovered from the routes/ directory and loaded
     * in alphabetical order. Files starting with underscore are excluded.
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

        // Load ALL app route files from routes/ directory FIRST (highest priority)
        // These are loaded without automatic prefix - app controls its own prefixing
        // This ensures app routes like /parps/{uuid} match before generic /{resource}/{uuid}
        $router->enableUnprefixedRouteWarnings(true);
        self::loadAppRoutes($router, $context, $manifest);
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
     * Load all app route files from the routes directory
     *
     * Files are loaded in alphabetical order for deterministic behavior.
     * This means api.php loads before identity.php, parps.php, social.php, etc.
     *
     * Exclusion convention:
     * - Files starting with underscore (_) are excluded
     * - Use _helpers.php, _shared.php for partials that are included by other route files
     *
     * @param array<string, mixed> $manifest
     */
    private static function loadAppRoutes(
        Router $router,
        ApplicationContext $context,
        array $manifest
    ): void {
        /** @var string $routesDirRelative */
        $routesDirRelative = $manifest['app_routes_dir'] ?? '/routes';
        $routesDir = base_path($context, $routesDirRelative);

        if (!is_dir($routesDir)) {
            return;
        }

        /** @var array<string> $excludePatterns */
        $excludePatterns = $manifest['app_routes_exclude'] ?? ['_*.php'];

        // Get all PHP files in routes directory
        $files = glob($routesDir . '/*.php');
        if ($files === false || $files === []) {
            return;
        }

        // Sort alphabetically for deterministic loading order
        sort($files);

        foreach ($files as $file) {
            $filename = basename($file);

            // Skip excluded files (e.g., _helpers.php, _partials.php)
            if (self::isExcluded($filename, $excludePatterns)) {
                continue;
            }

            // Skip already loaded files
            $realpath = realpath($file);
            if ($realpath !== false && in_array($realpath, self::$loadedFiles, true)) {
                continue;
            }

            self::requireRouteFile($file, $router, $context);

            // Track loaded file
            if ($realpath !== false) {
                self::$loadedFiles[] = $realpath;
            }
        }
    }

    /**
     * Check if filename matches any exclusion pattern
     *
     * @param array<string> $patterns
     */
    private static function isExcluded(string $filename, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $filename)) {
                return true;
            }
        }
        return false;
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
        self::$loadedFiles = [];
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
