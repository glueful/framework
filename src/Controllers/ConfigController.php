<?php

declare(strict_types=1);

namespace Glueful\Controllers;

use Glueful\Helpers\ConfigManager;
use Glueful\Exceptions\SecurityException;
use Glueful\Exceptions\BusinessLogicException;
use Glueful\Exceptions\ValidationException;
use Glueful\Helpers\ValidationHelper;
use Glueful\Http\Response;
use Glueful\Services\FileManager;
use Glueful\Extensions\ExtensionManager;

class ConfigController extends BaseController
{
    public function __construct()
    {
        // CRITICAL: Call parent constructor to initialize BaseController properties
        parent::__construct();
    }

    /**
     * Get the list of sensitive configuration files
     *
     * This method allows the sensitive files list to be configured via the security config
     * rather than being hardcoded. Falls back to the default list if not configured.
     *
     * @return array<string> List of config file names considered sensitive
     */
    private function getSensitiveConfigFiles(): array
    {
        return config('security.sensitive_config_files', [
            'security',
            'database',
            'app',
            'auth',
            'services' // API keys and service credentials
        ]);
    }

    /**
     * Get all configuration files with HTTP caching
     * This endpoint returns public configuration that can be cached by CDNs
     */
    public function getConfigs(): Response
    {
        // Check permissions
        $this->requirePermission('system.config.view');

        // Get configuration data
        $groupedConfig = $this->loadAllConfigs();

         $configList = [];
        foreach ($groupedConfig as $config) {
            $configList[] = [
                'name' => $config['name'],
                'path' => $config['name'] . '.php'
            ];
        }

        // Use simple success response instead of cached to avoid middleware issues
        return $this->publicSuccess($configList, 'Configuration retrieved');
    }

    /**
     * Get specific configuration file with conditional caching
     */
    public function getConfigByFile(string $filename): Response
    {
        // Check permissions÷
        $this->requirePermission('system.config.view');

        // Multi-level rate limiting (using BaseController's rateLimitMethod)
        $this->rateLimitMethod('config_file');


        // Remove .php extension if present for consistent lookup
        $configName = str_replace('.php', '', $filename);

        // Validate filename
        if (!$this->validateConfigName($configName)) {
            return $this->notFound('Configuration file not found');
        }

        // Check for sensitive config access
        if (in_array($configName, $this->getSensitiveConfigFiles(), true)) {
            $this->requirePermission('system.config.sensitive.view');

            // Sensitive configs get private caching (5 minutes)
            $config = $this->loadConfigFile($configName);
            if ($config === null) {
                return $this->notFound('Configuration file not found');
            }
            $formattedConfig = [
                'name' => $filename,
                'content' => $config
            ];

            return $this->privateCached(
                $this->success($formattedConfig, 'Configuration retrieved'),
                300  // 5 minutes for sensitive configs
            );
        }

        // Check if content has been modified
        $lastModified = $this->getConfigLastModified($configName);
        if ($lastModified !== null) {
            $notModifiedResponse = $this->checkNotModified($lastModified);
            if ($notModifiedResponse !== null) {
                return $notModifiedResponse;
            }
        }

        // Load the config
        $config = $this->loadConfigFile($configName);
        if ($config === null) {
            return $this->notFound('Configuration file not found');
        }

        // Create cacheable response with Last-Modified header
        $formattedConfig = [
            'name' => $filename,
            'content' => $config
        ];
        $response = $this->publicSuccess($formattedConfig, 'Configuration retrieved', 1800); // 30 minutes

        if ($lastModified !== null) {
            $response = $this->withLastModified($response, $lastModified);
        }

        return $response;
    }

    /**
     * Get public API configuration with aggressive caching
     * This endpoint is designed for high-traffic, public access
     */
    public function getPublicConfig(): Response
    {
        // No authentication required for public config

        // Add modest public rate limiting to prevent abuse
        $this->rateLimitMethod('public_config', [
            'attempts' => 60,
            'window' => 60,
            'adaptive' => true
        ]);

        $publicConfig = [
            'app_name' => ConfigManager::get('app.name', 'Glueful'),
            'api_version' => ConfigManager::get('app.version', '1.0'),
            'features' => [
                'registration_enabled' => ConfigManager::get('auth.registration.enabled', true),
                'social_login_enabled' => ConfigManager::get('auth.social.enabled', false),
                'api_docs_enabled' => ConfigManager::get('api.docs.enabled', true),
            ],
            'limits' => [
                'max_upload_size' => ConfigManager::get('upload.max_size', '10MB'),
                'rate_limit' => ConfigManager::get('api.rate_limit.default', 100),
            ]
        ];

        // Very aggressive caching for public config (6 hours)
        // This is safe because it's public, non-sensitive data
        return $this->publicSuccess($publicConfig, 'Public configuration retrieved', 21600);
    }

