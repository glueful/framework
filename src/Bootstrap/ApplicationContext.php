<?php

declare(strict_types=1);

namespace Glueful\Bootstrap;

use Psr\Container\ContainerInterface;

/**
 * Application context holding all framework state.
 *
 * Replaces $GLOBALS for proper multi-app support and testability.
 */
final class ApplicationContext
{
    /** @var array<string, array<string, mixed>> Loaded config files cache */
    private array $loadedConfigs = [];

    /** @var array<string, array<string, mixed>> Extension-provided config defaults, merged under file config */
    private array $configDefaults = [];

    /** @var array<string, mixed> Process-local config overrides applied over file/default config. */
    private array $configOverrides = [];
    private ?ContainerInterface $container = null;
    private ?ConfigurationLoader $configLoader = null;
    private bool $booted = false;

    /** @var array<string, mixed> Cached configuration values (per-app) */
    private array $configCache = [];

    /** @var array<string, mixed> Per-request state that resets on each request */
    private array $requestState = [];

    /**
     * @param array<string> $configPaths
     */
    public function __construct(
        private readonly string $basePath,
        private readonly string $environment = 'production',
        private readonly array $configPaths = [],
    ) {
    }

    public function getBasePath(): string
    {
        return $this->basePath;
    }

    public function getEnvironment(): string
    {
        return $this->environment;
    }

    /**
     * @return array<int, string>
     */
    public function getConfigPaths(): array
    {
        return $this->configPaths;
    }

    public function hasContainer(): bool
    {
        return $this->container !== null;
    }

    public function getContainer(): ContainerInterface
    {
        if ($this->container === null) {
            throw new \RuntimeException('Container not initialized');
        }

        return $this->container;
    }

    public function setContainer(ContainerInterface $container): void
    {
        $this->container = $container;
    }

    public function getConfigLoader(): ConfigurationLoader
    {
        if ($this->configLoader === null) {
            throw new \RuntimeException('ConfigLoader not initialized');
        }

        return $this->configLoader;
    }

    public function setConfigLoader(ConfigurationLoader $loader): void
    {
        $this->configLoader = $loader;
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    public function markBooted(): void
    {
        $this->booted = true;
    }

    public function getConfig(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->configCache)) {
            return $this->configCache[$key];
        }

        $value = $this->resolveConfigValue($key, $default);
        $this->configCache[$key] = $value;

        return $value;
    }

    /**
     * Resolve a dot-notation config key (e.g., 'app.name' or 'database.default.driver').
     */
    private function resolveConfigValue(string $key, mixed $default): mixed
    {
        $segments = explode('.', $key);
        $configName = array_shift($segments);

        // Load the config file (if any) and merge registered extension defaults UNDER it
        // (framework/app/env file values win over defaults). Cached per config name.
        if (!isset($this->loadedConfigs[$configName])) {
            $fileConfig = $this->configLoader !== null
                ? $this->configLoader->loadConfig($configName)
                : [];
            $defaults = $this->configDefaults[$configName] ?? [];
            $this->loadedConfigs[$configName] = $defaults !== []
                ? self::deepMerge($defaults, $fileConfig)
                : $fileConfig;
        }

        $config = $this->loadedConfigs[$configName];

        // Process override layer wins over file/default config (precedence:
        // extension defaults < file/env < override). Applied here so overrides
        // survive clearConfigCache() (which only empties the loaded/cached layers).
        if (array_key_exists($configName, $this->configOverrides)) {
            $override = $this->configOverrides[$configName];
            $config = is_array($config) && is_array($override)
                ? self::deepMerge($config, $override)
                : $override;
        }

        // Traverse nested keys
        foreach ($segments as $segment) {
            if (!is_array($config) || !array_key_exists($segment, $config)) {
                return $default;
            }
            $config = $config[$segment];
        }

        return $config;
    }

    /**
     * Register extension-provided config defaults for a config name.
     *
     * Defaults are merged UNDER any framework/app/env config file for that name (file/app values
     * win) and persist across clearConfigCache(). This is what makes
     * {@see \Glueful\Extensions\ServiceProvider::mergeConfig()} actually reach config().
     *
     * @param array<string, mixed> $defaults
     */
    public function mergeConfigDefaults(string $name, array $defaults): void
    {
        $this->configDefaults[$name] = isset($this->configDefaults[$name])
            ? self::deepMerge($this->configDefaults[$name], $defaults)
            : $defaults;

        // Invalidate caches for this name so the next read re-merges defaults with file config.
        unset($this->loadedConfigs[$name]);
        foreach (array_keys($this->configCache) as $cachedKey) {
            if ($cachedKey === $name || str_starts_with($cachedKey, $name . '.')) {
                unset($this->configCache[$cachedKey]);
            }
        }
    }

    /**
     * Apply a process-local config override that wins over file/env/default config.
     *
     * Boot-only: overrides shape the container's view of config once, before the app is
     * booted. Calling after {@see markBooted()} is a programming error — mid-request config
     * changes would create split-brain services that read config at different times.
     *
     * A nested key invalidates the entire top-level namespace cache (including cached parent
     * reads), and the override persists across {@see clearConfigCache()}.
     */
    public function overrideConfig(string $key, mixed $value): void
    {
        if ($this->booted) {
            throw new \LogicException(
                'overrideConfig() must be called before boot completes (after markBooted()).'
            );
        }

        $segments = explode('.', $key);
        if ($segments === [] || in_array('', $segments, true)) {
            throw new \InvalidArgumentException('Config override keys must be non-empty dot paths.');
        }
        $configName = array_shift($segments);

        if ($segments === []) {
            $this->configOverrides[$configName] = $value;
        } else {
            $nested = $value;
            foreach (array_reverse($segments) as $segment) {
                $nested = [$segment => $nested];
            }
            $existing = $this->configOverrides[$configName] ?? [];
            $this->configOverrides[$configName] = is_array($existing)
                ? self::deepMerge($existing, $nested)
                : $nested;
        }

        // Invalidate the whole top-level namespace so parent + child cached reads re-resolve.
        unset($this->loadedConfigs[$configName]);
        foreach (array_keys($this->configCache) as $cachedKey) {
            if ($cachedKey === $configName || str_starts_with($cachedKey, $configName . '.')) {
                unset($this->configCache[$cachedKey]);
            }
        }
    }

    /**
     * Deep-merge two config arrays: $override wins for scalars; nested arrays merge recursively.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private static function deepMerge(array $base, array $override): array
    {
        $merged = $base;
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = self::deepMerge($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    public function clearConfigCache(): void
    {
        $this->configCache = [];
        $this->loadedConfigs = [];
    }

    public function getRequestState(string $key, mixed $default = null): mixed
    {
        return $this->requestState[$key] ?? $default;
    }

    public function setRequestState(string $key, mixed $value): void
    {
        $this->requestState[$key] = $value;
    }

    public function resetRequestState(): void
    {
        $this->requestState = [];
    }

    public static function forTesting(string $basePath): self
    {
        return new self(
            basePath: $basePath,
            environment: 'testing',
            configPaths: [$basePath . '/config'],
        );
    }
}
