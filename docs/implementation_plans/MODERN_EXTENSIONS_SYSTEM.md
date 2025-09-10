# Modern Extensions System - Simplified Architecture

## Executive Summary

Complete redesign of the Glueful Extensions system, reducing implementation from **5,242 LOC/17 files** to **~400 LOC across 3 core files (+ small helpers)**, following proven patterns from Laravel and Symfony.

## Current Problems

The existing Extensions system suffers from severe over-engineering:

- **5,242 lines of code** across 17 PHP files
- **5 service classes** with unnecessary interfaces
- Complex manifest specifications (manifest.json + extensions.json + catalog)
- Custom event registry instead of standard patterns
- 73 unnecessary methods that serve no real purpose
- Multiple abstraction layers with no real benefit
- Extensive documentation exceeding actual code

## Proposed Solution: Laravel/Symfony-Inspired Architecture

### Core Principles

1. **Composer-native discovery** - Use existing package manager
2. **ServiceProvider pattern** - Familiar to all PHP developers
3. **PHP config files** - Type-safe and IDE-friendly
4. **Standard interfaces** - PSR-11 container interface for portability
5. **Convention over configuration** - Minimal boilerplate

## Implementation

Extension discovery follows a deterministic order: **1) enabled config → 2) dev_only config → 3) local development → 4) Composer packages**. Provider priority controls execution order, with `bootAfter()` dependencies adding edges to the graph. Circular dependencies fall back to priority order.

### 1. Extension Discovery via Composer

Extensions are standard Composer packages with special type:

```json
// composer.json for an extension
{
    "name": "glueful/blog-extension",
    "type": "glueful-extension",
    "autoload": {
        "psr-4": {
            "Glueful\\Blog\\": "src/"
        }
    },
    "extra": {
        "glueful": {
            "provider": "Glueful\\Blog\\BlogServiceProvider"
        }
    }
}
```

### 2. ServiceProvider Pattern

Each extension has a single ServiceProvider with two phases:
1. **Service Registration** - Static method for DI container services (compiled into container)
2. **Boot Phase** - Runtime initialization for routes, migrations, etc.

**Rule of thumb**: Services in static `services()` method, everything else in `boot()`.

#### UI/Asset Policy

Glueful follows an API-first philosophy with clear asset delivery guidelines:

- **No runtime views layer**: Extensions must not call `loadViewsFrom()` or equivalent - Glueful is API-first
- **No publishes step**: We don't copy assets/config into the app. Config defaults are merged in `register()`; DB migrations are discovered via `loadMigrationsFrom()`
- **Frontend assets must be prebuilt**: If an extension ships UI, it ships a compiled SPA bundle. In dev you may expose it with `mountStatic('name', __DIR__.'/../dist')`
- **Production delivery**: Serve via CDN; `mountStatic()` is for dev/private UIs

#### Non-Goals

The new Extensions system explicitly excludes these Laravel-style patterns:

- **No runtime views layer**: Extensions must not use `loadViewsFrom()` or equivalent
- **No asset publishing**: No `publishes()` or `publishesMigrations()` step that copies files into the app
- **No in-app extension config UIs**: Extensions configure through standard config files, not admin panels

**Rationale**: Glueful follows API-first architecture for build determinism and cacheability. Runtime view layers and asset publishing introduce non-deterministic behavior that conflicts with compiled containers and production optimization. Extensions deliver prebuilt assets via CDN or serve static files through `mountStatic()` for development.

### 3. Configuration

Simple PHP configuration file for enabling extensions:

**Production Configuration (`config/extensions.php`)**:
```php
// config/extensions.php (production)
return [
    /*
    |--------------------------------------------------------------------------
    | Enabled Extensions
    |--------------------------------------------------------------------------
    |
    | List of extension service providers to load. These are loaded
    | in the order specified. Overall discovery order:
    | 1) enabled, 2) dev_only (non-prod), 3) local dev, 4) composer.
    |
    */
    'enabled' => [
        // Core extensions
        Glueful\Blog\BlogServiceProvider::class,
        Glueful\Shop\ShopServiceProvider::class,
        
        // Third-party
        Vendor\Analytics\AnalyticsServiceProvider::class,
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Disabled Extensions (Blacklist)
    |--------------------------------------------------------------------------
    |
    | Extensions to block even if discovered via Composer. Useful for
    | disabling transitive dependencies or environment-specific blocking.
    |
    */
    'disabled' => [
        // Example: Block debug tools in production
        // Barryvdh\Debugbar\ServiceProvider::class,
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Local Extensions Path
    |--------------------------------------------------------------------------
    |
    | DISABLED in production for security and performance.
    |
    */
    'local_path' => null,
];
```

**Development Configuration (`config/extensions.php`)**:
```php
// config/extensions.php (development)
return [
    /*
    |--------------------------------------------------------------------------
    | Enabled Extensions
    |--------------------------------------------------------------------------
    |
    | List of extension service providers to load. These are loaded
    | in the order specified. Overall discovery order:
    | 1) enabled, 2) dev_only (non-prod), 3) local dev, 4) composer.
    |
    */
    'enabled' => [
        // Core extensions
        Glueful\Blog\BlogServiceProvider::class,
        Glueful\Shop\ShopServiceProvider::class,
        
        // Third-party
        Vendor\Analytics\AnalyticsServiceProvider::class,
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Development Extensions
    |--------------------------------------------------------------------------
    |
    | Extensions only loaded in non-production environments.
    |
    */
    'dev_only' => [
        Glueful\Debug\DebugServiceProvider::class,
        Glueful\Profiler\ProfilerServiceProvider::class,
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Disabled Extensions (Blacklist)
    |--------------------------------------------------------------------------
    |
    | Block specific extensions even if discovered. Useful for temporarily
    | disabling problematic extensions during development.
    |
    */
    'disabled' => [
        // Example: Temporarily disable broken extension
        // Vendor\Broken\BrokenServiceProvider::class,
    ],
    
    /*
    |--------------------------------------------------------------------------
    | Local Extensions Path
    |--------------------------------------------------------------------------
    |
    | Path to scan for local extensions during development.
    | Set to null to disable local extension scanning.
    |
    */
    'local_path' => env('APP_ENV') === 'production' ? null : 'extensions',
    
    /*
    |--------------------------------------------------------------------------
    | Composer Package Scanning
    |--------------------------------------------------------------------------
    |
    | Whether to scan Composer packages for glueful-extension types.
    | Set to false to disable Composer scanning (for troubleshooting).
    |
    */
    'scan_composer' => true,
];
```

### Enterprise Configuration Patterns

The Extensions system supports advanced configuration patterns for enterprise deployments:

#### Exclusive Allow-List Mode (Ultra-Secure)

For maximum security in regulated environments, use `extensions.only` to explicitly specify ALL allowed extensions:

```php
// config/extensions.php (production - banking/healthcare)
return [
    /*
    |--------------------------------------------------------------------------
    | Exclusive Allow-List Mode
    |--------------------------------------------------------------------------
    |
    | When 'only' is set, ONLY these providers load. Nothing else.
    | This overrides all other discovery methods for maximum security.
    |
    */
    'only' => [
        // Core business logic
        'App\\Banking\\CoreBankingProvider',
        'App\\Banking\\TransactionProvider',
        'App\\Banking\\ComplianceProvider',
        
        // Approved third-party
        'Vendor\\Audit\\AuditProvider',
        'Vendor\\Encryption\\FIPSProvider',
        
        // NOTHING else can load - no auto-discovery
    ],
    
    // Note: 'enabled', 'disabled', 'dev_only' are ignored when 'only' is set
];
```

#### Environment-Specific Blacklisting

Dynamically disable extensions based on environment:

```php
// config/extensions.php
return [
    'enabled' => [
        // Your standard extensions
    ],
    
    'disabled' => match(env('APP_ENV')) {
        'production' => [
            'Barryvdh\\Debugbar\\ServiceProvider',     // No debug tools in prod
            'Vendor\\Profiler\\ProfilerProvider',       // No profiling in prod
            'Vendor\\Analytics\\PhoneHomeProvider',     // Privacy concern
        ],
        'staging' => [
            'Vendor\\Experimental\\BetaProvider',       // Not ready for staging
        ],
        default => [],
    },
];
```

#### Transitive Dependency Blocking

Block unwanted extensions from package dependencies:

```php
// config/extensions.php
return [
    'enabled' => [
        'Vendor\\MainPackage\\Provider', // We want this
    ],
    
    'disabled' => [
        // MainPackage requires TelemetryPackage, but we don't want its extension
        'Vendor\\TelemetryPackage\\TelemetryProvider',
        
        // Another package includes optional debugging we don't need
        'Vendor\\SubPackage\\DebugProvider',
    ],
];
```

#### Compliance-Driven Configuration

For audited environments requiring explicit approval:

```php
// config/extensions.php
return [
    // Start with allow-list for approved extensions
    'only' => json_decode(file_get_contents(
        base_path('compliance/approved-extensions.json')
    ), true),
    
    // Or use traditional mode with audit log
    'enabled' => [
        // Approved on 2024-01-15 by Security Team (Ticket: SEC-123)
        'App\\Core\\CoreProvider',
        
        // Approved on 2024-02-01 by Compliance (Ticket: COMP-456)
        'Vendor\\Secure\\SecureProvider',
    ],
    
    'disabled' => [
        // Blocked on 2024-03-01 - Security vulnerability (CVE-2024-1234)
        'Vendor\\Vulnerable\\VulnerableProvider',
    ],
];
```

#### Dynamic Feature Flags

Combine with feature flags for runtime control:

```php
// config/extensions.php
return [
    'enabled' => array_filter([
        'App\\Core\\CoreProvider',
        
        // Conditionally enable based on feature flags
        feature('new-payment-system') 
            ? 'App\\Payments\\PaymentProvider' 
            : null,
        
        feature('beta-analytics')
            ? 'App\\Analytics\\AnalyticsProvider'
            : null,
    ]),
    
    'disabled' => [
        // Temporarily disabled due to incident
        ...config('incidents.disabled_extensions', []),
    ],
];
```

#### Zero-Trust Production

Maximum paranoia configuration:

```php
// config/extensions.php (production)
return [
    // Disable ALL auto-discovery
    'scan_composer' => false,
    'local_path' => null,
    
    // Use ONLY explicit allow-list
    'only' => require base_path('config/extensions-whitelist.php'),
    
    // Log any discovery attempts (for monitoring)
    'log_discovery_attempts' => true,
];
```

These patterns ensure enterprise-grade control over extension loading with full auditability and compliance support.

### 4. Core Implementation

#### ProviderLocator.php (~60 lines)

Unified provider discovery used by both compile-time and runtime phases to prevent dev/prod mismatches.

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions;

/**
 * Unified provider discovery for both compile-time and runtime phases.
 * Prevents mismatches where config-enabled extensions work in dev but break in prod.
 */
final class ProviderLocator
{
    /**
     * Get all extension providers in deterministic discovery order.
     * Supports enterprise features: allow-list mode and blacklisting.
     * @return list<class-string>
     */
    public static function all(): array
    {
        // Exclusive allow-list mode (highest priority)
        if ($only = config('extensions.only')) {
            return array_values((array) $only);
        }

        $providers = [];

        // 1) enabled (preserve order)
        foreach ((array) config('extensions.enabled', []) as $cls) {
            $providers[] = $cls;
        }

        // 2) dev_only (preserve order)
        if (($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? 'production') !== 'production') {
            foreach ((array) config('extensions.dev_only', []) as $cls) {
                $providers[] = $cls;
            }

            // 3) local scan (sort by folder name for stability)
            if ($localPath = config('extensions.local_path')) {
                $local = self::scanLocalExtensions($localPath);
                sort($local, SORT_STRING);
                array_push($providers, ...$local);
            }
        }

        // 4) composer scan (already deterministic in PackageManifest)
        if (config('extensions.scan_composer', true)) {
            $providers = array_merge($providers, array_values((new PackageManifest())->getGluefulProviders()));
        }

        // dedupe while preserving first occurrence
        $providers = array_values(array_unique($providers, SORT_STRING));
        
        // Apply blacklist filter with strict comparison and normalization
        $disabled = (array) config('extensions.disabled', []);
        $disabled = array_values(array_unique(array_map('strval', $disabled)));
        
        if (!empty($disabled)) {
            $providers = array_values(array_filter($providers, fn($cls) => !in_array($cls, $disabled, true)));
        }
        
        return $providers;
    }
    