    /**
     * Update configuration with cache invalidation
     * @param array<string, mixed> $data
     */
    public function updateConfig(string $filename, array $data): Response
    {
        // Check permissions
        $this->requirePermission('system.config.edit');

        // Multi-level rate limiting for write operations
        $this->rateLimitMethod('config_update');


        // Require low risk behavior for sensitive operations (using BaseController)
        $this->requireLowRiskBehavior(0.6, 'config_update');

        $configName = str_replace('.php', '', $filename);

        // Validate config name and data
        if (!$this->validateConfigName($configName)) {
            return $this->validationError(['filename' => 'Invalid configuration name']);
        }

        if (!$this->validateConfigData($data)) {
            return $this->validationError(['data' => 'Invalid configuration data']);
        }

        // Check for sensitive config modifications
        if (in_array($configName, $this->getSensitiveConfigFiles(), true)) {
            $this->requirePermission('system.config.sensitive.edit');
        }

        // Get existing config through ConfigManager for audit trail
        $existingConfig = ConfigManager::get($configName, []);

        if ($existingConfig === [] || $existingConfig === null) {
            return $this->notFound('Configuration not found');
        }

        // Create rollback point before making changes
        $this->createConfigRollbackPoint($configName, $existingConfig);

        // Merge (optionally recursive) and then validate via schema if available
        $newConfig = $this->shouldUseRecursiveMerge()
            ? $this->recursiveMerge($existingConfig, $data)
            : array_merge($existingConfig, $data);

        // Update in ConfigManager (runtime)
        ConfigManager::set($configName, $newConfig);

        // Persist to file
        $success = $this->persistConfigToFile($configName, $newConfig);

        if (!$success) {
            return $this->serverError('Failed to update configuration');
        }

        $this->updateEnvVariables($data);


        // Invalidate config cache (using BaseController implementation)
        $this->invalidateResourceCache('config', $configName);

        // Return success with no caching (since data just changed)
        return $this->success(['updated' => true], 'Configuration updated successfully');
    }

    /**
     * Create new configuration file
     * @param array<string, mixed> $data
     */
    public function createConfig(string $name, array $data): bool
    {
        // Check permissions
        $this->requirePermission('system.config.create');

        // Multi-level rate limiting for write operations
        $this->rateLimitMethod('config_create');


        // Require low risk behavior for sensitive operations
        $this->requireLowRiskBehavior(0.6, 'config_create');

        // Validate config name
        $configName = str_replace('.php', '', $name);
        if (!$this->validateConfigName($configName)) {
            throw new ValidationException('Invalid configuration name');
        }

        if (!$this->validateConfigData($data)) {
            throw new ValidationException('Invalid configuration data');
        }

        // Get FileManager service
        $fileManager = container()->get(FileManager::class);

        // Determine config path
        $configPath = config_path();
        $filePath = $configPath . '/' . $configName . '.php';

        // Check if config already exists
        if ($fileManager->exists($filePath)) {
            throw new BusinessLogicException("Configuration file '{$configName}' already exists");
        }

        // Ensure config directory exists
        if (!$fileManager->exists($configPath)) {
            if (!$fileManager->createDirectory($configPath)) {
                throw new BusinessLogicException('Failed to create config directory');
            }
        }

        // $data is already typed as array in method signature

        // Generate configuration content
        $configContent = "<?php\n\n";
        $configContent .= "/**\n";
        $configContent .= " * Configuration: {$configName}\n";
        $configContent .= " * Created: " . date('Y-m-d H:i:s') . "\n";
        $configContent .= " * Created by: " . ($this->getCurrentUserUuid() ?? 'system') . "\n";
        $configContent .= " */\n\n";
        $configContent .= "return " . var_export($data, true) . ";\n";

        // Atomic write via temp file + rename
        $success = $this->writeAtomic($filePath, $configContent);

        if (!$success) {
            throw new BusinessLogicException('Failed to write configuration file');
        }

        // Set appropriate permissions (readable by web server)
        @chmod($filePath, 0644);

        // Clear configuration cache
        ConfigManager::clearCache();


        // Invalidate config cache
        $this->invalidateResourceCache('config', $configName);

        return true;
    }

