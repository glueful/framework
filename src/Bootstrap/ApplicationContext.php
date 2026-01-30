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
        if ($this->configLoader === null) {
            return $default;
        }

        $segments = explode('.', $key);
        $configName = array_shift($segments);

        // Load the config file if not already loaded
        if (!isset($this->loadedConfigs[$configName])) {
            $this->loadedConfigs[$configName] = $this->configLoader->loadConfig($configName);
        }

        $config = $this->loadedConfigs[$configName];

        // Traverse nested keys
        foreach ($segments as $segment) {
            if (!is_array($config) || !array_key_exists($segment, $config)) {
                return $default;
            }
            $config = $config[$segment];
        }

        return $config;
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