    /**
     * Scan local extensions with same rules as ExtensionManager.
     * Duplicated intentionally to keep compile-time/runtime parity; update both together.
     * @return list<class-string>
     */
    private static function scanLocalExtensions(string $path): array
    {
        $providers = [];
        $extensionsPath = base_path($path);
        
        if (!is_dir($extensionsPath)) {
            return [];
        }
        
        // include only immediate subdirs; glob() excludes dot-dirs by default
        $pattern = $extensionsPath . '/*/composer.json';
        $files = glob($pattern) ?: [];
        
        // Same limits to prevent pathological folders
        $maxProjects = 200;
        if (count($files) > $maxProjects) {
            error_log("[ProviderLocator] Too many local extensions found, limiting to {$maxProjects}");
            $files = array_slice($files, 0, $maxProjects);
        }
        
        foreach ($files as $file) {
            // Skip symlinks and check file readability
            if (is_link($file) || !is_readable($file)) {
                continue;
            }
            
            // Safe filesize check to prevent warnings on unreadable files
            $filesize = @filesize($file);
            if ($filesize === false || $filesize > 1024 * 100) {
                continue; // Skip unreadable or oversized files
            }
            
            try {
                $json = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
                if (isset($json['extra']['glueful']['provider'])) {
                    $providers[] = $json['extra']['glueful']['provider'];
                }
            } catch (\JsonException $e) {
                error_log("[ProviderLocator] Invalid composer.json in {$file}: " . $e->getMessage());
            }
        }
        
        return $providers;
    }
}
```

**Local Development Autoloading**: For compile-time discovery of local extensions, add path repositories to your root `composer.json` (development only):

```json
// composer.json (development)
{
  "repositories": [
    { 
      "type": "path", 
      "url": "extensions/*", 
      "options": { "symlink": true } 
    }
  ],
  "require-dev": {
    "glueful/my-extension": "*"
  },
  "prefer-stable": true,
  "minimum-stability": "dev"
}
```

This provides PSR-4 autoloading at compile-time and maintains perfect dev/prod parity. Without this, runtime `ExtensionManager` will add PSR-4 but compile-time discovery won't have class access.

**Note**: `"minimum-stability": "dev"` is only required for path repositories. Use `"prefer-stable": true` to maintain stable packages for everything else.

#### PackageManifest.php (~50 lines)

Handles Composer discovery across all installation formats - installed.php and both installed.json shapes work seamlessly.

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions;

/**
 * Discovers Glueful extensions from Composer's installed metadata.
 * Supports Composer 2 installed.php and installed.json (both shapes).
 */
final class PackageManifest
{
    /** @var array<string, class-string> package name => provider FQCN */
    private array $providers;

    public function __construct()
    {
        $this->providers = $this->discover();
    }

    /** @return array<string, class-string> */
    public function getGluefulProviders(): array
    {
        return $this->providers;
    }

    /** @return array<string, class-string> */
    private function discover(): array
    {
        // Prefer installed.php — normalized and fast
        $installedPhp = base_path('vendor/composer/installed.php');
        if (is_file($installedPhp)) {
            try {
                /** @var array $installed */
                $installed = require $installedPhp;
                return $this->extractFromInstalledPhp($installed);
            } catch (\Throwable $e) {
                error_log('[Extensions] installed.php load failed: ' . $e->getMessage());
            }
        }

        // Fallback to installed.json (may be array-of-packages or {packages: [...]})
        $installedJson = base_path('vendor/composer/installed.json');
        if (!is_file($installedJson)) {
            return [];
        }

        try {
            $data = json_decode((string) file_get_contents($installedJson), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            error_log('[Extensions] installed.json parse failed: ' . $e->getMessage());
            return [];
        }

        $packages = $data['packages'] ?? (is_array($data) ? $data : []);
        return $this->extractFromPackagesArray($packages);
    }

    /** @param array $installed @return array<string, class-string> */
    private function extractFromInstalledPhp(array $installed): array
    {
        $out = [];

        // Common Composer 2 shape
        if (isset($installed['versions']) && is_array($installed['versions'])) {
            foreach ($installed['versions'] as $name => $pkg) {
                if (($pkg['type'] ?? '') !== 'glueful-extension') continue;
                $provider = $pkg['extra']['glueful']['provider'] ?? null;
                if (is_string($provider) && str_contains($provider, '\\')) {
                    // Optional compatibility check
                    $currentVersion = \defined('GLUEFUL_VERSION') ? GLUEFUL_VERSION : '0.0.0';
                    $min = $pkg['extra']['glueful']['minVersion'] ?? null;
                    $isProd = ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? 'production') === 'production';
                    
                    if ($isProd && is_string($min) && version_compare($currentVersion, $min, '<')) {
                        // skip provider in prod, warn in logs
                        error_log("[Extensions] {$name} requires Glueful {$min}, current {$currentVersion} - skipping in production");
                        continue;
                    } elseif (is_string($min) && version_compare($currentVersion, $min, '<')) {
                        error_log("[Extensions] {$name} requires Glueful {$min}, current {$currentVersion}");
                        // Still allow registration in dev - just warn
                    }
                    $out[$name] = $provider;
                }
            }
            return $out;
        }

        // Multi-vendor datasets (less common)
        foreach ($installed as $entry) {
            if (!is_array($entry) || !isset($entry['versions'])) continue;
            foreach ($entry['versions'] as $name => $pkg) {
                if (($pkg['type'] ?? '') !== 'glueful-extension') continue;
                $provider = $pkg['extra']['glueful']['provider'] ?? null;
                if (is_string($provider) && str_contains($provider, '\\')) {
                    $out[$name] = $provider;
                }
            }
        }

        return $out;
    }

    /** @param array<int, array<string,mixed>> $packages @return array<string, class-string> */
    private function extractFromPackagesArray(array $packages): array
    {
        $out = [];
        foreach ($packages as $pkg) {
            if (!is_array($pkg)) continue;
            if (($pkg['type'] ?? '') !== 'glueful-extension') continue;

            $provider = $pkg['extra']['glueful']['provider'] ?? null;
            if (is_string($provider) && str_contains($provider, '\\')) {
                $name = $pkg['name'] ?? 'unknown';
                $out[$name] = $provider;
            }
        }
        ksort($out); // deterministic order by package name
        return $out;
    }
}
```

#### ExtensionManager.php (~200 lines)

Providers are ordered by priority() and bootAfter() dependencies (topological). Cycles fall back to priority order and log a warning. Priority is the primary order; bootAfter() only adds edges.

