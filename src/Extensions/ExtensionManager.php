<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Psr\Container\ContainerInterface;
use Glueful\Bootstrap\ApplicationContext;

/**
 * Discovers, registers, boots extension providers.
 * - Composer discovery (installed.php/json)
 * - Optional local dev scan
 * - Deterministic ordering (priority + bootAfter())
 * - Production cache
 */
final class ExtensionManager
{
    /** @var array<class-string<ServiceProvider>, ServiceProvider> */
    private array $providers = [];

    private bool $discovered = false;
    private bool $booted = false;
    private bool $cacheUsed = false;

    public function __construct(private ContainerInterface $container)
    {
    }

    private function getContext(): ApplicationContext
    {
        return $this->container->get(ApplicationContext::class);
    }

    /**
     * Discover and register all extensions with caching
     */
    public function discover(): void
    {
        // Prevent redundant discovery
        if ($this->discovered) {
            return;
        }
        $this->discovered = true;

        // Try cache first
        $cached = $this->loadFromCache();
        if ($cached !== null) {
            $this->providers = $cached;
            $this->cacheUsed = true;
            return;
        }

        // Full discovery using unified ProviderLocator
        $this->loadAllProviders();

        // Sort providers by priority and dependencies
        $this->sortProviders();

        // Register all providers
        $this->registerProviders();

        // Cache for next time (production only)
        if ($this->isProduction()) {
            $this->saveToCache();
        }
    }

    /**
     * Boot all registered providers
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $context = $this->getContext();
        foreach ($this->providers as $providerClass => $provider) {
            try {
                if (method_exists($provider, 'boot')) {
                    $provider->boot($context);
                }
            } catch (\Throwable $e) {
                $this->log(
                    "Provider failed during boot()",
                    ['provider' => $providerClass, 'error' => $e->getMessage()]
                );
            }
        }

        $this->booted = true;
    }

    /**
     * Discovery now unified via ProviderLocator to prevent dev/prod mismatches
     */
    private function loadAllProviders(): void
    {
        $context = $this->container->get(ApplicationContext::class);
        foreach (ProviderLocator::all($context) as $providerClass) {
            $this->addProvider($providerClass);
        }
    }

    private function addProvider(string $providerClass): void
    {
        // Prevent duplicate providers
        if (isset($this->providers[$providerClass])) {
            $this->log("Provider already registered, skipping", ['provider' => $providerClass], 'debug');
            return;
        }

        if (!class_exists($providerClass)) {
            $this->log("Extension provider not found", ['provider' => $providerClass]);
            return;
        }

        // Verify provider is actually a ServiceProvider subclass
        if (!is_subclass_of($providerClass, ServiceProvider::class)) {
            $this->log('Provider is not a ServiceProvider', ['provider' => $providerClass]);
            return;
        }

        try {
            $this->providers[$providerClass] = new $providerClass($this->container);
        } catch (\Throwable $e) {
            $this->log("Failed to instantiate provider", ['provider' => $providerClass, 'error' => $e->getMessage()]);
        }
    }

    private function registerProviders(): void
    {
        $context = $this->getContext();
        foreach ($this->providers as $providerClass => $provider) {
            try {
                if (method_exists($provider, 'register')) {
                    $provider->register($context);
                }
            } catch (\Throwable $e) {
                $this->log(
                    "Provider failed during register()",
                    ['provider' => $providerClass, 'error' => $e->getMessage()]
                );
            }
        }
    }

    /**
     * @param array<string, string|array<string>> $psr4
     */
    private function registerComposerAutoload(array $psr4, string $basePath): void
    {
        // Use Composer's ClassLoader if available
        static $composerLoader = null;

        if ($composerLoader === null) {
            $composerLoader = require base_path($this->getContext(), 'vendor/autoload.php');
        }

        if ($composerLoader instanceof \Composer\Autoload\ClassLoader) {
            foreach ($psr4 as $namespace => $path) {
                $fullPath = rtrim($basePath, '/\\') . '/' . ltrim($path, '/\\') . '/';
                if (is_dir($fullPath) && is_readable($fullPath)) {
                    $composerLoader->addPsr4($namespace, $fullPath, true); // prepend = true
                }
            }
            $composerLoader->register(true); // prepend so local wins in dev
        }
    }

