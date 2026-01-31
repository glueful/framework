<?php

declare(strict_types=1);

namespace Glueful\Helpers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Services\FileFinder;

/**
 * Centralized Configuration Manager
 *
 * Handles loading, caching, and accessing all configuration files
 * with environment-aware loading and validation.
 */
class ConfigManager
{
    /** @var array<string, mixed> */
    private static array $config = [];
    private static bool $loaded = false;
    private static string $environment;
    private static ?ApplicationContext $context = null;
    /** @var array<string> */
    private static array $requiredConfigs = [
        'app', 'database', 'security', 'cache'
    ];
    private static ?string $cacheFile = null;

    public static function setContext(?ApplicationContext $context): void
    {
        self::$context = $context;
    }

    public static function load(?string $configPath = null): void
    {
        if (self::$loaded) {
            return;
        }

        $configPath = $configPath ?? self::getBasePath('config');

        // Improved environment detection with fallbacks
        self::$environment = env('APP_ENV', 'production');

        // Try to load from cache in production
        if (self::$environment === 'production' && self::loadFromCache($configPath)) {
            self::$loaded = true;
            return;
        }

        // Load core configurations first with error handling
        foreach (self::$requiredConfigs as $config) {
            if (!self::loadConfigFile($configPath, $config, true)) {
                throw new \RuntimeException("Required configuration file '{$config}.php' not found");
            }
        }

        // Load remaining configurations using FileFinder
        $fileFinder = self::resolveFileFinder();
        $configFiles = $fileFinder->findConfigFiles($configPath);

        foreach ($configFiles as $file) {
            $name = $file->getBasename('.php');
            if (!in_array($name, self::$requiredConfigs, true)) {
                self::loadConfigFile($configPath, $name, false);
            }
        }

        // Load environment-specific overrides with proper merging
        self::loadEnvironmentOverrides($configPath);

        // Validate critical configurations
        self::validateConfig();

        // Cache configuration in production
        if (self::$environment === 'production') {
            self::saveToCache($configPath);
        }

        self::$loaded = true;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::$loaded) {
            self::load();
        }