**Note**: Providers are de-duplicated by class FQCN across config, composer discovery, and local scan. If a provider appears in both config and composer discovery, it's loaded once; we log a debug note.

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\DI\Container;
use Psr\Log\LoggerInterface;

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

    private bool $booted = false;
    private bool $cacheUsed = false;

    public function __construct(private \Psr\Container\ContainerInterface $container) {}
    
    /**
     * Discover and register all extensions with caching
     */
    public function discover(): void
    {
        // Try cache first
        if ($cached = $this->loadFromCache()) {
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
        
        foreach ($this->providers as $providerClass => $provider) {
            try {
                if (method_exists($provider, 'boot')) {
                    $provider->boot();
                }
            } catch (\Throwable $e) {
                $this->log("Provider failed during boot()", ['provider' => $providerClass, 'error' => $e->getMessage()]);
            }
        }
        
        $this->booted = true;
    }
    
    /** @deprecated Superseded by ProviderLocator::all() - kept for backward compatibility */
    private function loadFromConfig(): void
    {
        throw new \RuntimeException('loadFromConfig() is deprecated; use ProviderLocator::all()');
    }
    
    /**
     * Discovery now unified via ProviderLocator to prevent dev/prod mismatches
     */
    private function loadAllProviders(): void
    {
        foreach (ProviderLocator::all() as $providerClass) {
            $this->addProvider($providerClass);
        }
    }
    
    /** @deprecated Superseded by ProviderLocator::all() - kept for backward compatibility */
    private function scanLocalExtensions(string $path): void
    {
        // This method is no longer called - discovery is unified via ProviderLocator
        throw new \RuntimeException('scanLocalExtensions() is deprecated; use ProviderLocator::all()');
    }
    
    private function addProvider(string $providerClass): void
    {
        // Prevent duplicate providers
        if (isset($this->providers[$providerClass])) {
            $this->log("Provider already registered, skipping", ['provider' => $providerClass], 'debug');
            return;
        }
        
        if (!class_exists($providerClass)) {
            $this->log("Extension provider not found", ['provider' => $providerClass], 'notice');
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
        foreach ($this->providers as $providerClass => $provider) {
            try {
                if (method_exists($provider, 'register')) {
                    $provider->register();
                }
            } catch (\Throwable $e) {
                $this->log("Provider failed during register()", ['provider' => $providerClass, 'error' => $e->getMessage()]);
                
                // Fail fast in testing with aggressive error handling
                if (($_ENV['APP_ENV'] ?? getenv('APP_ENV')) === 'testing' && 
                    ($_ENV['APP_FAIL_FAST_EXTENSIONS'] ?? getenv('APP_FAIL_FAST_EXTENSIONS')) === '1') {
                    throw new \RuntimeException(
                        "Extension provider {$providerClass} failed during register(): " . $e->getMessage(), 
                        previous: $e
                    );
                }
            }
        }
    }
    
    /** @deprecated No longer needed after unifying discovery via ProviderLocator */
    private function registerComposerAutoload(array $psr4, string $basePath): void
    {
        throw new \RuntimeException('registerComposerAutoload() is deprecated; use path repositories in composer.json');
    }
    
    /**
     * Get all registered providers
     */
    public function getProviders(): array
    {
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
        usort($rows, fn($a,$b) => $a[1] <=> $b[1] ?: $a[2] <=> $b[2]);

        // build graph edges from bootAfter(): dep -> node
        $graph = []; $in = [];
        foreach ($rows as [$class]) { $graph[$class] = []; $in[$class] = 0; }
        foreach ($rows as [$class,,,$p]) {
            if ($p instanceof OrderedProvider) {
                foreach (array_unique($p->bootAfter()) as $dep) {
                    if ($dep === $class) continue;
                    if (isset($graph[$dep])) {
                        $graph[$dep][] = $class;
                        $in[$class]++;
                    } else {
                        $this->log("bootAfter() references unknown provider {$dep}", ['provider' => $class], 'debug');
                    }
                }
            }
        }

        // Kahn's algorithm
        $q = []; foreach ($rows as [$class]) if ($in[$class] === 0) $q[] = $class;
        $ordered = [];
        while ($q) {
            $u = array_shift($q); $ordered[] = $u;
            foreach ($graph[$u] as $v) if (--$in[$v] === 0) $q[] = $v;
        }

        if (count($ordered) !== count($rows)) {
            // cycle → fall back to priority order
            $ordered = array_map(fn($r) => $r[0], $rows);
            $this->log('Circular dependency detected in provider bootAfter(), using priority fallback');
        }

        $final = [];
        foreach ($ordered as $class) $final[$class] = $this->providers[$class];
        $this->providers = $final;
    }
    
    
    /**
     * Force write cache regardless of environment (for commands)
     */
    public function writeCacheNow(): void
    {
        $this->saveToCache();
    }

    /**
     * Save providers to cache
     */
    private function saveToCache(): void
    {
        $cacheFile = base_path('bootstrap/cache/extensions.php');
        $dir = dirname($cacheFile);
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $payload = [
            'v' => 1, // bump on format changes
            'generated_at' => time(),
            'providers' => array_keys($this->providers),
        ];

        $content = "<?php\nreturn " . var_export($payload, true) . ";\n";
        file_put_contents($cacheFile . '.tmp', $content, LOCK_EX);
        rename($cacheFile . '.tmp', $cacheFile);
        // Paranoid integrity check
        if (!is_file($cacheFile)) {
            throw new \RuntimeException('Failed to write extensions cache.');
        }
        @chmod($cacheFile, 0644);
        
        // Invalidate OPcache to ensure fresh cache is loaded
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($cacheFile, true);
        }
    }

    private function loadFromCache(): ?array
    {
        $cacheFile = base_path('bootstrap/cache/extensions.php');
        if (!is_file($cacheFile)) return null;

        $maxAge = $this->isProduction()
            ? (int) (env('EXTENSIONS_CACHE_TTL_PROD', PHP_INT_MAX))
            : (int) env('EXTENSIONS_CACHE_TTL_DEV', 5);
        $data = @require $cacheFile;
        if (!is_array($data) || ($data['v'] ?? 0) !== 1) return null;
        if (time() - (int) ($data['generated_at'] ?? 0) > $maxAge) return null;

        $providers = [];
        foreach ((array) ($data['providers'] ?? []) as $providerClass) {
            if (class_exists($providerClass)) {
                $providers[$providerClass] = new $providerClass($this->container);
            }
        }
        return $providers;
    }
    
    /**
     * Check if running in production
     */
    private function isProduction(): bool
    {
        return ($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? 'production') === 'production';
    }
    
    /**
     * Log with PSR-3 logger (always available via CoreServiceProvider)
     */
    private function log(string $message, array $context = [], string $level = 'warning'): void
    {
        /** @var \Psr\Log\LoggerInterface $logger */
        $logger = $this->container->get(\Psr\Log\LoggerInterface::class);
        $logger->{$level}('[Extensions] ' . $message, $context);
    }
    
    /**
     * Register extension metadata
     */
    public function registerMeta(string $providerClass, array $info): void
    {
        $this->container->get(\Glueful\Extensions\ExtensionMetadataRegistry::class)
            ->set($providerClass, $info);
    }

    /**
     * Get all extension metadata
     */
    public function listMeta(): array
    {
        return $this->container->get(\Glueful\Extensions\ExtensionMetadataRegistry::class)->all();
    }

    /**
     * Get metadata for specific provider
     */
    public function getMeta(string $providerClass): ?array
    {
        return $this->container->get(\Glueful\Extensions\ExtensionMetadataRegistry::class)->get($providerClass);
    }
    
    /**
     * Get startup summary for diagnostics
     */
    public function getSummary(): array
    {
        return [
            'total_providers' => count($this->providers),
            'booted' => $this->booted,
            'cache_used' => $this->cacheUsed,
        ];
    }
}
```

#### ServiceProvider.php (API-First Base Class ~50 lines)

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions;

use Glueful\DI\Container;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Routing\Router;

/**
 * Optional: providers can influence boot ordering.
 */
interface OrderedProvider
{
    /** Higher boots later (default 0). */
    public function priority(): int;

    /**
     * Providers that must boot before this one.
     * @return array<class-string<ServiceProvider>>
     */
    public function bootAfter(): array;
}

/**
 * Optional: providers can declare services they provide for future lazy loading optimization.
 * Currently unused but reserved for future deferral strategies.
 */
interface DeferrableProvider
{
    /** @return array<class-string> */
    public function provides(): array;
}

/**
 * API-first base provider (PSR-11 compliant).
 */
abstract class ServiceProvider
{
    protected \Psr\Container\ContainerInterface $app;

    public function __construct(\Psr\Container\ContainerInterface $app) { $this->app = $app; }

    /** 
     * Register services in DI container (called during compilation).
     * Returns service definitions that get compiled into the container.
     * @return array<string, mixed>
     */
    public static function services(): array { return []; }

    /** Register runtime configuration and setup. */
    public function register(): void { 
        /* 
         * Extensions can safely do config merging, route registration, etc. here.
         * Service mutation blocking is enforced at the container level via 
         * bind/singleton/alias APIs when container is frozen.
         */
    }

    /** Boot after all providers are registered. */
    public function boot(): void { /* optional */ }
    
    /** Load routes from a file; file will use $router from the container. */
    protected function loadRoutesFrom(string $path): void
    {
        if (!is_file($path) || !$this->app->has(Router::class)) return;
        /** @var Router $router */
        $router = $this->app->get(Router::class);
        require $path;
    }

    /** Register migrations directory. */
    protected function loadMigrationsFrom(string $dir): void
    {
        if (!is_dir($dir) || !$this->app->has(MigrationManager::class)) return;
        /** @var MigrationManager $mm */
        $mm = $this->app->get(MigrationManager::class);
        $mm->addMigrationPath($dir);
    }

    /** Merge default config (app overrides always win). */
    protected function mergeConfig(string $key, array $defaults): void
    {
        if (!$this->app->has('config.manager')) return;
        $this->app->get('config.manager')->merge($key, $defaults);
    }
    
    /**
     * Optional: register translation catalogs for API messages only.
     * Expects files like messages.en.php returning ['key' => 'Message'].
     */
    protected function loadMessageCatalogs(string $dir, string $domain = 'messages'): void
    {
        if (!is_dir($dir) || !$this->app->has('translation.manager')) return;
        $translator = $this->app->get('translation.manager');

        foreach (glob($dir . '/messages.*.php') ?: [] as $file) {
            $base = pathinfo($file, PATHINFO_FILENAME); // messages.en
            $parts = explode('.', $base);
            $locale = $parts[1] ?? 'en';
            $messages = require $file;
            if (is_array($messages)) {
                $translator->addMessages($locale, $domain, $messages);
            }
        }
    }
    
    /**
     * Serves prebuilt static assets via Symfony HttpFoundation (no templating). 
     * Mounted at /extensions/{mount} with strict path and caching guards. 
     * For public production assets prefer a CDN; mountStatic() is ideal for 
     * dev/private UIs and immutable bundles.
     */
    protected function mountStatic(string $mount, string $dir): void
    {
        // Validate mount name to prevent route collisions and ensure URL safety
        if (!preg_match('/^[a-z0-9\-]+$/', $mount)) {
            throw new \InvalidArgumentException("Invalid mount name '{$mount}'. Only lowercase letters, numbers, and hyphens allowed.");
        }
        
        if (!$this->app->has(\Glueful\Routing\Router::class) || !is_dir($dir)) return;
        $realDir = realpath($dir);
        if ($realDir === false) return;

        /** @var \Glueful\Routing\Router $router */
        $router = $this->app->get(\Glueful\Routing\Router::class);

        // Shared file serving logic for both routes
        $serveFile = function (\Symfony\Component\HttpFoundation\Request $request, string $path) use ($realDir) {
            if (headers_sent()) return new \Symfony\Component\HttpFoundation\Response('', 404);

            // deny dotfiles and PHP by policy (guard against empty basename)
            $basename = basename($path);
            if ($basename === '' || $basename[0] === '.' || str_ends_with(strtolower($basename), '.php')) {
                return new \Symfony\Component\HttpFoundation\Response('', 404);
            }

            $requested = realpath($realDir . DIRECTORY_SEPARATOR . $path);
            if ($requested === false
                || !str_starts_with($requested, $realDir . DIRECTORY_SEPARATOR)
                || !is_file($requested)) {
                return new \Symfony\Component\HttpFoundation\Response('', 404);
            }

            $mtime = filemtime($requested) !== false ? filemtime($requested) : time();
            $etag  = md5_file($requested) !== false ? md5_file($requested) : sha1($requested);

            // mime (prefer Symfony MimeTypes; fallback to mime_content_type)
            $guesser = \Symfony\Component\Mime\MimeTypes::getDefault();
            $mimeGuess = mime_content_type($requested);
            $mime = $guesser->guessMimeType($requested) ?? 
                ($mimeGuess !== false ? $mimeGuess : 'application/octet-stream');

            $resp = new \Symfony\Component\HttpFoundation\BinaryFileResponse($requested);
            $resp->headers->set('Content-Type', $mime);
            $resp->headers->set('X-Content-Type-Options', 'nosniff');
            $resp->headers->set('Cross-Origin-Resource-Policy', 'same-origin'); // Prevent passive leaks via <img src>
            // Basic hardening headers (safe defaults; override in app as needed)
            $resp->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:;");
            $resp->headers->set('Referrer-Policy', 'no-referrer');
            $resp->headers->set('X-Frame-Options', 'SAMEORIGIN');
            $resp->headers->set('X-XSS-Protection', '0'); // Modern browsers rely on CSP
            $resp->setPublic();
            $resp->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
            $resp->setETag('"' . $etag . '"'); // strong ETag; consider weak (W/"...") if upstream transforms
            $resp->setLastModified((new \DateTimeImmutable())->setTimestamp($mtime));
            $resp->setContentDisposition(\Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_INLINE, $basename);

            if ($resp->isNotModified($request)) return $resp;
            return $resp;
        };

        // Serve index.html for SPA root route
        $indexCallback = function () use ($realDir) {
            $index = $realDir . DIRECTORY_SEPARATOR . 'index.html';
            if (!is_file($index)) {
                return new \Symfony\Component\HttpFoundation\Response('', 404);
            }
            $resp = new \Symfony\Component\HttpFoundation\BinaryFileResponse($index);
            // Apply the same hardening headers to index as to asset responses
            $resp->headers->set('X-Content-Type-Options', 'nosniff');
            $resp->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
            $resp->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:;");
            $resp->headers->set('Referrer-Policy', 'no-referrer');
            $resp->headers->set('X-Frame-Options', 'SAMEORIGIN');
            $resp->headers->set('X-XSS-Protection', '0');
            return $resp;
        };

        // Asset serving routes (GET only - HEAD handled by framework)
        $router->get("/extensions/{$mount}/{path}", $serveFile)->where('path', '.+');
        
        // Index serving routes (GET only - HEAD handled by framework)
        $router->get("/extensions/{$mount}", $indexCallback);
    }
    
    
    /** Register console commands. */
    protected function commands(array $commands): void
    {
        if (!$this->runningInConsole() || !$this->app->has('console.application')) return;
        $console = $this->app->get('console.application');
        foreach ($commands as $class) {
            $console->add($this->app->get($class));
        }
    }

    protected function runningInConsole(): bool
    {
        return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
    }
}
```

#### ExtensionMetadataRegistry.php (~30 lines)

Simple registry for extension metadata used by the `registerMeta()` helper method.

```php
<?php
// src/Extensions/ExtensionMetadataRegistry.php
declare(strict_types=1);

namespace Glueful\Extensions;

final class ExtensionMetadataRegistry
{
    /** @var array<class-string<ServiceProvider>, array> */
    private array $meta = [];

    public function set(string $providerClass, array $data): void
    {
        $this->meta[$providerClass] = $data;
    }

    /** @return array<class-string<ServiceProvider>, array> */
    public function all(): array
    {
        // deterministic by provider FQCN
        ksort($this->meta);
        return $this->meta;
    }

    public function get(string $providerClass): ?array
    {
        return $this->meta[$providerClass] ?? null;
    }
}
```

### 5. Framework Integration

**Two-Phase Integration**:

#### Phase 1: Service Discovery (During DI Compilation)

> **🚨 CRITICAL BUILD ORDER REQUIREMENT 🚨**
> 
> **The `config()` function MUST be fully initialized before calling `ProviderLocator::all()`.**
> 
> **Failure to load `.env` and configuration files first will result in:**
> - `config('extensions.enabled')` returns empty array → no providers loaded
> - `config('extensions.disabled')` returns empty array → disabled providers get loaded
> - **Silent failures in production** with zero extension discovery
> 
> **This is the #1 cause of "extensions work in dev but not production" issues.**

**✅ Correct Build Order:**
```php
// 1. FIRST: Load configuration (this enables config() calls)
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

// Initialize configuration system
require_once __DIR__ . '/../config/app.php';

// 2. SECOND: Now ProviderLocator::all() can access config()
use Glueful\Extensions\ProviderLocator;
use Glueful\Extensions\ExtensionServiceCompiler;
use Symfony\Component\DependencyInjection\ContainerBuilder;

private static function loadExtensionServices(ContainerBuilder $builder): void
{
    // Use unified discovery to prevent dev/prod mismatches
    $compiler = new ExtensionServiceCompiler($builder);
    foreach (ProviderLocator::all() as $providerClass) {
        if (method_exists($providerClass, 'services')) {
            $compiler->register($providerClass::services(), $providerClass);
        }
    }
}
```