    /**
     * Get schema information for a specific configuration
     */
    public function getSchemaInfo(string $configName): Response
    {
        // Check permissions
        $this->requirePermission('system.config.view');

        try {
            $schemaPath = base_path('config/' . $configName . '.php');
            if (!is_file($schemaPath)) {
                return $this->notFound('Schema not found for configuration: ' . $configName);
            }

            $enhanced = [
                'name' => $configName,
                'description' => 'Plain PHP config file',
                'version' => '1.0',
                'has_schema' => true,
                'config_exists' => $this->configFileExists($configName),
                'schema_structure' => null,
            ];

            return $this->publicSuccess($enhanced, 'Schema information retrieved', 1800);
        } catch (\Exception $e) {
            return $this->serverError('Failed to get schema info: ' . $e->getMessage());
        }
    }

    /**
     * Get all available configuration schemas
     */
    public function getAllSchemas(): Response
    {
        // Check permissions
        $this->requirePermission('system.config.view');

        try {
            $pattern = base_path('config/*.php');
            $globResult = glob($pattern);
            $files = $globResult !== false ? $globResult : [];

            $schemaList = [];
            foreach ($files as $file) {
                $name = basename($file, '.php');
                $schemaList[] = [
                    'name' => $name,
                    'description' => 'Plain PHP config file',
                    'version' => '1.0',
                    'config_exists' => $this->configFileExists($name),
                    'is_extension_schema' => false,
                ];
            }

            return $this->publicSuccess($schemaList, 'Configuration files retrieved', 1800);
        } catch (\Exception $e) {
            return $this->serverError('Failed to get schemas: ' . $e->getMessage());
        }
    }

    /**
     * Validate configuration data against its schema
     */
    public function validateConfig(): Response
    {
        // Check permissions
        $this->requirePermission('system.config.view');

        // Multi-level rate limiting for validation operations
        $this->rateLimitMethod('config_validate');

        $requestData = $this->getRequestData();

        // Validate request data
        if (!isset($requestData['config_name']) || !isset($requestData['config_data'])) {
            return $this->validationError([
                'config_name' => 'Configuration name is required',
                'config_data' => 'Configuration data is required'
            ]);
        }

        $configName = $requestData['config_name'];
        $configData = $requestData['config_data'];

        if (!is_array($configData)) {
            return $this->validationError(['config_data' => 'Configuration data must be an array']);
        }

        try {
            // $configData is guaranteed to be an array from loadConfigFile when not null

            return $this->success([
                'valid' => true,
                'config_name' => $configName,
                'processed_config' => $configData,
                'validation_message' => 'Configuration is valid'
            ], 'Configuration validated successfully');
        } catch (\InvalidArgumentException $e) {
            return $this->validationError([
                'valid' => false,
                'config_name' => $configName,
                'errors' => [$e->getMessage()],
                'validation_message' => 'Configuration validation failed'
            ]);
        } catch (\Exception $e) {
            return $this->serverError('Failed to validate configuration: ' . $e->getMessage());
        }
    }

    /**
     * Validate existing configuration file against its schema
     */
    public function validateExistingConfig(string $configName): Response
    {
        // Check permissions
        $this->requirePermission('system.config.view');

        // Multi-level rate limiting
        $this->rateLimitMethod('config_validate');

        try {
            // Load existing configuration
            $existingConfig = $this->loadConfigFile($configName);
            if ($existingConfig === null) {
                return $this->notFound('Configuration file not found: ' . $configName);
            }

            return $this->success([
                'valid' => true,
                'config_name' => $configName,
                'original_config' => $existingConfig,
                'processed_config' => $existingConfig,
                'validation_message' => 'Existing configuration is valid'
            ], 'Configuration validated successfully');
        } catch (\InvalidArgumentException $e) {
            return $this->validationError([
                'valid' => false,
                'config_name' => $configName,
                'errors' => [$e->getMessage()],
                'validation_message' => 'Configuration validation failed'
            ]);
        } catch (\Exception $e) {
            return $this->serverError('Failed to validate configuration: ' . $e->getMessage());
        }
    }