        return self::getNestedValue(self::$config, $key, $default);
    }

    /**
     * @param mixed $value
     */
    public static function set(string $key, mixed $value): void
    {
        self::setNestedValue(self::$config, $key, $value);
    }

    public static function has(string $key): bool
    {
        return self::get($key) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        if (!self::$loaded) {
            self::load();
        }
        return self::$config;
    }

    private static function loadConfigFile(string $path, string $name, bool $required = false): bool
    {
        $file = $path . '/' . $name . '.php';

        if (!file_exists($file)) {
            if ($required) {
                throw new \RuntimeException("Required configuration file not found: {$file}");
            }
            return false;
        }

        if (!is_readable($file)) {
            throw new \RuntimeException("Configuration file is not readable: {$file}");
        }

        $config = require $file;

        // Validate that config file returns an array
        if (!is_array($config)) {
            throw new \RuntimeException("Configuration file must return an array: {$file}");
        }

        self::$config[$name] = $config;
        return true;
    }

    private static function loadEnvironmentOverrides(string $configPath): void
    {
        $envFile = $configPath . '/environments/' . self::$environment . '.php';

        if (!file_exists($envFile)) {
            return;
        }

        if (!is_readable($envFile)) {
            throw new \RuntimeException("Environment config file is not readable: {$envFile}");
        }

        $overrides = require $envFile;

        if (!is_array($overrides)) {
            throw new \RuntimeException("Environment config file must return an array: {$envFile}");
        }

        // Use custom deep merge instead of array_merge_recursive
        self::$config = self::deepMerge(self::$config, $overrides);
    }

    private static function validateConfig(): void
    {
        $errors = [];

        // Validate database config
        if (self::get('database.default') === null) {
            $errors[] = 'Database default connection not configured';
        }

        $defaultDb = self::get('database.default');
        if ($defaultDb !== null && self::get("database.connections.{$defaultDb}") === null) {
            $errors[] = "Database connection '{$defaultDb}' is not defined";
        }

        // Validate security config
        if (self::$environment === 'production') {
            if (self::get('security.jwt.secret') === null) {
                $errors[] = 'JWT secret not configured for production';
            }

            if (self::get('app.key') === null) {
                $errors[] = 'Application key not configured for production';
            }

            if (self::get('app.debug', false) === true) {
                $errors[] = 'Debug mode should be disabled in production';
            }
        }

        // Validate cache config
        if (self::get('cache.default') === null) {
            $errors[] = 'Default cache driver not configured';
        }

        if (count($errors) > 0) {
            throw new \RuntimeException(
                "Configuration validation failed:\n- " . implode("\n- ", $errors)
            );
        }
    }

    /**
     * @param array<string, mixed> $array
     * @param mixed $default
     * @return mixed
     */
    private static function getNestedValue(array $array, string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $array;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $array
     * @param mixed $value
     */
    private static function setNestedValue(array &$array, string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $current = &$array;

        foreach ($keys as $segment) {
            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }
            $current = &$current[$segment];
        }

        $current = $value;
    }

    /**
     * Proper deep merge that replaces values instead of creating arrays
     * @param array<string, mixed> $array1
     * @param array<string, mixed> $array2
     * @return array<string, mixed>
     */
    private static function deepMerge(array $array1, array $array2): array
    {
        $merged = $array1;

        foreach ($array2 as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = self::deepMerge($merged[$key], $value);
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * Cache configuration for production performance
     */
    private static function loadFromCache(string $configPath): bool
    {
        self::$cacheFile = sys_get_temp_dir() . '/glueful_config_' . md5($configPath) . '.php';

        if (!file_exists(self::$cacheFile)) {
            return false;
        }

        // Check if cache is stale (older than any config file)
        $cacheTime = filemtime(self::$cacheFile);
        $fileFinder = self::resolveFileFinder();
        $configFiles = $fileFinder->findConfigFiles($configPath);

        foreach ($configFiles as $file) {
            if ($file->getMTime() > $cacheTime) {
                return false;
            }
        }

        // Check environment-specific config
        $envFile = $configPath . '/environments/' . self::$environment . '.php';
        if (file_exists($envFile) && filemtime($envFile) > $cacheTime) {
            return false;
        }

        // Load from cache
        $cached = require self::$cacheFile;
        if (is_array($cached)) {
            self::$config = $cached;
            return true;
        }

        return false;
    }

    /**
     * Save configuration to cache
     */
    private static function saveToCache(string $configPath): void
    {
        if (self::$cacheFile === null || self::$cacheFile === '') {
            self::$cacheFile = sys_get_temp_dir() . '/glueful_config_' . md5($configPath) . '.php';
        }

        $content = '<?php return ' . var_export(self::$config, true) . ';';

        if (file_put_contents(self::$cacheFile, $content) === false) {
            // Log warning but don't throw exception
            error_log("Warning: Unable to cache configuration to " . self::$cacheFile);
        }
    }

    /**
     * Clear configuration cache
     */
    public static function clearCache(): void
    {
        if (self::$cacheFile !== null && self::$cacheFile !== '' && file_exists(self::$cacheFile)) {
            unlink(self::$cacheFile);
        }
    }

    /**
     * Get current environment
     */
    public static function getEnvironment(): string
    {
        if (!self::$loaded) {
            self::load();
        }
        return self::$environment;
    }

    private static function resolveFileFinder(): FileFinder
    {
        if (self::$context !== null) {
            return container(self::$context)->get(FileFinder::class);
        }

        return new FileFinder();
    }

    private static function getBasePath(string $suffix = ''): string
    {
        if (self::$context !== null) {
            return base_path(self::$context, $suffix);
        }

        $root = rtrim(dirname(__DIR__, 3), DIRECTORY_SEPARATOR);
        return $suffix !== ''
            ? $root . DIRECTORY_SEPARATOR . ltrim($suffix, DIRECTORY_SEPARATOR)
            : $root;
    }
}