    /**
     * Get all registered providers
     *
     * Auto-discovers if not yet done (lazy initialization).
     */
    /**
     * @return array<class-string, object>
     */
    public function getProviders(): array
    {
        // Auto-discover if not yet done (handles case when discover() wasn't explicitly called)
        if (!$this->discovered) {
            $this->discover();
        }
        return $this->providers;
    }

    /**
     * Check if a provider is registered
     */
    public function hasProvider(string $providerClass): bool
    {
        return isset($this->providers[$providerClass]);
    }

    /**
     * Sort providers by priority and bootAfter() dependencies
     */
    private function sortProviders(): void
    {
        // stable priority sort (then class for determinism)
        $rows = [];
        $i = 0;
        foreach ($this->providers as $class => $p) {
            $prio = $p instanceof OrderedProvider ? $p->priority() : 0;
            $rows[] = [$class, $prio, $i++, $p];
        }
        usort($rows, function ($a, $b) {
            $priorityComparison = $a[1] <=> $b[1];
            return $priorityComparison !== 0 ? $priorityComparison : $a[2] <=> $b[2];
        });

        // build graph edges from bootAfter(): dep -> node
        $graph = [];
        $in = [];
        foreach ($rows as [$class]) {
            $graph[$class] = [];
            $in[$class] = 0;
        }
        foreach ($rows as [$class,,,$p]) {
            if ($p instanceof OrderedProvider) {
                foreach ($p->bootAfter() as $dep) {
                    if ($dep !== $class && isset($graph[$dep])) {
                        $graph[$dep][] = $class;
                        $in[$class]++;
                    }
                }
            }
        }

        // Kahn's algorithm
        $q = [];
        foreach ($rows as [$class]) {
            if ($in[$class] === 0) {
                $q[] = $class;
            }
        }
        $ordered = [];
        while ($q) {
            $u = array_shift($q);
            $ordered[] = $u;
            foreach ($graph[$u] as $v) {
                if (--$in[$v] === 0) {
                    $q[] = $v;
                }
            }
        }

        if (count($ordered) !== count($rows)) {
            // cycle → fall back to priority order
            $ordered = array_map(fn($r) => $r[0], $rows);
            $this->log('Circular dependency detected in provider bootAfter(), using priority fallback');
        }

        $final = [];
        foreach ($ordered as $class) {
            $final[$class] = $this->providers[$class];
        }
        $this->providers = $final;
    }