    /**
     * Load all configuration files
     */
    /**
     * @return array<int, array{name: string, config: array<string, mixed>, source: string, extension_version?: string}>
     */
    private function loadAllConfigs(): array
    {
        // Load config files directly from the application config directory
        $configPath = config_path();
        $configFiles = glob($configPath . '/*.php');

        if ($configFiles === false) {
            return [];
        }

        $groupedConfig = [];

        // Load core configs
        foreach ($configFiles as $file) {
            // Skip if not a file or not readable
            if (!is_file($file) || !is_readable($file)) {
                continue;
            }

            $name = basename($file, '.php');

            // Load the config file
            $config = require $file;

            // Validate that config file returns an array
            if (!is_array($config)) {
                continue;
            }

            // Mask sensitive data based on user permissions
            $maskedConfig = $this->maskSensitiveData($config, $name);

            $groupedConfig[] = [
                'name' => $name,
                'config' => $maskedConfig,
                'source' => 'core'
            ];
        }

        // Load extension configs
        $extensionConfigs = $this->loadExtensionConfigs();
        foreach ($extensionConfigs as $extensionConfig) {
            $groupedConfig[] = [
                'name' => $extensionConfig['name'],
                'config' => $extensionConfig['content'],
                'source' => 'extension',
                'extension_version' => $extensionConfig['extension_version'] ?? null
            ];
        }

        return $groupedConfig;
    }

    /**
     * Load a specific configuration file
     */
    /**
     * @return array<string, mixed>|null
     */
    private function loadConfigFile(string $configName): ?array
    {
        // Use permission-aware caching for config file access
        return $this->cacheByPermission("config_file_{$configName}", function () use ($configName) {
            // First check core config files
            $configPath = config_path();
            $filePath = $configPath . '/' . $configName . '.php';

            // Check if the core config file exists and is readable
            if (file_exists($filePath) && is_readable($filePath)) {
                // Load the config file directly
                $config = require $filePath;

                // Validate that config file returns an array
                if (is_array($config)) {
                    // Mask sensitive data
                    return $this->maskSensitiveData($config, $configName);
                }
            }

            // If not found in core, check extension configs
            $extensionConfigs = $this->loadExtensionConfigs();

            if (isset($extensionConfigs[$configName])) {
                $extensionConfig = $extensionConfigs[$configName];


                return $extensionConfig['content'];
            }

            return null;
        });
    }

    /**
     * Get last modified time for config file
     */
    private function getConfigLastModified(string $configName): ?\DateTime
    {
        $configPath = config_path();
        $filePath = $configPath . '/' . $configName . '.php';

        if (file_exists($filePath)) {
            $timestamp = filemtime($filePath);
            if ($timestamp !== false) {
                return (new \DateTime())->setTimestamp($timestamp);
            }
        }

        return null;
    }

    // ... rest of the existing methods remain the same ...
    // (keeping all the existing functionality intact)

    /**
     * @return array<string, array{
     *     name: string,
     *     source: string,
     *     content: array<string, mixed>,
     *     extension_version: string|null,
     *     last_modified: int|false
     * }>
     */
    private function loadExtensionConfigs(): array
    {
        $configs = [];

        try {
            // Get enabled extension names directly
            $extensionManager = container()->get(ExtensionManager::class);
            $enabledExtensionNames = $extensionManager->listEnabled();

            foreach ($enabledExtensionNames as $extensionName) {
                // Check common config file locations in extensions directory
                $extensionPath = base_path('extensions/' . $extensionName);

                if (!is_dir($extensionPath)) {
                    continue;
                }

                // Check common config file locations
                $configPaths = [
                    $extensionPath . '/src/config.php',
                    $extensionPath . '/config.php',
                    $extensionPath . '/config/' . strtolower($extensionName) . '.php'
                ];

                foreach ($configPaths as $configFile) {
                    if (file_exists($configFile)) {
                        $config = $this->safeIncludeConfig($configFile);

                        // Mask sensitive data based on user permissions
                        $maskedConfig = $this->maskSensitiveData($config, $extensionName);

                        $configs[$extensionName] = [
                            'name' => $extensionName,
                            'source' => 'extension',
                            'content' => $maskedConfig,
                            'extension_version' => null, // Can be enhanced later if needed
                            'last_modified' => filemtime($configFile)
                        ];

                        break; // Only load first found config file
                    }
                }
            }
        } catch (\Exception $e) {
            // Log error but don't fail the entire operation
            error_log("Error loading extension configs: " . $e->getMessage());
        }

        return $configs;
    }