Note: Ensure any compiler passes that consume service tags (e.g., `console.command`, `event.subscriber`, `middleware`) are registered after running `ExtensionServiceCompiler` and before `$builder->compile()`. This guarantees tags are bound correctly during compilation.

#### Phase 2: Extension Boot (Runtime)
```php
// In Framework::initializeExtensions()
private function initializeExtensions(): void
{
    $extensions = $this->container->get(\Glueful\Extensions\ExtensionManager::class);
    
    // Boot extensions (routes, migrations, etc.)
    $extensions->boot();
}
```

### Container Build Order & Freeze Timing

**Critical**: `loadExtensionServices()` must execute **before** container compilation and freeze to ensure all extension services are available during dependency resolution.

```php
// Correct build order in your ContainerFactory or similar:
private static function buildContainer(): ContainerInterface
{
    $builder = new ContainerBuilder();
    
    // 1. Load core framework services
    self::loadCoreServices($builder);
    
    // 2. Load extension services BEFORE compilation
    self::loadExtensionServices($builder);
    
    // 3. Run compiler passes (optimization, validation, etc.)
    $builder->compile();
    
    // 4. Container is now frozen - no more service mutations allowed
    return $builder;
}

private static function loadExtensionServices(ContainerBuilder $builder): void
{
    // Use unified discovery to prevent dev/prod mismatches
    $compiler = new ExtensionServiceCompiler($builder);
    foreach (ProviderLocator::all() as $providerClass) {
        if (method_exists($providerClass, 'services')) {
            $compiler->register($providerClass::services(), $providerClass);
        }
    }
}

/**
 * Critical: Configuration Loading Order
 * 
 * For compile-time discovery to work properly, configuration must be 
 * available before container compilation. Ensure this order:
 * 
 * 1. Load environment variables (.env file)
 * 2. Load configuration files (config/extensions.php, etc.)
 * 3. Discover and compile extension services
 * 4. Compile and freeze container
 * 
 * If config() calls fail during compilation, verify that the configuration
 * system is initialized before calling loadExtensionServices().
 */
```

### Container Freeze Protection

Extensions are prevented from mutating services after compilation. The Container implements freeze protection:

```php
// In the Container implementation (src/DI/Container.php)
public function isFrozen(): bool
{
    return $this->isCompiled();
}

public function set(string $id, mixed $service): void
{
    if ($this->container instanceof \Symfony\Component\DependencyInjection\ContainerBuilder) {
        // Disallow mutations once compiled/frozen
        if ($this->container->isCompiled()) {
            throw new \RuntimeException('Cannot set services on compiled container');
        }
        $this->container->set($id, $service);
        return;
    }
    throw new \RuntimeException('Cannot set services on compiled container');
}
```

**Important**: Extensions must declare all services at compile-time via the static `services()` method. Runtime service registration is intentionally prevented to ensure deterministic builds and optimal performance.

This approach allows extensions to safely perform configuration merging, route registration, and migration discovery in `register()` and `boot()` methods while preventing service container mutations after compilation.

### Compiler Adapter (drop-in)

```php
<?php
// src/Extensions/ExtensionServiceCompiler.php
declare(strict_types=1);

namespace Glueful\Extensions;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Translates Provider::services() arrays into Symfony DI definitions.
 * Supported keys per service: class, arguments, shared, alias, tags, factory.
 */
final class ExtensionServiceCompiler
{
    private string $currentProvider = 'unknown';
    private array $serviceProviders = [];

    public function __construct(private ContainerBuilder $builder) {}

    /**
     * @param array<string, array<string,mixed>> $serviceDefs
     * @param string|null $providerClass Provider class for collision tracking
     */
    public function register(array $serviceDefs, ?string $providerClass = null): void
    {
        $this->currentProvider = $providerClass ?? 'unknown';
        
        foreach ($serviceDefs as $id => $def) {
            if (!is_array($def)) {
                continue; // ignore unsupported entries
            }

            $class = $def['class'] ?? (is_string($id) ? $id : null);
            if (!is_string($class)) {
                continue;
            }

            $definition = new Definition($class);
            $definition->setPublic((bool)($def['public'] ?? false)); // Private by default
            $definition->setShared((bool)($def['shared'] ?? true));

            // Arguments: "@id" => Reference('id') with validation
            $args = [];
            foreach (($def['arguments'] ?? []) as $arg) {
                if (is_string($arg)) {
                    if ($arg === '@') {
                        throw new \InvalidArgumentException("Invalid argument '@' for service '{$id}'. Expected '@service_id'.");
                    }
                    if (str_starts_with($arg, '@@')) {
                        throw new \InvalidArgumentException("Invalid argument '{$arg}' for service '{$id}'. Use single '@' prefix.");
                    }
                    if (str_starts_with($arg, '@')) {
                        $args[] = new Reference(substr($arg, 1));
                    } else {
                        $args[] = $arg;
                    }
                } else {
                    $args[] = $arg;
                }
            }
            if ($args) {
                $definition->setArguments($args);
            }

            // Factory (prefer [serviceId, method] or ClassName::method)
            if (isset($def['factory'])) {
                $factory = $def['factory'];
                
                // Prevent closures in compiled containers
                if ($factory instanceof \Closure) {
                    throw new \InvalidArgumentException("Closures are not allowed as factories in compiled containers. Use ['@service','method'] or 'Class::method' instead for service: {$id}");
                }
                
                if (is_array($factory) && isset($factory[0], $factory[1])) {
                    $target = $factory[0];
                    if (is_string($target) && str_starts_with($target, '@')) {
                        $factory[0] = new Reference(substr($target, 1));
                    }
                    $definition->setFactory($factory);
                } elseif (is_string($factory) && str_contains($factory, '::')) {
                    $definition->setFactory($factory);
                }
            }

            // Decorator support: wrap existing services
            if (!empty($def['decorate'])) {
                $decorateConfig = is_array($def['decorate']) ? $def['decorate'] : ['id' => $def['decorate']];
                $definition->setDecoratedService(
                    (string)$decorateConfig['id'],
                    $decorateConfig['inner'] ?? null,
                    (int)($decorateConfig['priority'] ?? 0)
                );
            }

            // Tags: either ["tag1", "tag2"] or [["name"=>"tag","attr"=>...], ...]
            if (!empty($def['tags'])) {
                foreach ((array)$def['tags'] as $tag) {
                    if (is_string($tag)) {
                        $definition->addTag($tag);
                    } elseif (is_array($tag) && isset($tag['name'])) {
                        $attrs = $tag;
                        $name  = (string)$attrs['name'];
                        unset($attrs['name']);
                        $definition->addTag($name, $attrs);
                    }
                }
            }

            // Note: tags are only meaningful if a compiler pass consumes them.
            // Built-in passes process: event.subscriber, middleware (priority), validation.rule (rule_name), console.command.

            // Collision detection with provider blame: First definition wins
            if ($this->builder->hasDefinition((string)$id)) {
                $originalProvider = $this->serviceProviders[(string)$id] ?? 'unknown';
                $classInfo = $class ? " (class {$class})" : '';
                error_log("[Extensions] Service collision for '{$id}'{$classInfo} from {$this->currentProvider} ignored; first was {$originalProvider}");
                continue;
            }
            
            // Track which provider registered this service
            $this->serviceProviders[(string)$id] = $this->currentProvider;

            $this->builder->setDefinition((string)$id, $definition);

            // Aliases with collision detection
            if (!empty($def['alias'])) {
                foreach ((array)$def['alias'] as $alias) {
                    if ($this->builder->hasDefinition((string)$alias) || $this->builder->hasAlias((string)$alias)) {
                        $originalProvider = $this->serviceProviders[(string)$alias] ?? 'unknown';
                        $classInfo = $class ? " (class {$class})" : '';
                        error_log("[Extensions] Alias collision for '{$alias}'{$classInfo} from {$this->currentProvider} ignored; first was {$originalProvider}");
                        continue;
                    }
                    $this->serviceProviders[(string)$alias] = $this->currentProvider;
                    $this->builder->setAlias((string)$alias, (string)$id)->setPublic($definition->isPublic());
                }
            }
        }
        
        // Validate all service references to catch typos
        $this->validateReferences();
    }

    /**
     * Validate that all service references exist to catch typos at build time.
     */
    private function validateReferences(): void
    {
        $missing = [];
        
        foreach ($this->builder->getDefinitions() as $id => $definition) {
            foreach ($definition->getArguments() as $arg) {
                if ($arg instanceof Reference && !$this->builder->has((string)$arg)) {
                    $missing[] = [$id, (string)$arg];
                }
            }
            
            // Check factory references too
            $factory = $definition->getFactory();
            if (is_array($factory) && isset($factory[0]) && $factory[0] instanceof Reference) {
                $serviceId = (string)$factory[0];
                if (!$this->builder->has($serviceId)) {
                    $missing[] = [$id, $serviceId];
                }
            }
            
            // TODO: Also validate method calls set via setMethodCalls() if we expose that later
            // This would scan $definition->getMethodCalls() for Reference arguments
        }
        
        if (!empty($missing)) {
            $errors = [];
            foreach ($missing as [$serviceId, $missingRef]) {
                $errors[] = "Service '{$serviceId}' references missing service '{$missingRef}'";
            }
            throw new \InvalidArgumentException("Missing service references detected (check for typos like '@validator'):\n" . implode("\n", $errors));
        }
    }
}
```

## Service Definition Mini-DSL Reference

Complete reference for extension authors defining services in `Provider::services()`:

| Key | Type | Default | Notes |
|-----|------|---------|-------|
| `class` | `string` (FQCN) | `id` | Implementation class name |
| `arguments` | `array` (mixed or `@id`) | `[]` | Constructor arguments; `@id` becomes DI reference |
| `shared` | `bool` | `true` | `true` = singleton, `false` = new instance per request |
| `factory` | `['@id','method']` or `'FQCN::method'` | — | Factory method; no closures in compiled containers |
| `alias` | `string` or `string[]` | — | Creates container aliases for interfaces/shortcuts |
| `tags` | `string[]` or `[{name, …}]` | — | Tags for discovery (controllers, commands, etc.) |
| `decorate` | `string` or `{id, priority?, inner?}` | — | Wrap an existing service with decorator pattern |
| `public` | `bool` | `false` | `true` = accessible via container->get(), `false` = DI only |

### Examples

**Basic Service**:
```php
BlogService::class => [
    'class' => BlogService::class,
    'arguments' => ['@database', '@cache'],
    'shared' => true,
    'public' => true, // Accessible via container->get()
]
```

**Interface Binding**:
```php
// Note: Add proper use statements when implementing
BlogRepository::class => [
    'class' => EloquentBlogRepository::class,
    'arguments' => ['@database'],
    'alias' => [BlogRepositoryInterface::class, 'blog.repo'],
]
```

**Factory Pattern**:
```php
'redis.connection' => [
    'factory' => ['@connection.factory', 'createRedis'],
    'arguments' => ['%redis.config%'], // Parameter placeholders resolved at compile time
    'shared' => true,
]
```

**Parameter Resolution**: Parameter placeholders (`%param%`) are resolved by Symfony's ParameterBag at compile time.

Example parameter definition in `config/services.yaml`:
```yaml
parameters:
  redis.host: 'localhost'
  redis.port: 6379
  redis.config:
    host: '%redis.host%'
    port: '%redis.port%'
```

**Service Decorator**:
```php
CachingBlogRepository::class => [
    'class' => CachingBlogRepository::class,
    'arguments' => ['@CachingBlogRepository.inner', '@cache'], // .inner convention
    'decorate' => ['id' => BlogRepository::class, 'priority' => 10],
]
```

