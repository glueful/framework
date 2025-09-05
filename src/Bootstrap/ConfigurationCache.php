<?php

declare(strict_types=1);

namespace Glueful\Bootstrap;

class ConfigurationCache
{
    /** @var array<string, mixed> */
    private static array $config = [];
    private static bool $loaded = false;
    private static ?ConfigurationLoader $loader = null;

    /** @param array<string, mixed> $config */
    public static function setAll(array $config): void
    {
        self::$config = $config;
        self::$loaded = true;
    }

    /**
     * Set the configuration loader for lazy loading
     */
    public static function setLoader(ConfigurationLoader $loader): void
    {
        self::$loader = $loader;
        self::$loaded = true; // Mark as "loaded" even though we'll load lazily
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::$loaded) {
            throw new \RuntimeException('Configuration not loaded yet. Ensure ConfigurationLoader runs first.');
        }

        // Extract the config file name from the key (e.g., 'cache' from 'cache.driver')
        $segments = explode('.', $key);
        $configFile = $segments[0];

        // Lazy load the config file if not already loaded
        if (!isset(self::$config[$configFile]) && self::$loader !== null) {
            self::$config[$configFile] = self::$loader->loadConfig($configFile);
        }

        // Now traverse the config tree
        $current = self::$config;
        foreach ($segments as $segment) {
            if (!isset($current[$segment])) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }

    public static function has(string $key): bool
    {
        if (!self::$loaded) {
            return false;
        }

        $segments = explode('.', $key);
        $current = self::$config;

        foreach ($segments as $segment) {
            if (!isset($current[$segment])) {
                return false;
            }
            $current = $current[$segment];
        }

        return true;
    }

    public static function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $current = &self::$config;

        foreach ($segments as $segment) {
            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }
            $current = &$current[$segment];
        }

        $current = $value;
    }

    /**
     * Clear all cached configuration
     */
    public static function clear(): void
    {
        self::$config = [];
        self::$loaded = false;
        self::$loader = null;
    }

    /**
     * Get all configuration
     * @return array<string, mixed>
     */
    public static function getAll(): array
    {
        return self::$config;
    }
}
