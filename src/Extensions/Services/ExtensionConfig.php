<?php

declare(strict_types=1);

namespace Glueful\Extensions\Services;

use Glueful\Extensions\Services\Interfaces\ExtensionConfigInterface;
use Glueful\Extensions\Exceptions\ExtensionException;
use Glueful\Services\FileManager;
use Glueful\DI\ContainerBootstrap;
use Psr\Log\LoggerInterface;

class ExtensionConfig implements ExtensionConfigInterface
{
    /** @var array<string, mixed>|null */
    private ?array $configCache = null;
    /** @var array<string, mixed>|null */
    private ?array $metadataCache = null;
    private ?int $configLastModified = null;
    private string $configPath;
    private bool $debug = false;

    public function __construct(
        ?string $configPath = null,
        private ?FileManager $fileManager = null,
        private ?LoggerInterface $logger = null
    ) {
        $this->configPath = $configPath ?? $this->getDefaultConfigPath();
        $this->initializeServices();
    }

    private function initializeServices(): void
    {
        if ($this->fileManager === null || $this->logger === null) {
            try {
                $container = ContainerBootstrap::getContainer();
                $this->fileManager ??= $container->get(FileManager::class);
                $this->logger ??= $container->get(LoggerInterface::class);
            } catch (\Exception $e) {
                // Fallback to creating directly if container not available
                $this->fileManager ??= new FileManager();
            }
        }
    }

    public function setDebugMode(bool $enable = true): void
    {
        $this->debug = $enable;
    }

    /** @return array<string, mixed> */
    public function getConfig(): array
    {
        if ($this->shouldReloadConfig()) {
            $this->loadConfigFromFile();
        }

        return $this->configCache ?? [];
    }