**Decorator Chain with Priorities**:
```php
// High priority decorator (applied last, executed first)
LoggingRepository::class => [
    'class' => LoggingRepository::class,
    'arguments' => ['@LoggingRepository.inner', '@logger'],
    'decorate' => ['id' => BlogRepository::class, 'priority' => 20],
],

// Medium priority decorator  
CachingRepository::class => [
    'class' => CachingRepository::class,
    'arguments' => ['@CachingRepository.inner', '@cache'],
    'decorate' => ['id' => BlogRepository::class, 'priority' => 10],
],

// Low priority decorator (applied first, executed last)
ValidatingRepository::class => [
    'class' => ValidatingRepository::class,
    'arguments' => ['@ValidatingRepository.inner', '@validator'],
    'decorate' => ['id' => BlogRepository::class, 'priority' => 0],
],
```

**Inner Service Naming Convention**: 
- Symfony automatically creates `<DecoratorClass>.inner` service for the wrapped service
- Use `@<YourDecoratorClass>.inner` in arguments to inject the decorated service
- **Execution Order**: Higher priority decorates later and executes earlier in the chain

**Tagged Services**:
```php
BlogController::class => [
    'class' => BlogController::class,
    'arguments' => ['@'.BlogService::class],
    // Controllers are typically registered differently in the routing layer
    'public' => true, // Controllers need to be public
]
```

### Validation Rules

- **No closures**: Use factory methods instead of closures in production
- **Reference format**: `@service_id` not `@@service_id` or bare `@`
- **Class resolution**: Service ID can be FQCN or string; class defaults to ID
- **Collision policy**: First definition wins; duplicates are logged and ignored
- **Visibility**: Private by default; set `public: true` for container->get() access

Notes:
- Use array syntax for services; avoid closures as factories in compiled containers.
- `@service_id` in `arguments` turns into a Symfony `Reference`.
- `shared` defaults to true; `alias` can be a string or array of strings.
- `tags` supports simple strings or objects with a `name` and attributes.

## Extension Structure

### Standard Composer Package Structure

```
glueful-blog/
├── composer.json           # Package definition with provider
├── src/
│   ├── BlogServiceProvider.php
│   ├── Controllers/
│   ├── Models/
│   └── Services/
├── routes/
│   └── blog.php           # Route definitions
├── database/
│   └── migrations/        # Database migrations
├── config/
│   └── blog.php          # Extension configuration
└── tests/
    └── BlogTest.php
```

### Local Development Extension

```
extensions/
└── my-extension/
    ├── composer.json      # Same structure as package
    ├── src/
    │   └── MyExtensionServiceProvider.php
    └── routes/
        └── routes.php
```

## Real-World Example: Blog Extension

### composer.json

```json
{
    "name": "glueful/blog",
    "description": "Blog functionality for Glueful",
    "type": "glueful-extension",
    "require": {
        "php": "^8.2",
        "glueful/framework": "^1.0"
    },
    "autoload": {
        "psr-4": {
            "Glueful\\Blog\\": "src/"
        }
    },
    "extra": {
        "glueful": {
            "provider": "Glueful\\Blog\\BlogServiceProvider"
        }
    }
}
```

### BlogServiceProvider.php

```php
<?php

namespace Glueful\Blog;

use Glueful\Extensions\ServiceProvider;
use Glueful\Blog\Services\BlogService;
use Glueful\Blog\Controllers\BlogController;
use Glueful\Blog\Commands\BlogInstallCommand;
use Glueful\Blog\Middleware\BlogAuthMiddleware;
use Glueful\Blog\Subscribers\BlogEventSubscriber;
use Glueful\Routing\Router;

class BlogServiceProvider extends ServiceProvider
{
    /**
     * Define services for DI container (compiled into container)
     */
    public static function services(): array
    {
        return [
            BlogService::class => [
                'class' => BlogService::class,
                'arguments' => ['@database', '@cache'],
                'shared' => true,
                // public: false (default) — controller gets it via DI
                'alias'  => ['blog.service'], // Framework alias
            ],
            BlogRepository::class => [
                'class' => BlogRepository::class,
                'arguments' => ['@database'],
                'shared' => true,
                'alias'  => [BlogRepositoryInterface::class], // Interface binding
            ],
            // Controller defined with constructor arguments (avoid closures in compiled container)
            BlogController::class => [
                'class'     => BlogController::class,
                'arguments' => ['@'.BlogService::class, '@validator'],
                'public'    => true, // Controllers must be public for framework access
                // Controllers don't need tags - they're referenced directly in routes
            ],
        ];
    }
    
    public function register(): void
    {
        // Merge default config (app config overrides)
        $this->mergeConfig('blog', require __DIR__.'/../config/blog.php');
    }
    
    public function boot(): void
    {
        // Load routes
        $this->loadRoutesFrom(__DIR__.'/../routes/blog.php');
        
        // Load migrations
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        
        // Register middleware and event subscribers
        $this->app->get('middleware.registry')->register([
            'blog.auth' => BlogAuthMiddleware::class,
        ]);
        $this->app->get('events')->addSubscriber(new BlogEventSubscriber());
        
        // Register console commands
        if ($this->runningInConsole()) {
            $this->commands([
                BlogInstallCommand::class,
            ]);
        }
        
        // Serve compiled frontend assets (if any)
        if (is_dir(__DIR__.'/../dist')) {
            $this->mountStatic('blog', __DIR__.'/../dist');
        }
        
        // Register extension metadata
        $this->app->get(\Glueful\Extensions\ExtensionManager::class)->registerMeta(self::class, [
            'slug' => 'blog',
            'name' => 'Blog Extension',
            'version' => '1.0.0',
            'description' => 'Blogging functionality for Glueful',
        ]);
    }
}
```

### routes/blog.php

```php
<?php

use Glueful\Blog\Controllers\BlogController;
use Glueful\Routing\Router;

// Using Next-Gen Router with modern patterns
$router->group(['prefix' => 'blog'], function (Router $router) {
    // Public routes
    $router->get('/', [BlogController::class, 'index'])->name('blog.index');
    $router->get('/posts/{slug}', [BlogController::class, 'show'])
           ->where('slug', '[a-z0-9\-]+')
           ->name('blog.show');
    $router->get('/category/{category}', [BlogController::class, 'category'])
           ->where('category', '[a-z0-9\-]+')
           ->name('blog.category');
    
    // Admin routes with Next-Gen Router middleware chaining
    $router->group(['middleware' => ['auth:api', 'role:admin']], function (Router $router) {
        $router->get('/admin', [BlogController::class, 'admin'])->name('blog.admin');
        $router->post('/posts', [BlogController::class, 'store'])->name('blog.store');
        $router->put('/posts/{id}', [BlogController::class, 'update'])
               ->where('id', '\d+')
               ->name('blog.update');
        $router->delete('/posts/{id}', [BlogController::class, 'destroy'])
               ->where('id', '\d+')
               ->name('blog.destroy');
    });
});
```

## Comparison with Current System

| Aspect | Current System | New System | Improvement |
|--------|---------------|------------|-------------|
| **Lines of Code** | 5,242 | ~400 | **92% reduction** |
| **Number of Files** | 17 | 3 | **82% reduction** |
| **Core Classes** | 5 services + 4 interfaces | 3 classes + 3 interfaces* | **78% reduction** |
| **Learning Curve** | Hours | Minutes (Laravel/Symfony-familiar) | **Instant if know Laravel** |

*Includes DeferrableProvider (reserved for future use)
| **Discovery Method** | Custom manifest.json | Composer packages | **Standard tooling** |
| **Configuration** | JSON files | PHP config | **Type-safe** |
| **Pattern** | Custom abstractions | ServiceProvider | **Industry standard** |
| **Dependencies** | Complex validation | Composer handles | **Automated** |
| **Local Dev** | Complex setup | Drop in extensions/ | **Zero config** |
| **Caching** | None | Production cache | **Fast boot times** |
| **Security** | Basic | Directory traversal protection | **Hardened** |
| **Error Handling** | Fail fast | Graceful degradation | **Production ready** |
| **Logging** | Custom | PSR-3 + fallback | **Standard** |
| **Provider Ordering** | None | Priority + dependencies | **Deterministic** |

## Migration Strategy

**Backwards Compatibility & Migration**: The old manifest.json and custom registry systems are deprecated. Existing extensions can be migrated using this mapping:

| Legacy Concept | New Approach |
|---------------|--------------|
| `manifest.json` | `composer.json` with `extra.glueful.provider` |
| Custom registry | Standard Composer discovery |  
| Extension type field | Provider class namespace |
| Routes path | `loadRoutesFrom()` in provider |
| Migrations path | `loadMigrationsFrom()` in provider |
| Config publishing | `mergeConfig()` in `register()` method |

Migration is straightforward: convert manifest.json to composer.json structure, create a ServiceProvider class, and move initialization logic from custom registry to standard provider methods.

## Implementation Strategy

### Phase 1: Clean Slate Implementation (Week 1)
1. **Remove old Extensions system** - Delete all 17 files (5,242 lines)
2. **Implement new system** - Add 3 core files (~400 lines)
3. **Update Framework integration** - Replace old initialization
4. **Create documentation** - Usage examples and extension creation guide

### Phase 2: Extension Creation (Week 2)
1. **Create sample extensions** - Blog, Shop examples
2. **Test integration** - Ensure all functionality works
3. **Performance testing** - Verify improvement claims
4. **CLI commands** - Implement extension management tools

## Benefits

### For Developers
- **Laravel/Symfony-familiar** - Industry standard ServiceProvider pattern
- **IDE support** - Full autocomplete and refactoring
- **Standard tooling** - Composer, PSR-4, etc.
- **Clear structure** - One provider, standard directories
- **Easy debugging** - Simple call stack, no abstractions
- **Consistent behavior** - Works same way in dev and production

### For Performance
- **Minimal overhead** - ~400 LOC vs 5,000+
- **No validation layers** - Composer handles dependencies
- **Compiled services** - Extension services built into container
- **Lazy loading** - Providers registered but boot deferred
- **Cached discovery** - Composer's optimized autoloader
- **Production ready** - Services compiled for maximum performance

### For Maintenance
- **92% less code** - Less to maintain and debug
- **Standard patterns** - New developers understand immediately
- **Composer updates** - Leverage existing ecosystem
- **No custom abstractions** - Use framework/PHP features directly
- **Container integration** - Full DI container features for extension services

### Architecture Advantages
- **Two-phase loading** - Services during compilation, setup during boot
- **Container compilation** - Extension services get full Symfony DI benefits
- **Development parity** - Same behavior in dev and production environments
- **Proper dependency injection** - Extension services support circular dependency detection, lazy loading, etc.

## Failure Modes & Recovery

The Extensions system handles common failure scenarios gracefully:

**Missing Provider Class**:
```
[Extensions] Extension provider not found ['provider' => 'Missing\\Class']
```
*Recovery*: Extension is skipped, system continues normally.

**Invalid JSON in Local Extensions**:
```
[Extensions] Invalid composer.json in /path/to/extension/composer.json: Syntax error
```
*Recovery*: Extension is skipped, local scan continues with other extensions.

**Provider Exception During Boot**:
```
[Extensions] Provider failed during boot() ['provider' => 'Vendor\\Extension\\Provider', 'error' => 'Service not found']
```
*Recovery*: Provider registration completed, only boot() failed. System continues with other providers.

## CLI Commands

Production-ready commands for extension management:

```bash
# Discovery and information
php glueful extensions:list              # List all discovered extensions with status (shows disabled)
php glueful extensions:info blog         # Show detailed extension information  
php glueful extensions:why BlogProvider  # Explain why/how a provider was included or excluded
php glueful extensions:summary           # Show startup summary (total_providers, booted, cache_used)
```

**Example CLI Output:**

```bash
$ php glueful extensions:list
✓ App\Extensions\BlogProvider (enabled, composer)
✓ App\Extensions\ShopProvider (enabled, local scan)
✗ App\Extensions\TestProvider (disabled in config)
✓ VendorPackage\CmsProvider (enabled, composer)

4 providers discovered, 3 active, 1 disabled

$ php glueful extensions:why BlogProvider
✓ Found: App\Extensions\BlogProvider
✓ Source: composer scan (vendor/myapp/blog-extension)
✓ Status: included in final provider list
✓ Load order: priority 100, no dependencies
✓ Boot phase: registered 3 services, mounted /blog static assets

$ php glueful extensions:why TestProvider  
✓ Found: App\Extensions\TestProvider
✓ Source: local scan (/app/extensions/test)
✗ Status: EXCLUDED - listed in extensions.disabled config
✗ Reason: disabled via config('extensions.disabled')

$ php glueful extensions:summary
Extensions: 3 loaded, 1 disabled, 2 deferred
Cache: enabled (built 2 hours ago)
Boot time: 45ms (12ms discovery, 33ms registration)
```

