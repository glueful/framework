<?php

declare(strict_types=1);

namespace Glueful\Configuration;

/**
 * Environment Configuration Loader
 *
 * Loads configuration files with environment-specific overrides.
 * Supports loading base configurations and then environment-specific
 * overrides from subdirectories like config/production/, config/local/, etc.
 */
class EnvironmentConfigLoader
{
    /**
     * Load configuration with environment-specific overrides
     *
     * @param string $configPath Base configuration directory path
     * @param string $environment Environment name (e.g., 'production', 'local', 'testing')
     * @return array<string, mixed> Merged configuration array
     */
    public function loadEnvironmentConfig(string $configPath, string $environment): array
    {
        $config = [];

        // Load base configuration files first
        if (is_dir($configPath)) {
            $config = $this->loadConfigDirectory($configPath);
        }

        // Load environment-specific overrides
        $envConfigPath = rtrim($configPath, '/') . '/' . $environment;
        if (is_dir($envConfigPath)) {
            $envConfig = $this->loadConfigDirectory($envConfigPath);
            $config = $this->deepMerge($config, $envConfig);
        }

        return $config;
    }

    /**
     * Load all PHP configuration files from a directory
     *
     * @param string $path Directory path
     * @return array Configuration array indexed by filename
     */
    /**
     * @return array<string, mixed>
     */
    private function loadConfigDirectory(string $path): array
    {
        $config = [];

        if (!is_dir($path)) {
            return $config;
        }

        $pattern = rtrim($path, '/') . '/*.php';
        foreach (glob($pattern) as $configFile) {
            $key = basename($configFile, '.php');
            $loaded = require $configFile;
            if (is_array($loaded)) {
                $config[$key] = $loaded;
            }
        }

        return $config;
    }

    /**
     * Deep merge two configuration arrays
     *
     * Recursively merges arrays, with the second array taking precedence.
     * For associative arrays, keys are merged. For indexed arrays, values are appended.
     *
     * @param array $base Base configuration array
     * @param array $overrides Override configuration array
     * @return array Merged configuration
     */
    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function deepMerge(array $base, array $overrides): array
    {
        $merged = $base;

        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                // Distinguish associative vs indexed arrays
                $isAssoc = static function (array $arr): bool {
                    if ($arr === []) {
                        return false;
                    }
                    return array_keys($arr) !== range(0, count($arr) - 1);
                };

                if ($isAssoc($merged[$key]) || $isAssoc($value)) {
                    // Associative arrays: recursive merge
                    $merged[$key] = $this->deepMerge($merged[$key], $value);
                } else {
                    // Indexed arrays: append and de-duplicate
                    $merged[$key] = array_values(array_unique(array_merge($merged[$key], $value)));
                }
            } else {
                // Scalar values or one side is not an array: override
                $merged[$key] = $value;
            }
        }

        return $merged;
    }

    /**
     * Get list of available environments by scanning for subdirectories
     *
     * @param string $configPath Base configuration directory path
     * @return array List of environment names
     */
    /**
     * @return array<string>
     */
    public function getAvailableEnvironments(string $configPath): array
    {
        $environments = [];

        if (!is_dir($configPath)) {
            return $environments;
        }

        $iterator = new \DirectoryIterator($configPath);
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDot() || !$fileInfo->isDir()) {
                continue;
            }

            $envName = $fileInfo->getFilename();
            // Check if this directory contains PHP config files
            $envPath = $fileInfo->getPathname();
            if (glob($envPath . '/*.php')) {
                $environments[] = $envName;
            }
        }

        sort($environments);
        return $environments;
    }

    /**
     * Check if environment-specific configuration exists
     *
     * @param string $configPath Base configuration directory path
     * @param string $environment Environment name
     * @return bool True if environment config directory exists with PHP files
     */
    public function hasEnvironmentConfig(string $configPath, string $environment): bool
    {
        $envConfigPath = rtrim($configPath, '/') . '/' . $environment;

        if (!is_dir($envConfigPath)) {
            return false;
        }

        // Check if there are any PHP config files in the environment directory
        return (glob($envConfigPath . '/*.php') !== false ? glob($envConfigPath . '/*.php') : []) !== [];
    }
}
