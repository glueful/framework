<?php

declare(strict_types=1);

namespace Glueful\Bootstrap;

class ConfigurationLoader
{
    private string $basePath;
    private string $environment;
    /** @var array<string, string> */
    private array $configPaths;

    public function __construct(string $basePath, string $environment, ?string $configPath = null)
    {
        $this->basePath = rtrim($basePath, '/');
        $this->environment = $environment;
        $this->configPaths = [
            'framework' => dirname(__DIR__, 2) . '/config',
            'application' => $configPath ?? ($this->basePath . '/config')
        ];
    }

    /**
     * Load all configuration files in dependency order
     * @return array<string, mixed>
     */
    public function loadAllConfiguration(): array
    {
        // Load configs in dependency order to prevent circular references
        $configOrder = [
            'app',
            'cache',
            'database',
            'session',
            'security',
            'extensions',
            'queue',
            'mail',
            'filesystem',
            'logging',
            'services'
        ];

        $configs = [];

        foreach ($configOrder as $configName) {
            $configs[$configName] = $this->loadConfig($configName);
        }

        // Load any additional configs that weren't in the standard list
        $this->loadAdditionalConfigs($configs);

        return $configs;
    }

    /**
     * Load a specific configuration file
     * @return array<string, mixed>
     */
    public function loadConfig(string $name): array
    {
        $config = [];

        // Load framework defaults first
        $frameworkConfig = $this->loadConfigFile($this->configPaths['framework'], $name);
        if ($frameworkConfig !== []) {
            $config = $frameworkConfig;
        }

        // Load application overrides
        $applicationConfig = $this->loadConfigFile($this->configPaths['application'], $name);
        if ($applicationConfig !== []) {
            $config = $this->mergeConfigs($config, $applicationConfig);
        }

        // Load environment-specific overrides
        $envConfig = $this->loadEnvironmentConfig($name);
        if ($envConfig !== []) {
            $config = $this->mergeConfigs($config, $envConfig);
        }

        // Process environment variables
        $config = $this->processEnvironmentVariables($config);

        return $config;
    }

    /**
     * Load configuration file from a specific path
     * @return array<string, mixed>
     */
    private function loadConfigFile(string $basePath, string $name): array
    {
        $configFile = $basePath . '/' . $name . '.php';

        if (!file_exists($configFile)) {
            return [];
        }

        try {
            $config = require $configFile;
            return is_array($config) ? $config : [];
        } catch (\Throwable $e) {
            error_log("Failed to load config file {$configFile}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Load environment-specific configuration
     * @return array<string, mixed>
     */
    private function loadEnvironmentConfig(string $name): array
    {
        $envConfigFile = $this->configPaths['application'] . '/' . $this->environment . '/' . $name . '.php';

        if (!file_exists($envConfigFile)) {
            return [];
        }

        try {
            $config = require $envConfigFile;
            return is_array($config) ? $config : [];
        } catch (\Throwable $e) {
            error_log("Failed to load environment config file {$envConfigFile}: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Merge configuration arrays (override rather than create arrays)
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function mergeConfigs(array $base, array $override): array
    {
        // Use array_replace_recursive instead of array_merge_recursive
        // to avoid creating arrays when merging scalar values
        return array_replace_recursive($base, $override);
    }

    /**
     * Process environment variables in configuration values
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function processEnvironmentVariables(array $config): array
    {
        array_walk_recursive($config, function (&$value) {
            if (is_string($value) && preg_match('/^env\(([^,\)]+)(?:,\s*(.+))?\)$/', $value, $matches)) {
                $envKey = trim($matches[1], '"\'');
                $default = isset($matches[2]) ? trim($matches[2], '"\'') : null;

                $envValue = $_ENV[$envKey] ?? getenv($envKey);
                $value = $envValue !== false ? $this->castEnvValue($envValue) : $default;
            }
        });

        return $config;
    }

    /**
     * Cast environment variable values to appropriate types
     */
    private function castEnvValue(string $value): mixed
    {
        // Handle boolean values
        if (in_array(strtolower($value), ['true', '1', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array(strtolower($value), ['false', '0', 'no', 'off'], true)) {
            return false;
        }

        // Handle null
        if (strtolower($value) === 'null') {
            return null;
        }

        // Handle numbers
        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        return $value;
    }

    /**
     * Load any additional configuration files not in the standard list
     * @param array<string, mixed> $configs
     */
    private function loadAdditionalConfigs(array &$configs): void
    {
        $standardConfigs = array_keys($configs);

        // Check application config directory for additional configs
        if (is_dir($this->configPaths['application'])) {
            $configFiles = glob($this->configPaths['application'] . '/*.php');

            foreach ($configFiles as $configFile) {
                $configName = basename($configFile, '.php');

                if (!in_array($configName, $standardConfigs, true) && $configName !== $this->environment) {
                    $configs[$configName] = $this->loadConfig($configName);
                }
            }
        }
    }
}