```bash
# Cache management (production optimization)
php glueful extensions:cache             # Build extensions cache for production
php glueful extensions:clear             # Clear extensions cache

# Development tools
php glueful create:extension my-extension # Create new local extension
php glueful extensions:enable blog        # Enable extension (development only)
php glueful extensions:disable blog       # Disable extension (development only)
```

### Cache Management

The Extensions system includes intelligent caching for production environments:

- **Development**: Cache expires after 5 seconds, allows rapid iteration
- **Production**: Cache persists until explicitly cleared, maximum performance
- **Cache Location**: `bootstrap/cache/extensions.php`
- **Production (deterministic)**: Rebuild via php glueful extensions:cache; clear with ...:clear. No auto-rebuild

### Developer Experience

Add a convenience script to keep the build order correct during local development:

```bash
# bin/dev (make executable)
#!/usr/bin/env bash
set -euo pipefail

php glueful extensions:clear || true
php glueful extensions:cache
php glueful di:container:compile
echo "Dev build complete."
```

This ensures extensions are cleared, re-cached, then the container is compiled, preserving the required order.

## Production Build Integration

The Extensions system integrates with production build workflows for optimal performance:

### Optimized Build Pipeline

For production deployments, follow this build sequence for maximum performance:

```bash
# 1. Optimize Composer autoloader
composer dump-autoload -o --no-dev

# 2. Build extension discovery cache  
php glueful extensions:cache

# 3. Compile DI container with extension services
php glueful di:container:compile

# 4. Clear application cache
php glueful cache:clear
```

### Automated Cache Freshness

Keep extension cache synchronized with Composer updates using a post-autoload script:

```json
// composer.json
{
  "scripts": {
    "post-autoload-dump": [
      "@php glueful extensions:cache"
    ]
  }
}
```

This ensures extension discovery cache stays fresh after `composer install`, `composer update`, or `composer dump-autoload` commands without requiring a separate Composer plugin.

### Container Compilation Order

The two-phase extension loading ensures proper compilation:

1. **Service Discovery Phase**: Extension services are discovered and compiled into the DI container
2. **Provider Boot Phase**: Extensions boot for routes, migrations, and runtime setup

**Critical**: Extension services must be loaded **before** container compilation to ensure all dependencies are resolved.

### Production Cache Strategy

- **Extensions Cache**: Contains discovered provider class names
- **Container Cache**: Contains compiled service definitions from extensions
- **Route Cache**: Contains compiled routes from extension providers
- **Configuration Cache**: Contains merged configuration from extensions

**Cache Invalidation**: Rebuild `extensions.php` cache after any `composer install/update` in CI (already covered by `php glueful extensions:cache`).

**Tip**: Run `php glueful extensions:clear` before `extensions:cache` if you suspect a stale cache in CI.

## Development Guardrails & Security

The Extensions system includes multiple security layers for safe development:

### Local Extension Scanning Safety

**Critical Security Requirements**:
- **Directory Protection**: The `extensions/` folder should be gitignored and NOT writable by web server user in production
- **Production Disable**: Always set `local_path: null` in production environments
- **Symlink Prevention**: Local scanning skips symbolic links to prevent traversal attacks

**File Size Limits**: Prevents DoS attacks during development scanning
```php
// Validate composer.json file size (100KB max)
if (filesize($file) > 1024 * 100) { 
    continue; // Skip oversized files
}
```

**Project Count Limits**: Prevents pathological development folders
```php
$maxProjects = 200;
if (count($files) > $maxProjects) {
    $this->log("Too many local extensions found, limiting to {$maxProjects}");
    $files = array_slice($files, 0, $maxProjects);
}
```

**Path Traversal Protection**: Secure directory traversal
```php
// Skip symlinks to reduce traversal surprises
if (is_link($file)) {
    continue;
}

// Only scan immediate subdirectories; glob() excludes dot-dirs by default
$pattern = $extensionsPath . '/*/composer.json';
```

**JSON Validation**: Safe JSON parsing with error handling
```php
try {
    $json = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
} catch (\JsonException $e) {
    error_log("Invalid composer.json in {$file}: " . $e->getMessage());
    continue; // Skip invalid files
}
```

### Static Asset Security

**Directory Restriction**: `mountStatic()` enforces strict path boundaries
```php
$requested = realpath($realDir . DIRECTORY_SEPARATOR . $path);
if (!str_starts_with($requested, $realDir . DIRECTORY_SEPARATOR)) {
    return response()->notFound(); // Path traversal blocked
}
```

**Windows Compatibility**: Path operations use `DIRECTORY_SEPARATOR` and `realpath()` for cross-platform safety, ensuring proper security boundaries on Windows filesystems.

**Route Namespace Safety**: Extensions should register routes under a dedicated prefix to prevent collisions with application routes:

```php
// Good: Prefixed routes prevent collisions
public function boot(): void
{
    $this->routes('blog', __DIR__ . '/routes.php');
}

// routes.php
Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
Route::get('/blog/{slug}', [BlogController::class, 'show'])->name('blog.show');
```

This ensures extension routes live under `/blog/*` namespace, avoiding conflicts with application routes like `/admin` or `/api`.

**Metadata Registry Safety**: De-duplication prevents side-effects
```php
// Extensions are de-duplicated by provider FQCN
// Duplicate registrations are ignored, not merged
if (isset($this->providers[$providerClass])) {
    $this->log("Provider already registered, skipping", ['provider' => $providerClass], 'debug');
    return;
}
```

### Service ID Style Guide

Use stable string IDs for interfaces and class names for concrete implementations:

**Recommended Pattern**:
```php
// Good: Stable string IDs for interfaces
'rbac.authz' => [
    'class' => AuthorizationService::class,
    'alias' => [AuthorizationService::class, AuthorizationServiceInterface::class],
]

// Good: Class names for concretes
BlogService::class => [
    'class' => BlogService::class,
    'alias' => ['blog.service'], // Interface alias
]
```

**Avoid**:
```php
// Bad: Redundant aliases
BlogService::class => [
    'class' => BlogService::class,
    'alias' => [BlogService::class], // Redundant - service ID already matches
]
```

**Note**: Alias to the concrete class is useful when the service ID is not the class FQCN (e.g., 'rbac.authz' aliased to AuthorizationService::class).

### Logging Level Standards

The Extensions system uses consistent PSR-3 logging levels for different event types:

- **`debug`**: Duplicate providers, unknown `bootAfter()` dependencies, discovery details
- **`notice`**: Missing provider classes, configuration warnings
- **`warning`**: Exceptions during provider registration or boot, performance issues
- **`error`**: Critical failures that prevent extension loading

This standardization helps with log filtering and monitoring in production environments.

### Service Reference Normalization

The compiler supports both `@ClassName` and `@service.id` patterns:
```php
// Both work identically
'arguments' => ['@' . BlogService::class]
'arguments' => ['@blog.service']
```

### Versioning Matrix

Example of extension version compatibility using the `minVersion` field:

| Extension | Current Version | Min Glueful | Status |
|-----------|----------------|-------------|---------|
| glueful/blog | 2.1.0 | 1.8.0 | ✅ Compatible |
| glueful/shop | 3.0.0 | 2.0.0 | ⚠️ Requires upgrade |
| vendor/analytics | 1.5.2 | 1.5.0 | ✅ Compatible |

Extensions declare compatibility in `composer.json`:
```json
{
    "extra": {
        "glueful": {
            "provider": "Vendor\\Analytics\\AnalyticsProvider",
            "minVersion": "1.5.0"
        }
    }
}
```

### Example CLI Implementation

Complete implementation of the `extensions:list` command:

```php
<?php
// src/Console/ExtensionsListCommand.php
declare(strict_types=1);

namespace Glueful\Console;

use Glueful\Extensions\ExtensionManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ExtensionsListCommand extends BaseCommand
{
    protected static $defaultName = 'extensions:list';

    public function __construct(private ExtensionManager $extensions)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('List discovered extensions with status');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $enabled = $this->extensions->getProviders();
        $meta = $this->extensions->listMeta();
        $disabled = $this->getDisabledProviders();

        $rows = [];
        
        // Add enabled providers
        foreach ($enabled as $class => $provider) {
            $m = $meta[$class] ?? [];
            $rows[] = [
                $m['slug'] ?? basename(str_replace('\\', '/', $class)),
                $m['name'] ?? $class,
                $m['version'] ?? 'n/a',
                'enabled',
                $class,
            ];
        }
        
        // Add disabled providers (struck through)
        foreach ($disabled as $class) {
            $slug = basename(str_replace('\\', '/', $class));
            $rows[] = [
                $slug,
                $class,
                'n/a',
                'disabled',
                $class,
            ];
        }

        if (empty($rows)) {
            $output->writeln('<comment>No extensions discovered.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf("%-20s %-30s %-10s %-10s %s", 'Slug', 'Name', 'Version', 'Status', 'Provider'));
        $output->writeln(str_repeat('-', 80));
        
        foreach ($rows as $r) {
            if ($r[3] === 'disabled') {
                // Strike-through for disabled providers
                $output->writeln(sprintf("%-20s %-30s %-10s <fg=red>%-10s</> <comment>%s</comment>", 
                    $r[0], $r[1], $r[2], $r[3], $r[4]));
            } else {
                $output->writeln(sprintf("%-20s %-30s %-10s <info>%-10s</> %s", 
                    $r[0], $r[1], $r[2], $r[3], $r[4]));
            }
        }

        return Command::SUCCESS;
    }
    
    private function getDisabledProviders(): array
    {
        $disabled = [];
        $disabledConfig = (array) config('extensions.disabled', []);
        
        // Get all possible providers from discovery sources
        $allDiscovered = [];
        
        // Check enabled config
        $allDiscovered = array_merge($allDiscovered, (array) config('extensions.enabled', []));
        
        // Check dev_only config
        if (($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? 'production') !== 'production') {
            $allDiscovered = array_merge($allDiscovered, (array) config('extensions.dev_only', []));
            
            // Check local scan
            if ($localPath = config('extensions.local_path')) {
                try {
                    $reflection = new \ReflectionClass(ProviderLocator::class);
                    $method = $reflection->getMethod('scanLocalExtensions');
                    $method->setAccessible(true);
                    $local = $method->invokeArgs(null, [$localPath]);
                    $allDiscovered = array_merge($allDiscovered, $local);
                } catch (\Throwable $e) {
                    // Ignore scan errors in list command
                }
            }
        }
        
        // Check Composer packages
        if (config('extensions.scan_composer', true)) {
            try {
                $manifest = new \Glueful\Extensions\PackageManifest();
                $composer = $manifest->getGluefulProviders();
                $allDiscovered = array_merge($allDiscovered, array_values($composer));
            } catch (\Throwable $e) {
                // Ignore composer scan errors
            }
        }
        
        // Find providers that were discovered but are disabled
        foreach (array_unique($allDiscovered) as $provider) {
            if (in_array($provider, $disabledConfig)) {
                $disabled[] = $provider;
            }
        }
        
        return $disabled;
    }
}
```

Complete implementation of the `extensions:info` command:

