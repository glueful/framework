<?php

declare(strict_types=1);

namespace Glueful\Services;

use Glueful\Http\Router;

/**
 * Route Cache Service
 *
 * Handles compilation and caching of application routes for production performance.
 * Generates optimized route cache files that eliminate route loading overhead.
 *
 * @package Glueful\Services
 */
class RouteCacheService
{
    /** @var string Default cache directory */
    private string $cacheDir;


    public function __construct()
    {
        // Use storage/cache directory for route cache
        $this->cacheDir = base_path('storage/cache');

        // Ensure cache directory exists
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Get the full path to the route cache file
     */
    public function getCacheFilePath(?string $env = null): string
    {
        $env = $env ?? (string) config('app.env', env('APP_ENV', 'production'));
        $hash = \Glueful\Services\RouteHash::computeEnvHash($env);
        return $this->cacheDir . "/routes_{$env}_{$hash}.php";
    }

    /**
     * Check if compiled route cache exists and is valid
     */
    public function isCacheValid(): bool
    {
        $cacheFile = $this->getCacheFilePath();

        if (!file_exists($cacheFile)) {
            return false;
        }

        // Check if cache is readable
        if (!is_readable($cacheFile)) {
            return false;
        }

        // Additional validation: check if cache file returns a callable factory
        try {
            $result = include $cacheFile;
            return is_callable($result) || $result instanceof \Symfony\Component\Routing\Matcher\UrlMatcherInterface;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Compile and cache routes using Symfony's CompiledUrlMatcherDumper
     *
     * @param Router $router The router instance (used to access RouteCollection)
     * @return array Result array with success status and metadata
     */
    /**
     * @return array<string, mixed>
     */
    public function cacheRoutes(Router $router, ?string $env = null): array
    {
        try {
            $cacheFile = $this->getCacheFilePath($env);

            // Get RouteCollection from Router directly
            $routes = Router::getRoutes();

            // Dump compiled matcher data and wrap in a factory closure
            $dumper = new \Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper($routes);
            $compiled = $dumper->dump();
            $export = var_export($compiled, true);
            $generatedAt = date('Y-m-d H:i:s');
            $cacheContent = <<<PHP
<?php

/**
 * Glueful Compiled Routes
 * Generated: {$generatedAt}
 */

return function(\Symfony\Component\Routing\RequestContext \$context) {
    \$compiled = {$export};
    return new \Symfony\Component\Routing\Matcher\CompiledUrlMatcher(\$compiled, \$context);
};
PHP;

            // Write to cache file
            $bytesWritten = file_put_contents($cacheFile, $cacheContent, LOCK_EX);

            if ($bytesWritten === false) {
                return [
                    'success' => false,
                    'error' => 'Failed to write cache file'
                ];
            }

            // Minimal metadata
            $stats = [
                'routes_count' => count($routes),
                'cache_size' => $bytesWritten,
                'compilation_type' => 'symfony_compiled_dumper'
            ];

            // Cleanup older caches for this environment
            $envForCleanup = $env ?? (string) config('app.env', env('APP_ENV', 'production'));
            $globPattern = $this->cacheDir . "/routes_{$envForCleanup}_*.php";
            $oldFiles = glob($globPattern);
            foreach (($oldFiles !== false ? $oldFiles : []) as $old) {
                if ($old !== $cacheFile) {
                    @unlink($old);
                }
            }

            return [
                'success' => true,
                'cache_file' => $cacheFile,
                'stats' => $stats
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Load compiled routes cache by registering factory with Router
     *
     * @param Router $router Router instance (kept for signature compatibility)
     * @return bool True if compiled matcher factory was registered
     */
    public function loadCachedRoutes(Router $router): bool
    {
        if (!$this->isCacheValid()) {
            return false;
        }

        try {
            $result = include $this->getCacheFilePath();
            if (is_callable($result)) {
                Router::setCompiledMatcherFactory($result);
                return true;
            }

            if ($result instanceof \Symfony\Component\Routing\Matcher\UrlMatcherInterface) {
                Router::setCompiledMatcher($result);
                return true;
            }

            return false;
        } catch (\Exception $e) {
            error_log("Failed to load cached routes: " . $e->getMessage());
            return false;
        }
    }
    // Legacy reflection-based code removed in favor of Symfony compiled matcher cache
}