    /**
     * Load providers from cache
     */
    /**
     * @return array<class-string, object>|null
     */
    private function loadFromCache(): ?array
    {
        $cacheFile = base_path($this->getContext(), 'bootstrap/cache/extensions.php');

        if (!file_exists($cacheFile)) {
            return null;
        }

        // Check cache age with env overrides
        // Dev default: 5s; Prod default: forever
        $maxAge = $this->isProduction()
            ? (int) (env('EXTENSIONS_CACHE_TTL_PROD', PHP_INT_MAX))
            : (int) env('EXTENSIONS_CACHE_TTL_DEV', 5);
        if (time() - filemtime($cacheFile) > $maxAge) {
            return null;
        }

        try {
            /** @var array<class-string<ServiceProvider>> $providerClasses */
            $providerClasses = require $cacheFile;
            $providers = [];

            foreach ($providerClasses as $providerClass) {
                if (class_exists($providerClass)) {
                    $providers[$providerClass] = new $providerClass($this->container);
                }
            }

            return $providers;
        } catch (\Throwable $e) {
            $this->log("Failed to load extensions cache", ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Save providers to cache
     */
    private function saveToCache(): void
    {
        $cacheFile = base_path($this->getContext(), 'bootstrap/cache/extensions.php');
        $dir = dirname($cacheFile);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $content = "<?php\n\n";
        $content .= "// Auto-generated extensions cache\n";
        $content .= "// Generated: " . date('Y-m-d H:i:s') . "\n\n";
        $content .= "return " . var_export(array_keys($this->providers), true) . ";\n";

        file_put_contents($cacheFile . '.tmp', $content, LOCK_EX);
        rename($cacheFile . '.tmp', $cacheFile);
        // Paranoid integrity check
        if (!is_file($cacheFile)) {
            throw new \RuntimeException('Failed to write extensions cache.');
        }
        @chmod($cacheFile, 0644);
    }

    /**
     * Force-write the extensions cache regardless of environment.
     * Optionally pass an explicit list of provider classes.
     *
     * @param list<class-string<ServiceProvider>>|null $providerClasses
     */
    public function writeCacheNow(?array $providerClasses = null): void
    {
        // Rebuild internal providers list deterministically
        $this->providers = [];
        $context = $this->container->get(ApplicationContext::class);
        $classes = $providerClasses ?? ProviderLocator::all($context);
        foreach ($classes as $cls) {
            $this->addProvider($cls);
        }
        // Save irrespective of environment
        $this->saveToCache();
    }

    /**
     * Check if running in production
     */
    private function isProduction(): bool
    {
        return env('APP_ENV', 'production') === 'production';
    }

    /**
     * Log with PSR-3 or fallback
     */
    /**
     * @param array<string, mixed> $context
     */
    private function log(string $message, array $context = [], string $level = 'warning'): void
    {
        if ($this->container->has(\Psr\Log\LoggerInterface::class)) {
            $logger = $this->container->get(\Psr\Log\LoggerInterface::class);
            if ($logger instanceof \Psr\Log\LoggerInterface) {
                // Use specific method calls instead of dynamic method call
                match ($level) {
                    'emergency' => $logger->emergency('[Extensions] ' . $message, $context),
                    'alert' => $logger->alert('[Extensions] ' . $message, $context),
                    'critical' => $logger->critical('[Extensions] ' . $message, $context),
                    'error' => $logger->error('[Extensions] ' . $message, $context),
                    'warning' => $logger->warning('[Extensions] ' . $message, $context),
                    'notice' => $logger->notice('[Extensions] ' . $message, $context),
                    'info' => $logger->info('[Extensions] ' . $message, $context),
                    'debug' => $logger->debug('[Extensions] ' . $message, $context),
                    default => $logger->warning('[Extensions] ' . $message, $context)
                };
            }
        } else {
            error_log('[Extensions] ' . $message);
        }
    }

    /**
     * Register extension metadata
     */
    /**
     * @param array<string, mixed> $info
     */
    public function registerMeta(string $providerClass, array $info): void
    {
        $this->container->get(\Glueful\Extensions\ExtensionMetadataRegistry::class)
            ->set($providerClass, $info);
    }

    /**
     * Get all extension metadata
     */
    /**
     * @return array<class-string, array<string, mixed>>
     */
    public function listMeta(): array
    {
        return $this->container->get(\Glueful\Extensions\ExtensionMetadataRegistry::class)->all();
    }

    /**
     * Get metadata for specific provider
     */
    /**
     * @return array<string, mixed>|null
     */
    public function getMeta(string $providerClass): ?array
    {
        return $this->container->get(\Glueful\Extensions\ExtensionMetadataRegistry::class)->get($providerClass);
    }

    /**
     * Get cache usage status
     */
    public function getCacheUsed(): bool
    {
        return $this->cacheUsed;
    }

    /**
     * Get startup summary for diagnostics
     */
    /**
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        return [
            'total_providers' => count($this->providers),
            'booted' => $this->booted,
            'cache_used' => $this->cacheUsed, // Use property instead of file_exists()
        ];
    }
}
