<?php

declare(strict_types=1);

namespace Glueful\Routing;

use Glueful\Bootstrap\ApplicationContext;

class RouteCache
{
    private string $cacheDir;
    private ApplicationContext $context;

    public function __construct(ApplicationContext $context)
    {
        $this->context = $context;
        $this->cacheDir = base_path($this->context, 'storage/cache');
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function load(): ?array
    {
        $cacheFile = $this->getCacheFile();

        if (!file_exists($cacheFile)) {
            return null;
        }

        $data = include $cacheFile;
        if (!is_array($data)) {
            return null;
        }

        if (!isset($data['signature']) || !is_string($data['signature'])) {
            return null;
        }

        $currentSignature = $this->computeSignature();
        if (!hash_equals($data['signature'], $currentSignature)) {
            @unlink($cacheFile);
            return null;
        }

        // Check if cache contains closures (which can't be reconstructed)
        if ($this->cacheContainsClosures($data)) {
            @unlink($cacheFile);
            return null;
        }

        return $data;
    }

    /**
     * Check if cached route data contains any closure handlers.
     * Closures cannot be reliably reconstructed from cache.
     *
     * @param array<string, mixed> $data
     */
    private function cacheContainsClosures(array $data): bool
    {
        // Check static routes
        if (isset($data['static']) && is_array($data['static'])) {
            foreach ($data['static'] as $routeData) {
                if (isset($routeData['handler']['type']) && $routeData['handler']['type'] === 'closure') {
                    return true;
                }
            }
        }

        // Check dynamic routes
        if (isset($data['dynamic']) && is_array($data['dynamic'])) {
            foreach ($data['dynamic'] as $methodRoutes) {
                if (!is_array($methodRoutes)) {
                    continue;
                }
                foreach ($methodRoutes as $routeData) {
                    if (isset($routeData['handler']['type']) && $routeData['handler']['type'] === 'closure') {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function save(Router $router): bool
    {
        $compiler = new RouteCompiler();

        // Check for closures - they can't be cached reliably
        $issues = $compiler->validateHandlers($router);

        if ($compiler->hasClosures($issues)) {
            // Don't cache routes with closures - they'll work fine without caching
            // Clear any existing cache to prevent stale closure placeholders
            $this->clear();

            // Log warning so teams know why caching isn't happening
            $closureRoutes = $compiler->getClosureRoutes($issues);
            $routeList = implode(', ', array_slice($closureRoutes, 0, 5));
            if (count($closureRoutes) > 5) {
                $routeList .= sprintf(' (and %d more)', count($closureRoutes) - 5);
            }
            error_log(sprintf(
                '[RouteCache] Skipping route caching: %d route(s) use closure handlers which cannot be cached. Routes: %s. ' .
                'Convert to [Controller::class, "method"] syntax to enable caching.',
                count($closureRoutes),
                $routeList
            ));

            return false;
        }

        $signature = $this->computeSignature();
        $code = $compiler->compile($router, $signature);

        $cacheFile = $this->getCacheFile();
        $tmpFile = $cacheFile . '.tmp';

        // Atomic write with proper permissions
        file_put_contents($tmpFile, $code, LOCK_EX);
        chmod($tmpFile, 0644);
        rename($tmpFile, $cacheFile);

        // Warm opcache
        if (function_exists('opcache_compile_file')) {
            opcache_compile_file($cacheFile);
        }

        return true;
    }

    public function clear(): void
    {
        $cacheFile = $this->getCacheFile();
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
    }

    private function getCacheFile(): string
    {
        $env = $this->isDevelopment() ? 'dev' : 'prod';
        return $this->cacheDir . "/routes_{$env}.php";
    }

    public function getCacheFilePath(): string
    {
        return $this->getCacheFile();
    }

    private function isDevelopment(): bool
    {
        return $this->context->getEnvironment() !== 'production';
    }

    public function getSignature(): string
    {
        return $this->computeSignature();
    }

    /**
     * @return array<int, string>
     */
    public function getSourceFiles(): array
    {
        return $this->getRouteSourceFiles();
    }

    public function getCachedSignature(): ?string
    {
        $cacheFile = $this->getCacheFile();
        if (!file_exists($cacheFile)) {
            return null;
        }

        $data = include $cacheFile;
        if (!is_array($data)) {
            return null;
        }

        return isset($data['signature']) && is_string($data['signature'])
            ? $data['signature']
            : null;
    }

    /**
     * Build a stable signature based on route sources and relevant config.
     */
    private function computeSignature(): string
    {
        $files = $this->getRouteSourceFiles();
        sort($files);

        $ctx = hash_init('sha256');
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $stat = @stat($file);
            if ($stat === false) {
                continue;
            }
            hash_update($ctx, $file);
            hash_update($ctx, (string) ($stat['mtime'] ?? 0));
            hash_update($ctx, (string) ($stat['size'] ?? 0));
        }

        return hash_final($ctx);
    }

    /**
     * @return array<int, string>
     */
    private function getRouteSourceFiles(): array
    {
        $files = [];

        // App route files
        $appRoutes = glob(base_path($this->context, 'routes/*.php')) ?: [];
        $files = array_merge($files, $appRoutes);

        // Framework route files
        $frameworkRoutes = glob(dirname(__DIR__) . '/routes/*.php') ?: [];
        $files = array_merge($files, $frameworkRoutes);

        // App controllers for attribute routing
        $appControllers = base_path($this->context, 'app/Controllers');
        $files = array_merge($files, $this->collectPhpFiles($appControllers));

        // Framework controllers (if any attribute routes are used)
        $frameworkControllers = dirname(__DIR__) . '/Controllers';
        $files = array_merge($files, $this->collectPhpFiles($frameworkControllers));

        // Config files (routing/versioning can depend on config)
        $whitelist = $this->getConfigWhitelist();
        if ($whitelist !== null) {
            foreach ($whitelist as $name) {
                $files[] = base_path($this->context, 'config/' . $name);
                $files[] = dirname(__DIR__) . '/config/' . $name;
            }
        } else {
            $appConfig = glob(base_path($this->context, 'config/*.php')) ?: [];
            $files = array_merge($files, $appConfig);

            $frameworkConfig = glob(dirname(__DIR__) . '/config/*.php') ?: [];
            $files = array_merge($files, $frameworkConfig);
        }

        return array_values(array_unique($files));
    }

    /**
     * @return array<int, string>
     */
    private function collectPhpFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * @return array<int, string>|null
     */
    private function getConfigWhitelist(): ?array
    {
        $value = env('ROUTE_CACHE_CONFIG_WHITELIST', '');
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $parts = array_map('trim', explode(',', $value));
        $parts = array_values(array_filter($parts, static fn(string $p): bool => $p !== ''));

        return $parts === [] ? null : $parts;
    }
}