    /** @param array<string, mixed> $config */
    public function saveConfig(array $config): bool
    {
        try {
            $jsonContent = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->debugLog("JSON encoding error: " . json_last_error_msg());
                return false;
            }

            $result = file_put_contents($this->configPath, $jsonContent, LOCK_EX);

            if ($result !== false) {
                // Update cache
                $this->configCache = $config;
                $this->configLastModified = filemtime($this->configPath);
                $this->debugLog("Configuration saved to: {$this->configPath}");
                return true;
            }

            return false;
        } catch (\Exception $e) {
            $this->debugLog("Failed to save config: " . $e->getMessage());
            return false;
        }
    }

    /** @return array<string, mixed> */
    public function getExtensionConfig(string $name): array
    {
        $config = $this->getConfig();
        return $config['extensions'][$name] ?? [];
    }

    /** @param array<string, mixed> $extensionConfig */
    public function updateExtensionConfig(string $name, array $extensionConfig): void
    {
        $config = $this->getConfig();
        $config['extensions'][$name] = array_merge(
            $config['extensions'][$name] ?? [],
            $extensionConfig
        );

        if (!$this->saveConfig($config)) {
            throw new ExtensionException("Failed to update configuration for extension: {$name}");
        }
    }

    /** @return string[] */
    public function getEnabledExtensions(): array
    {
        $config = $this->getConfig();
        $enabled = [];

        // Get base enabled extensions
        foreach ($config['extensions'] ?? [] as $name => $extensionConfig) {
            if (($extensionConfig['enabled'] ?? false) === true) {
                $enabled[] = $name;
            }
        }

        // Check for environment-specific filtering (like current system)
        $environment = env('APP_ENV', 'production');
        if (isset($config['environments'][$environment]['enabledExtensions'])) {
            // Only include extensions that are both individually enabled AND listed in environment config
            $envEnabled = $config['environments'][$environment]['enabledExtensions'];
            return array_intersect($enabled, $envEnabled);
        }

        return $enabled;
    }

    public function isEnabled(string $name): bool
    {
        $extensionConfig = $this->getExtensionConfig($name);
        return ($extensionConfig['enabled'] ?? false) === true;
    }

    public function enableExtension(string $name): void
    {
        $config = $this->getConfig();

        // Always read and update from manifest.json to sync any changes
        $extensionData = $this->createExtensionConfigFromManifest($name);
        // Preserve any custom settings if they exist
        if (isset($config['extensions'][$name]['settings'])) {
            $extensionData['settings'] = $config['extensions'][$name]['settings'];
        }
        $config['extensions'][$name] = $extensionData;

        // Update environments section
        $this->updateEnvironments($config, $name, true);

        if (!$this->saveConfig($config)) {
            throw new ExtensionException("Failed to enable extension: {$name}");
        }

        $this->debugLog("Enabled extension: {$name}");
    }

    /**
     * Check if extension config is minimal (missing manifest data)
     */
    /** @param array<string, mixed> $config */
    private function isMinimalExtensionConfig(array $config): bool
    {
        // Check if it only has basic properties and lacks manifest metadata
        $basicKeys = ['enabled', 'autoload', 'settings'];
        $configKeys = array_keys($config);
        // If it only has basic keys and lacks metadata keys like 'version', 'description', etc.
        return count(array_diff($configKeys, $basicKeys)) === 0;
    }

    /**
     * Create extension configuration from manifest.json file
     */
    /** @return array<string, mixed> */
    private function createExtensionConfigFromManifest(string $name): array
    {
        $extensionsPath = $this->getExtensionsPath();
        $extensionPath = $extensionsPath . DIRECTORY_SEPARATOR . $name;
        $manifestPath = $extensionPath . DIRECTORY_SEPARATOR . 'manifest.json';

        // Check if manifest.json exists
        if (!file_exists($manifestPath)) {
            // Fallback to basic config if no manifest
            return [
                'enabled' => true,
                'autoload' => true,
                'settings' => []
            ];
        }

        try {
            $manifestContent = file_get_contents($manifestPath);
            $manifest = json_decode($manifestContent, true);

            if ($manifest === null || $manifest === false) {
                throw new \Exception("Invalid JSON in manifest.json");
            }

            // Transform manifest.json format to extensions.json format
            $extensionConfig = [
                'version' => $manifest['version'] ?? '1.0.0',
                'enabled' => true,
                'type' => $this->determineExtensionType($manifest),
                'description' => $manifest['description'] ?? '',
                'author' => $manifest['author'] ?? 'Unknown',
                'license' => $manifest['license'] ?? 'MIT',
                'installPath' => 'extensions/' . $name,
                'autoload' => [
                    'psr-4' => [
                        "Glueful\\Extensions\\{$name}\\" => "extensions/{$name}/src/"
                    ]
                ],
                'dependencies' => [
                    'php' => $manifest['engines']['php'] ?? '^8.2',
                    'extensions' => $manifest['dependencies']['extensions'] ?? [],
                    'packages' => $manifest['dependencies']['composer'] ?? []
                ],
                'provides' => [
                    'main' => "extensions/{$name}/" . ($manifest['main'] ?? "{$name}.php"),
                    'services' => [],
                    'routes' => $this->detectRoutes($extensionPath),
                    'middleware' => $manifest['provides']['middleware'] ?? [],
                    'commands' => $manifest['provides']['commands'] ?? [],
                    'migrations' => $this->detectMigrations($extensionPath)
                ],
                'config' => [
                    'categories' => $manifest['categories'] ?? ['custom'],
                    'publisher' => strtolower($manifest['author'] ?? 'unknown'),
                    'icon' => "extensions/{$name}/assets/icon.png"
                ]
            ];

            // Add additional config if present
            if (isset($manifest['features'])) {
                $extensionConfig['config']['features'] = $manifest['features'];
            }

            if (isset($manifest['repository'])) {
                $extensionConfig['config']['repository'] = $manifest['repository'];
            }

            return $extensionConfig;
        } catch (\Exception $e) {
            $this->debugLog("Error reading manifest for {$name}: " . $e->getMessage());

            // Return basic config on error
            return [
                'enabled' => true,
                'autoload' => true,
                'settings' => []
            ];
        }
    }

    /**
     * Get the extensions directory path
     */
    private function getExtensionsPath(): string
    {
        // Use application base path helper
        return base_path('extensions');
    }

    /**
     * Update environments section when enabling/disabling extensions
     */
    /** @param array<string, mixed> $config */
    private function updateEnvironments(array &$config, string $extensionName, bool $enabled): void
    {
        // Ensure environments section exists
        if (!isset($config['environments'])) {
            $config['environments'] = [
                'development' => [
                    'enabledExtensions' => [],
                    'autoload_dev' => true,
                    'debug_mode' => true
                ],
                'production' => [
                    'enabledExtensions' => [],
                    'autoload_dev' => false,
                    'debug_mode' => false
                ]
            ];
        }

        foreach ($config['environments'] as $env => &$envConfig) {
            if (!isset($envConfig['enabledExtensions'])) {
                $envConfig['enabledExtensions'] = [];
            }

            $currentIndex = array_search($extensionName, $envConfig['enabledExtensions'], true);

            if ($enabled) {
                // Add to enabledExtensions if not already present
                if ($currentIndex === false) {
                    $envConfig['enabledExtensions'][] = $extensionName;
                }
            } else {
                // Remove from enabledExtensions if present
                if ($currentIndex !== false) {
                    array_splice($envConfig['enabledExtensions'], $currentIndex, 1);
                }
            }
        }
    }

    /**
     * Determine extension type based on manifest data
     */
    /** @param array<string, mixed> $manifest */
    private function determineExtensionType(array $manifest): string
    {
        // Check if it's a core extension based on author or other criteria
        $author = strtolower($manifest['author'] ?? '');
        if (in_array($author, ['glueful team', 'glueful', 'core'], true)) {
            return 'core';
        }
        return 'optional';
    }

    /**
     * Detect routes files in extension
     */
    /** @return string[] */
    private function detectRoutes(string $extensionPath): array
    {
        $routes = [];
        $routesFile = $extensionPath . '/src/routes.php';

        if (file_exists($routesFile)) {
            $routes[] = str_replace($this->getExtensionsPath() . '/', 'extensions/', $routesFile);
        }

        return $routes;
    }

    /**
     * Detect migration files in extension
     */
    /** @return string[] */
    private function detectMigrations(string $extensionPath): array
    {
        $migrations = [];
        $migrationsDir = $extensionPath . '/migrations';

        if (is_dir($migrationsDir)) {
            $files = glob($migrationsDir . '/*.php');
            foreach ($files as $file) {
                $migrations[] = str_replace($this->getExtensionsPath() . '/', 'extensions/', $file);
            }
        }

        return $migrations;
    }

    public function disableExtension(string $name): void
    {
        $config = $this->getConfig();

        if (isset($config['extensions'][$name])) {
            $config['extensions'][$name]['enabled'] = false;

            // Update environments section
            $this->updateEnvironments($config, $name, false);

            if (!$this->saveConfig($config)) {
                throw new ExtensionException("Failed to disable extension: {$name}");
            }
        }

        $this->debugLog("Disabled extension: {$name}");
    }

    /** @return array<string, mixed> */
    public function getExtensionSettings(string $name): array
    {
        $extensionConfig = $this->getExtensionConfig($name);
        return $extensionConfig['settings'] ?? [];
    }

    /** @param array<string, mixed> $settings */
    public function updateExtensionSettings(string $name, array $settings): void
    {
        $this->updateExtensionConfig($name, ['settings' => $settings]);
        $this->debugLog("Updated settings for extension: {$name}");
    }

    /** @return string[] */
    public function getCoreExtensions(): array
    {
        $config = $this->getConfig();
        $core = [];

        foreach ($config['extensions'] ?? [] as $name => $extensionConfig) {
            if (($extensionConfig['type'] ?? 'optional') === 'core') {
                $core[] = $name;
            }
        }

        return $core;
    }

    /** @return string[] */
    public function getOptionalExtensions(): array
    {
        $config = $this->getConfig();
        $optional = [];

        foreach ($config['extensions'] ?? [] as $name => $extensionConfig) {
            if (($extensionConfig['type'] ?? 'optional') === 'optional') {
                $optional[] = $name;
            }
        }

        return $optional;
    }

    public function isCoreExtension(string $name): bool
    {
        $extensionConfig = $this->getExtensionConfig($name);
        return ($extensionConfig['type'] ?? 'optional') === 'core';
    }

    /** @param array<string, mixed> $extensionData */
    public function addExtension(string $name, array $extensionData): void
    {
        $config = $this->getConfig();
        $config['extensions'][$name] = array_merge([
            'enabled' => false,
            'autoload' => true,
            'type' => 'optional',
            'settings' => []
        ], $extensionData);

        if (!$this->saveConfig($config)) {
            throw new ExtensionException("Failed to add extension to config: {$name}");
        }

        $this->debugLog("Added extension to config: {$name}");
    }

    public function removeExtension(string $name): void
    {
        $config = $this->getConfig();

        if (isset($config['extensions'][$name])) {
            unset($config['extensions'][$name]);

            if (!$this->saveConfig($config)) {
                throw new ExtensionException("Failed to remove extension from config: {$name}");
            }
        }

        $this->debugLog("Removed extension from config: {$name}");
    }

    public function clearCache(): void
    {
        $this->configCache = null;
        $this->metadataCache = null;
        $this->configLastModified = null;
        $this->debugLog("Configuration cache cleared");
    }

    public function getConfigPath(): string
    {
        return $this->configPath;
    }

    public function setConfigPath(string $path): void
    {
        $this->configPath = $path;
        $this->clearCache();
    }

    /**
     * @param array<string, mixed> $config
     * @return string[]
     */
    public function validateConfig(array $config): array
    {
        $errors = [];

        // Check required structure
        if (!isset($config['extensions'])) {
            $errors[] = "Missing 'extensions' section in configuration";
        }

        // Validate each extension config
        foreach ($config['extensions'] ?? [] as $name => $extensionConfig) {
            if (!is_array($extensionConfig)) {
                $errors[] = "Extension '{$name}' configuration must be an array";
                continue;
            }

            // Check for required fields
            if (!isset($extensionConfig['enabled'])) {
                $errors[] = "Extension '{$name}' is missing 'enabled' field";
            }

            if (isset($extensionConfig['enabled']) && !is_bool($extensionConfig['enabled'])) {
                $errors[] = "Extension '{$name}' 'enabled' field must be boolean";
            }
        }

        return $errors;
    }

    /** @return string[] */
    public function getExtensionsByEnvironment(string $environment): array
    {
        $config = $this->getConfig();
        $extensions = [];

        foreach ($config['extensions'] ?? [] as $name => $extensionConfig) {
            $environments = $extensionConfig['environments'] ?? [];

            if ($environments === [] || in_array($environment, $environments, true)) {
                $extensions[] = $name;
            }
        }

        return $extensions;
    }

    private function shouldReloadConfig(): bool
    {
        if ($this->configCache === null) {
            return true;
        }

        if (!file_exists($this->configPath)) {
            return false;
        }

        $currentModified = filemtime($this->configPath);
        return $currentModified !== $this->configLastModified;
    }

    private function loadConfigFromFile(): void
    {
        if (!file_exists($this->configPath)) {
            $this->createDefaultConfig();
        }

        try {
            $content = file_get_contents($this->configPath);

            if ($content === false) {
                throw new ExtensionException("Could not read configuration file: {$this->configPath}");
            }

            $config = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ExtensionException("Invalid JSON in configuration file: " . json_last_error_msg());
            }

            $this->configCache = $config;
            $this->configLastModified = filemtime($this->configPath);
            $this->debugLog("Configuration loaded from: {$this->configPath}");
        } catch (\Exception $e) {
            $this->debugLog("Failed to load config: " . $e->getMessage());
            // Use empty config as fallback
            $this->configCache = ['extensions' => []];
        }
    }

    private function createDefaultConfig(): void
    {
        $defaultConfig = [
            'version' => '1.0',
            'last_updated' => date('Y-m-d H:i:s'),
            'extensions' => [],
            'settings' => [
                'auto_update' => false,
                'check_updates' => true,
                'registry_url' => 'https://registry.glueful.com'
            ]
        ];

        $this->saveConfig($defaultConfig);
        $this->debugLog("Created default configuration file: {$this->configPath}");
    }

    private function getDefaultConfigPath(): string
    {
        return base_path('extensions/extensions.json');
    }

    private function debugLog(string $message): void
    {
        if (!$this->debug) {
            return;
        }

        if ($this->logger !== null) {
            $this->logger->debug("[ExtensionConfig] {$message}");
        } else {
            error_log("[ExtensionConfig] {$message}");
        }
    }
}
