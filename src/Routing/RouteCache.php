<?php

declare(strict_types=1);

namespace Glueful\Routing;

use Glueful\Bootstrap\ApplicationContext;

class RouteCache
{
    private string $cacheDir;
    private int $devTtl = 5; // 5 seconds in dev
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

        // Development: check TTL and file changes
        if ($this->isDevelopment()) {
            $age = time() - filemtime($cacheFile);
            if ($age > $this->devTtl) {
                return null;
            }

            // Check if route files changed
            if ($this->routeFilesChanged(filemtime($cacheFile))) {
                unlink($cacheFile);
                return null;
            }
        }

        return include $cacheFile;
    }

    public function save(Router $router): bool
    {
        $compiler = new RouteCompiler();
        $code = $compiler->compile($router);

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

    private function isDevelopment(): bool
    {
        return $this->context->getEnvironment() !== 'production';
    }

    private function routeFilesChanged(int $cacheTime): bool
    {
        // App route files
        $appGlob = glob(base_path($this->context, 'routes/*.php'));
        $appFiles = $appGlob !== false ? $appGlob : [];

        // Framework route files (under src/routes)
        $frameworkRoutesPath = dirname(__DIR__) . '/routes/*.php';
        $frameworkGlob = glob($frameworkRoutesPath);
        $frameworkFiles = $frameworkGlob !== false ? $frameworkGlob : [];

        $files = array_merge($appFiles, $frameworkFiles);

        foreach ($files as $file) {
            if (@filemtime($file) > $cacheTime) {
                return true;
            }
        }

        return false;
    }
}