```php
<?php
// src/Console/ExtensionsInfoCommand.php
declare(strict_types=1);

namespace Glueful\Console;

use Glueful\Extensions\ExtensionManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ExtensionsInfoCommand extends BaseCommand
{
    protected static $defaultName = 'extensions:info';

    public function __construct(private ExtensionManager $extensions)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Show detailed extension information')
            ->addArgument('slugOrClass', InputArgument::REQUIRED, 'Extension slug or provider FQCN');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $needle = (string) $input->getArgument('slugOrClass');
        $metaAll = $this->extensions->listMeta();
        $providers = $this->extensions->getProviders();

        // resolve by slug or FQCN
        $class = null;
        foreach (array_keys($providers) as $providerClass) {
            $m = $metaAll[$providerClass] ?? [];
            if ($providerClass === $needle || ($m['slug'] ?? null) === $needle) {
                $class = $providerClass; break;
            }
        }

        if (!$class) {
            $output->writeln("<error>Extension not found: {$needle}</error>");
            return Command::FAILURE;
        }

        $m = $metaAll[$class] ?? [];
        $output->writeln("Provider:       {$class}");
        $output->writeln('Name:           ' . ($m['name'] ?? 'n/a'));
        $output->writeln('Slug:           ' . ($m['slug'] ?? 'n/a'));
        $output->writeln('Version:        ' . ($m['version'] ?? 'n/a'));
        $output->writeln('Description:    ' . ($m['description'] ?? ''));
        // add anything else (routes, migrations path) if you store it in meta

        return Command::SUCCESS;
    }
}
```

Complete implementation of the `extensions:summary` command:

```php
<?php
// src/Console/ExtensionsSummaryCommand.php
declare(strict_types=1);

namespace Glueful\Console;

use Glueful\Extensions\ExtensionManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ExtensionsSummaryCommand extends BaseCommand
{
    protected static $defaultName = 'extensions:summary';

    public function __construct(private ExtensionManager $extensions)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Show startup summary and diagnostics');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $s = $this->extensions->getSummary();
        $output->writeln('Extensions summary');
        $output->writeln('------------------');
        $output->writeln('total_providers: ' . $s['total_providers']);
        $output->writeln('booted:          ' . ($s['booted'] ? 'yes' : 'no'));
        $output->writeln('cache_used:      ' . ($s['cache_used'] ? 'yes' : 'no'));
        return Command::SUCCESS;
    }
}
```

Complete implementation of the `extensions:cache` command:

```php
<?php
// src/Console/ExtensionsCacheCommand.php
declare(strict_types=1);

namespace Glueful\Console;

use Glueful\Extensions\ExtensionManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ExtensionsCacheCommand extends BaseCommand
{
    protected static $defaultName = 'extensions:cache';

    public function __construct(private ExtensionManager $extensions)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Build extensions cache (writes cache regardless of APP_ENV)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Force fresh discovery and write cache regardless of environment
        $this->extensions->discover();
        $this->extensions->writeCacheNow(); // Works in all environments
        $output->writeln('<info>Extensions cache generated.</info>');
        return Command::SUCCESS;
    }
}
```

Complete implementation of the `extensions:clear` command:

```php
<?php
// src/Console/ExtensionsClearCommand.php
declare(strict_types=1);

namespace Glueful\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ExtensionsClearCommand extends BaseCommand
{
    protected static $defaultName = 'extensions:clear';

    protected function configure(): void
    {
        $this->setDescription('Clear extensions cache');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cache = base_path('bootstrap/cache/extensions.php');
        if (is_file($cache)) {
            @unlink($cache);
            $output->writeln('<info>Extensions cache cleared.</info>');
        } else {
            $output->writeln('<comment>No cache file found.</comment>');
        }
        return Command::SUCCESS;
    }
}
```

Complete implementation of the `extensions:why` command for debugging provider inclusion/exclusion:

```php
<?php

declare(strict_types=1);

namespace Glueful\Console\Commands\Extensions;

use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\ProviderLocator;
use Glueful\Extensions\PackageManifest;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ExtensionsWhyCommand extends BaseCommand
{
    protected static $defaultName = 'extensions:why';

    public function __construct(private ExtensionManager $extensions)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Explain why a provider is included or excluded')
             ->addArgument('provider', InputArgument::REQUIRED, 'Provider class name to analyze');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $provider = $input->getArgument('provider');
        $output->writeln("<info>Analyzing provider:</info> {$provider}");
        $output->writeln('');

        // Check exclusive allow-list mode first
        if ($only = config('extensions.only')) {
            if (in_array($provider, (array) $only)) {
                $output->writeln("✅ <info>INCLUDED</info> via 'extensions.only' exclusive allow-list");
                $output->writeln("   Position: " . (array_search($provider, (array) $only) + 1) . " of " . count((array) $only));
            } else {
                $output->writeln("❌ <error>EXCLUDED</error> - not in 'extensions.only' allow-list");
                $output->writeln("   Only these providers are allowed:");
                foreach ((array) $only as $i => $allowed) {
                    $output->writeln("   " . ($i + 1) . ". {$allowed}");
                }
            }
            $output->writeln('');
            return Command::SUCCESS;
        }

        // Check blacklist
        $disabled = (array) config('extensions.disabled', []);
        if (in_array($provider, $disabled)) {
            $output->writeln("❌ <error>EXCLUDED</error> - blacklisted in 'extensions.disabled'");
            $this->showDiscoveryTrace($provider, $output);
            return Command::SUCCESS;
        }

        // Check discovery sources
        $found = false;
        $sources = [];

        // 1. Check enabled config
        if (in_array($provider, (array) config('extensions.enabled', []))) {
            $sources[] = 'extensions.enabled (config)';
            $found = true;
        }

        // 2. Check dev_only config
        if (($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? 'production') !== 'production') {
            if (in_array($provider, (array) config('extensions.dev_only', []))) {
                $sources[] = 'extensions.dev_only (config)';
                $found = true;
            }

            // 3. Check local scan
            if ($localPath = config('extensions.local_path')) {
                $local = ProviderLocator::scanLocalExtensions($localPath);
                if (in_array($provider, $local)) {
                    $sources[] = "local scan ({$localPath})";
                    $found = true;
                }
            }
        }

        // 4. Check Composer packages
        if (config('extensions.scan_composer', true)) {
            $manifest = new PackageManifest();
            $composer = $manifest->getGluefulProviders();
            if (in_array($provider, array_values($composer))) {
                $packageName = array_search($provider, $composer);
                $sources[] = "Composer package ({$packageName})";
                $found = true;
            }
        }

        if ($found) {
            $output->writeln("✅ <info>INCLUDED</info> via: " . implode(', ', $sources));
            
            // Show sort order information
            $this->showSortOrder($provider, $output);
        } else {
            $output->writeln("❌ <error>NOT FOUND</error> in any discovery source");
            $output->writeln('');
            $output->writeln('<comment>Discovery sources checked:</comment>');
            $output->writeln('• extensions.enabled: ' . count((array) config('extensions.enabled', [])) . ' providers');
            if (($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? 'production') !== 'production') {
                $output->writeln('• extensions.dev_only: ' . count((array) config('extensions.dev_only', [])) . ' providers');
                $localPath = config('extensions.local_path');
                $output->writeln('• local scan: ' . ($localPath ? "enabled ({$localPath})" : 'disabled'));
            }
            $output->writeln('• Composer scan: ' . (config('extensions.scan_composer', true) ? 'enabled' : 'disabled'));
        }

        $output->writeln('');
        return Command::SUCCESS;
    }

    private function showDiscoveryTrace(string $provider, OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln('<comment>Would have been discovered via:</comment>');
        
        // Show where it would have been found
        if (in_array($provider, (array) config('extensions.enabled', []))) {
            $output->writeln('• extensions.enabled (config)');
        }
        
        if (($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?? 'production') !== 'production') {
            if (in_array($provider, (array) config('extensions.dev_only', []))) {
                $output->writeln('• extensions.dev_only (config)');
            }
        }
        // Add other discovery sources...
    }

    private function showSortOrder(string $provider, OutputInterface $output): void
    {
        try {
            if (class_exists($provider)) {
                $instance = new $provider(app());
                
                $output->writeln('');
                $output->writeln('<comment>Sort order details:</comment>');
                
                // Priority
                $priority = method_exists($instance, 'priority') ? $instance->priority() : 0;
                $output->writeln("• Priority: {$priority} (lower = earlier)");
                
                // Dependencies
                if (method_exists($instance, 'bootAfter')) {
                    $deps = $instance->bootAfter();
                    if (!empty($deps)) {
                        $output->writeln("• Boots after: " . implode(', ', $deps));
                    }
                }
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>Could not instantiate provider for sort analysis</error>');
        }
    }
}
```

### Testing Matrix

Comprehensive testing approach for the Extensions system:

| Test Type | Scope | Example |
|-----------|-------|---------|
| **Unit** | Service definitions | Test BlogServiceProvider::services() contains correct definitions |
| **Integration** | Routes load | Test blog routes are accessible after provider boot |
| **Smoke** | CLI non-zero exit | Test `php glueful extensions:summary` returns 0 exit code |
| **Cache** | Production optimization | Test cache file generation and loading |
| **Discovery** | Composer formats | Test both installed.php and installed.json discovery |
| **Security** | Path traversal | Test `mountStatic()` denies access outside resolved directory |

**Sample Tests**:
```php
// Unit test: Verify service definitions
public function test_blog_provider_service_definitions(): void
{
    $services = BlogServiceProvider::services();
    
    $this->assertArrayHasKey(BlogService::class, $services);
    $this->assertEquals(BlogService::class, $services[BlogService::class]['class']);
}

// Integration test: Verify compiled container and route resolution
public function test_blog_extension_integration(): void
{
    // Build container with extension services compiled
    $container = $this->buildContainerWithExtensions();
    
    // Verify controller is resolvable (services compiled at build time)
    $this->assertTrue($container->has(BlogController::class));
    $controller = $container->get(BlogController::class);
    $this->assertInstanceOf(BlogController::class, $controller);
    
    // Verify routes exist after extension boot
    $router = $container->get(Router::class);
    $this->assertTrue($router->hasRoute('blog.index'));
}
```

### Extension Ordering & Graph Tests

Critical tests for deterministic extension loading:

**1. Stable Priority with FIFO Ordering**
```php
public function test_extension_ordering_stable_priority_with_fifo(): void
{
    // Setup providers with same priority (0) in discovery order
    $providers = [
        'First\\Provider' => new class extends ServiceProvider { public function priority(): int { return 0; } },
        'Second\\Provider' => new class extends ServiceProvider { public function priority(): int { return 0; } },
        'Third\\Provider' => new class extends ServiceProvider { public function priority(): int { return 0; } },
    ];
    
    $manager = new ExtensionManager($this->container);
    $reflection = new \ReflectionClass($manager);
    $property = $reflection->getProperty('providers');
    $property->setAccessible(true);
    $property->setValue($manager, $providers);
    
    // Call sortProviders
    $method = $reflection->getMethod('sortProviders');
    $method->setAccessible(true);
    $method->invoke($manager);
    
    // Verify FIFO order is preserved for equal priorities
    $sorted = array_keys($property->getValue($manager));
    $this->assertEquals(['First\\Provider', 'Second\\Provider', 'Third\\Provider'], $sorted);
}
```

**2. Circular Dependency Fallback**
```php
public function test_circular_dependency_falls_back_to_priority_order(): void
{
    // Create providers with circular bootAfter() and different priorities
    $providerA = new class extends ServiceProvider implements OrderedProvider {
        public function priority(): int { return 1; }
        public function bootAfter(): array { return ['B\\Provider']; }
    };
    
    $providerB = new class extends ServiceProvider implements OrderedProvider {
        public function priority(): int { return 0; }  // Higher priority (sorts first)
        public function bootAfter(): array { return ['A\\Provider']; }
    };
    
    $providers = [
        'A\\Provider' => $providerA,
        'B\\Provider' => $providerB,
    ];
    
    $manager = new ExtensionManager($this->container);
    $reflection = new \ReflectionClass($manager);
    $property = $reflection->getProperty('providers');
    $property->setAccessible(true);
    $property->setValue($manager, $providers);
    
    // Mock logger to capture warning
    $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
    $logger->expects($this->once())
           ->method('warning')
           ->with($this->stringContains('Circular dependency detected'));
    
    $this->container->method('get')
                   ->with(\Psr\Log\LoggerInterface::class)
                   ->willReturn($logger);
    
    // Call sortProviders
    $method = $reflection->getMethod('sortProviders');
    $method->setAccessible(true);
    $method->invoke($manager);
    
    // Verify priority order fallback (B=0, A=1)
    $sorted = array_keys($property->getValue($manager));
    $this->assertEquals(['B\\Provider', 'A\\Provider'], $sorted);
}
```