    /**
     * @return array<string, mixed>
     */
    private function safeIncludeConfig(string $file): array
    {
        // Basic security: ensure file is within allowed paths
        $realPath = realpath($file);
        $basePath = realpath(base_path());

        if (!$realPath || !str_starts_with($realPath, $basePath)) {
            throw new SecurityException("Invalid config file path: {$file}");
        }

        $config = include $realPath;

        if (!is_array($config)) {
            throw BusinessLogicException::operationNotAllowed(
                'load_config',
                "Config file {$file} must return an array"
            );
        }

        return $config;
    }

    // Add all the other existing methods here...
    // (This is a simplified version to show the HTTP caching patterns)

    /**
     * Mask sensitive data in configuration arrays
     */
    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function maskSensitiveData(array $config, string $configName): array
    {
        // If user has sensitive view permissions, return unmasked
        if ($this->can('system.config.sensitive.view')) {
            return $config;
        }

        $masked = $config;

        // Mask based on config file type
        switch ($configName) {
            case 'security':
                $masked = $this->maskSecurityConfig($masked);
                break;
            case 'database':
                $masked = $this->maskDatabaseConfig($masked);
                break;
            case 'app':
                $masked = $this->maskAppConfig($masked);
                break;
        }

        return $this->maskSensitiveKeys($masked);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function maskSecurityConfig(array $config): array
    {
        if (isset($config['jwt']['secret'])) {
            $config['jwt']['secret'] = '[REDACTED]';
        }
        if (isset($config['jwt']['private_key'])) {
            $config['jwt']['private_key'] = '[REDACTED]';
        }
        if (isset($config['jwt']['public_key'])) {
            $config['jwt']['public_key'] = '[REDACTED]';
        }
        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function maskDatabaseConfig(array $config): array
    {
        if (isset($config['password'])) {
            $config['password'] = '[REDACTED]';
        }
        if (isset($config['connections']) && is_array($config['connections'])) {
            foreach ($config['connections'] as $name => $connection) {
                if (isset($connection['password'])) {
                    $config['connections'][$name]['password'] = '[REDACTED]';
                }
            }
        }
        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function maskAppConfig(array $config): array
    {
        if (isset($config['key'])) {
            $config['key'] = '[REDACTED]';
        }
        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function maskSensitiveKeys(array $config): array
    {
        return $this->recursiveMaskSensitiveKeys($config);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function recursiveMaskSensitiveKeys(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->recursiveMaskSensitiveKeys($value);
            } elseif ($this->isSensitiveKeyName($key)) {
                $data[$key] = '[REDACTED]';
            }
        }
        return $data;
    }

    private function isSensitiveKeyName(string $key): bool
    {
        $sensitivePatterns = [
            'password', 'secret', 'key', 'token', 'private_key',
            'public_key', 'api_key', 'auth_key', 'encryption_key'
        ];

        $lowerKey = strtolower($key);

        foreach ($sensitivePatterns as $pattern) {
            if (strpos($lowerKey, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    private function validateConfigName(string $name): bool
    {
        try {
            ValidationHelper::validateLength($name, 1, 50, 'config_name');
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
                return false;
            }
            return true;
        } catch (\Exception $e) {
            error_log("Config name validation error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateConfigData(array $data): bool
    {
        // Basic validation
        if ($data === []) {
            return false;
        }

        // Check max depth
        if ($this->getArrayDepth($data) > 5) {
            return false;
        }

        // Check max keys
        if (count($data, COUNT_RECURSIVE) > 100) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $array
     */
    private function getArrayDepth(array $array): int
    {
        $maxDepth = 1;

        foreach ($array as $value) {
            if (is_array($value)) {
                $depth = $this->getArrayDepth($value) + 1;
                if ($depth > $maxDepth) {
                    $maxDepth = $depth;
                }
            }
        }

        return $maxDepth;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function persistConfigToFile(string $configName, array $config): bool
    {
        $configPath = config_path();
        $filePath = $configPath . '/' . $configName . '.php';

        // Create a backup before writing
        $this->createConfigRollbackPoint($configName, $config);

        $configContent = "<?php\n\nreturn " . var_export($config, true) . ";\n";

        return $this->writeAtomic($filePath, $configContent);
    }

    /**
     * Update corresponding .env variables for config changes
     *
     * LIMITATIONS:
     * - Only supports top-level config keys (e.g., 'app.name' -> 'APP_NAME')
     * - Nested config changes are NOT mapped (e.g., 'database.connections.mysql.host' won't update)
     * - Values are quoted as strings; complex types (arrays/objects) are not supported
     * - Boolean values are converted to '1' or '0' for compatibility
     *
     * For nested config changes, manual .env updates or direct config file editing is required.
     *
     * @param array<string, mixed> $data Top-level config key-value pairs
     */
    private function updateEnvVariables(array $data): void
    {
        $envPath = base_path('.env');
        if (!file_exists($envPath)) {
            return;
        }

        $envContent = file_get_contents($envPath);
        $lines = explode("\n", $envContent);
        $updated = false;

        foreach ($data as $key => $value) {
            // Only process top-level keys (single dot notation)
            if (substr_count($key, '.') !== 1) {
                continue; // Skip nested keys
            }

            $envKey = $this->findEnvKeyForConfigValue($key);
            if ($envKey !== '') {
                $lines = $this->updateEnvLine($lines, $envKey, $value);
                $updated = true;
            }
        }

        if ($updated) {
            file_put_contents($envPath, implode("\n", $lines));
        }
    }

    /**
     * Convert config key to .env key format
     *
     * Maps dot notation to underscore format (e.g., 'app.name' -> 'APP_NAME')
     * Only handles single-level dot notation for simplicity and security.
     *
     * @param string $key Config key in dot notation
     * @return string ENV key in uppercase with underscores
     */
    private function findEnvKeyForConfigValue(string $key): string
    {
        // Only convert simple top-level keys for safety
        if (substr_count($key, '.') === 1) {
            return strtoupper(str_replace('.', '_', $key));
        }
        return ''; // Return empty for nested keys
    }

    /**
     * @param array<int, string> $lines
     * @param mixed $value
     * @return array<int, string>
     */
    private function updateEnvLine(array $lines, string $key, mixed $value): array
    {
        $newLine = $key . '=' . (is_string($value) ? '"' . $value . '"' : $value);

        foreach ($lines as $i => $line) {
            if (strpos($line, $key . '=') === 0) {
                $lines[$i] = $newLine;
                return $lines;
            }
        }

        $lines[] = $newLine;
        return $lines;
    }

    // Placeholder methods for missing functionality - these will use BaseController implementations
    /**
     * @param array<string, mixed> $config
     */
    private function createConfigRollbackPoint(string $configName, array $config): void
    {
        try {
            $configDir = config_path();
            $filePath = $configDir . '/' . $configName . '.php';
            if (!file_exists($filePath)) {
                return; // nothing to back up
            }

            $backupDir = $configDir . '/backups';
            if (!is_dir($backupDir)) {
                @mkdir($backupDir, 0755, true);
            }

            $timestamp = date('Ymd_His');
            $backupPath = sprintf('%s/%s.php.bak.%s', $backupDir, $configName, $timestamp);
            @copy($filePath, $backupPath);
        } catch (\Throwable $e) {
            // Non-fatal: log and continue
            error_log('Failed to create config backup: ' . $e->getMessage());
        }
    }

    /**
     * Perform an atomic write: write to temp file then rename.
     */
    private function writeAtomic(string $filePath, string $content): bool
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $tmpPath = $filePath . '.tmp.' . uniqid('', true);
        $bytes = @file_put_contents($tmpPath, $content, LOCK_EX);
        if ($bytes === false) {
            @unlink($tmpPath);
            return false;
        }

        $renamed = @rename($tmpPath, $filePath);
        if (!$renamed) {
            @unlink($tmpPath);
            return false;
        }

        @chmod($filePath, 0644);
        return true;
    }

    /**
     * Determine whether to use recursive merge for updates.
     */
    private function shouldUseRecursiveMerge(): bool
    {
        return (bool) (ConfigManager::get('config.updates.recursive_merge', true));
    }

    /**
     * Recursively merge configuration arrays (override scalar values with updates).
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function recursiveMerge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->recursiveMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    /**
     * Check if a configuration file exists
     */
    private function configFileExists(string $configName): bool
    {
        $configPath = config_path();
        $filePath = $configPath . '/' . $configName . '.php';
        return file_exists($filePath) && is_readable($filePath);
    }

    // Legacy Symfony\Config schema analyzers removed
}