**3. Discovery Order Determinism**
```php
public function test_discovery_order_determinism(): void
{
    // Test that enabled → dev_only → local → composer order is stable
    config(['extensions.enabled' => ['Enabled\\Provider']]);
    config(['extensions.dev_only' => ['DevOnly\\Provider']]);
    config(['extensions.disabled' => []]);
    
    // Mock local and composer discovery
    $locator = $this->getMockBuilder(ProviderLocator::class)
                    ->onlyMethods(['scanLocalExtensions'])
                    ->getMock();
    $locator->method('scanLocalExtensions')
            ->willReturn(['Local\\Provider']);
    
    // Mock PackageManifest
    $manifest = $this->createMock(PackageManifest::class);
    $manifest->method('getGluefulProviders')
             ->willReturn(['Composer\\Provider']);
    
    $providers = ProviderLocator::all();
    
    // Verify strict discovery order
    $this->assertEquals([
        'Enabled\\Provider',
        'DevOnly\\Provider', 
        'Local\\Provider',
        'Composer\\Provider'
    ], $providers);
}
```

### Copy-Paste Unit Tests (high-signal)

1) mountStatic() security
```php
public function test_mount_static_denies_traversal_and_php(): void
{
    // Assume provider registered mountStatic('demo', __DIR__.'/fixtures')
    $req = Request::create('/extensions/demo/../secrets.txt', 'GET');
    $resp = $kernel->handle($req);
    $this->assertSame(404, $resp->getStatusCode());

    $req = Request::create('/extensions/demo/index.php', 'GET');
    $resp = $kernel->handle($req);
    $this->assertSame(404, $resp->getStatusCode());
}
```

2) SPA root + ETag
```php
public function test_mount_static_root_serves_index_and_honors_etag(): void
{
    $req = Request::create('/extensions/demo', 'GET');
    $resp = $kernel->handle($req);
    $this->assertSame(200, $resp->getStatusCode());
    $etag = $resp->headers->get('ETag');
    $this->assertNotEmpty($etag);

    // Repeat with If-None-Match
    $req2 = Request::create('/extensions/demo', 'GET');
    $req2->headers->set('If-None-Match', $etag);
    $resp2 = $kernel->handle($req2);
    $this->assertSame(304, $resp2->getStatusCode());
}
```

## Production Smoke Test Checklist

Comprehensive checklist to confirm production parity and proper extension system operation:

### Pre-Deployment Validation

**Container Build Verification**:
```bash
# ✅ Build: Container compilation succeeds with 0 errors
php glueful di:container:compile
# Verify: No missing references, no closure factories, no service collisions

# ✅ Boot: Extensions load and boot successfully  
php glueful extensions:summary
# Expected output: cache_used: yes, booted: yes, total_providers: X
```

**Service Resolution Testing**:
```bash
# ✅ Routes: Controller bound via services() resolves and executes
curl -H "Accept: application/json" https://your-domain.com/blog/
# Verify: BlogController instantiated with proper dependencies

# ✅ Migrations: Extensions can discover and run migrations
php glueful migrate:status
# Verify: Extension migrations are listed and detected
```

### Runtime Verification

**Extension Discovery**:
```bash
# ✅ Provider Discovery: All expected extensions are loaded
php glueful extensions:list
# Verify: All production extensions are listed with correct metadata

# ✅ Service Availability: Extension services are resolvable 
php glueful di:container:debug BlogService
# Verify: Service definition shows correct class and dependencies
```

**Asset Delivery** (if using `mountStatic()`):
```bash
# ✅ Static Assets: Extension assets serve with proper caching
curl -I https://your-domain.com/extensions/blog/index.html
# Verify: 200 OK, proper ETag/Cache-Control headers, 304 on repeat
```

### Production Environment Checks

**Cache Performance**:
```bash
# ✅ Extension Cache: Production cache is used and valid
ls -la bootstrap/cache/extensions.php
# Verify: Cache file exists and is recent

# ✅ Cold Boot Performance: Extensions load quickly from cache
time php glueful extensions:summary
# Verify: Sub-second response time
```

**Security Validation**:
```bash
# ✅ Local Scan Disabled: Production disables local extension scanning
php glueful config:list extensions.local_path
# Expected: null (local_path disabled in production)

# ✅ Runtime Mutations Blocked: Container is frozen in production
# Expected: LogicException if extension tries to mutate services at runtime
```

### Integration Testing

**Database Operations**:
```bash
# ✅ Extension Migrations: Can discover and run extension migrations
php glueful migrate:run --dry-run
# Verify: Extension migrations are detected and executable

# ✅ Migration Rollback: Extension migrations support rollback
php glueful migrate:rollback --dry-run
# Verify: Extension migrations can be rolled back safely
```

**Route Registration**:
```bash
# ✅ Route Discovery: Extension routes are properly registered
php glueful route | grep blog
# Verify: All blog routes are listed with correct middleware/controllers
```

### Error Handling Validation

**Graceful Degradation**:
```bash
# ✅ Missing Provider: System continues if extension provider is missing
# Temporarily rename a provider class and verify system boots normally

# ✅ Provider Exception: System continues if extension boot() fails
# Inject an exception in provider boot() method and verify logging
```

**Development vs Production Parity**:
```bash
# ✅ Service Definitions Match: Same services available in dev and production
diff <(php glueful di:container:debug --env=development) \
     <(php glueful di:container:debug --env=production)
# Verify: Extension services are identical across environments
```

### Performance Benchmarks

**Boot Time Validation**:
```bash
# ✅ Cold Boot: Application boots in reasonable time
time php glueful extensions:summary
# Target: <500ms for typical extension count

# ✅ Warm Boot: Cached extensions provide fast subsequent boots  
time php glueful extensions:summary  # Run multiple times
# Target: <100ms for cached extension discovery
```

**Memory Usage**:
```bash
# ✅ Memory Efficiency: Extension loading doesn't leak memory
php -d memory_limit=64M glueful extensions:boot
# Verify: Extensions boot within memory constraints
```

### Final Production Checklist

Before going live, verify all items pass:

- [ ] **Container compilation** succeeds with 0 missing references
- [ ] **Extensions cache** is built and being used (`cache_used: yes`)
- [ ] **All providers boot** successfully without exceptions
- [ ] **Routes resolve** and controllers instantiate properly
- [ ] **Static assets serve** with proper caching headers (if applicable)
- [ ] **Migrations discover** extension database changes
- [ ] **Local path disabled** in production (`local_path: null`)
- [ ] **Performance acceptable** (<500ms cold boot, <100ms warm)
- [ ] **Error logging** captures extension failures gracefully
- [ ] **Service references valid** (no typos like `@validator`)
- [ ] **No closure factories** in production container dump

## Why Not a Composer Plugin?

**Question**: Why read `installed.php`/`installed.json` instead of using a Composer plugin?

**Answer**: Our installed.php/json approach avoids plugin complexity, works in all deployments, and maintains zero runtime dependencies. Key advantages:

- **Deployment compatibility**: Works with any CI/CD system without requiring plugin installation
- **Zero overhead**: No additional Composer plugins to manage or secure
- **Universal support**: Works on shared hosting, containers, and restricted environments
- **Simplicity**: Direct file reading is more predictable than plugin hooks

Composer plugins would add unnecessary complexity for this use case, while our approach leverages existing Composer metadata that's always available.

## PSR-11 Container Compliance

The Extensions system uses PSR-11 `ContainerInterface` throughout, ensuring:

- **Framework portability**: Extensions can work across different PSR-11 compliant frameworks
- **Standard testing**: Easy mocking with standard container interfaces
- **Ecosystem compatibility**: Works with any PSR-11 container implementation

Since Glueful's Container already implements PSR-11, extensions remain fully compatible with the framework's rich container features while maintaining standards compliance.

## Container Freeze Protection

Extensions that attempt to mutate services after container compilation will throw exceptions in production. This is the intended behavior - extensions must register services during the compile phase through the `services()` method, not during runtime `boot()`.

**Expected behavior**: Container mutation exceptions indicate a misconfigured extension that needs to move service registration from `boot()` to `services()`.

## Conclusion

This production-hardened Extensions system provides:

- **92% code reduction** (5,242 → ~400 lines)
- **Zero learning curve** for Laravel/Symfony developers  
- **Standard tooling** (Composer, PSR-4, PSR-3 logging)
- **Production-ready** with caching, error handling, and security
- **Maximum flexibility** with deterministic provider ordering
- **Enterprise features** without enterprise complexity

The new system follows the principle: **"Make it as simple as possible, but not simpler."** It provides all essential functionality while eliminating unnecessary abstraction layers, resulting in a maintainable, performant, and developer-friendly extension system.

## Implementation Status

**This document describes the PROPOSED architecture with two-phase service registration.**

### Current Reality (as of now):
- Extensions cannot register services in production (container compilation issue)
- `$this->app->singleton()` method doesn't exist
- Service registration only works in development environment

### Proposed Solution:
- **Static `services()` method** for DI container compilation
- **Runtime `register()` and `boot()` methods** for setup
- **Consistent behavior** in development and production

## Next Steps

### Phase 1: Core Implementation
1. **Update ServiceProvider base class** - Add static `services()` method
2. **Modify ExtensionServiceProvider** - Add service discovery during DI compilation  
3. **Update PackageManifest** - Include service discovery
4. **Test framework integration** - Ensure services get compiled properly

### Phase 2: Migration Support
1. **Backward compatibility** - Keep existing `register()` method working
2. **Deprecation warnings** - Warn about runtime service registration
3. **Migration guide** - Help developers move to new pattern
4. **Update documentation** - Reflect new architecture

### Phase 3: Rollout
1. **Update existing extensions** - Move services to static method
2. **Create sample extensions** - Demonstrate new pattern
3. **Performance testing** - Verify compiled service benefits
4. **Community feedback** - Gather developer input

**Estimated implementation time: 2-3 days** (core implementation + testing)

## Migration Path

**For existing extensions using runtime service registration:**
```php
// OLD (fails in production):
public function register(): void 
{
    $this->app->set(MyService::class, new MyService());
}

// NEW (works everywhere):
public static function services(): array 
{
    return [
        MyService::class => [
            'class' => MyService::class,
            'shared' => true
        ]
    ];
}
```

### RBAC Extension Example

**Your current RBAC extension would become:**
```php
class RBACServiceProvider extends ServiceProvider 
{
    public static function services(): array 
    {
        return [
            'rbac.repository.role' => [
                'class' => RoleRepository::class,
                'arguments' => ['@database'],
                'shared' => true,
                'alias'  => [RoleRepositoryInterface::class], // Interface binding only
            ],
            'rbac.repository.permission' => [
                'class' => PermissionRepository::class, 
                'arguments' => ['@database'],
                'shared' => true,
                'alias'  => [PermissionRepositoryInterface::class], // Interface binding only
            ],
            'rbac.service.authorization' => [
                'class' => AuthorizationService::class,
                'arguments' => ['@rbac.repository.role', '@rbac.repository.permission'],
                'shared' => true,
                'public' => true, // Public API access needed
                'alias'  => [AuthorizationServiceInterface::class, 'rbac.authz'], // Interface + shortcut
            ],
        ];
    }
    
    public function register(): void 
    {
        // Merge RBAC configuration defaults
        $this->mergeConfig('rbac', require __DIR__ . '/../config/rbac.php');
    }
    
    public function boot(): void 
    {
        // Load RBAC routes
        $this->loadRoutesFrom(__DIR__ . '/../routes/rbac.php');
        
        // Load RBAC migrations  
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        
        // Register RBAC middleware
        $this->app->get('middleware.registry')->register([
            'rbac.authorize' => AuthorizationMiddleware::class,
            'rbac.role' => RoleMiddleware::class,
        ]);
        
        // Register extension metadata
        $this->app->get(\Glueful\Extensions\ExtensionManager::class)->registerMeta(self::class, [
            'slug' => 'rbac',
            'name' => 'RBAC Extension',
            'version' => '1.0.0',
            'description' => 'Role-based access control for Glueful',
        ]);
    }
}
```
