<?php

/**
 * Global Helper Functions
 *
 * This file contains globally accessible helper functions for environment
 * variables and configuration management.
 */

declare(strict_types=1);

if (!function_exists('env')) {
    /**
     * Get environment variable value
     *
     * Retrieves value from environment with type casting support.
     * Handles special values like 'true', 'false', 'null', and 'empty'.
     *
     * @param string $key Environment variable name
     * @param mixed $default Default value if not found
     * @return mixed Processed environment value
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? false;

        if ($value === false) {
            return $default;
        }

        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'null':
            case '(null)':
                return null;
            case 'empty':
            case '(empty)':
                return '';
        }

        return $value;
    }
}

if (!function_exists('config')) {
    /**
     * Get configuration value using dot notation with framework hierarchy support
     *
     * @param \Glueful\Bootstrap\ApplicationContext $context Application context
     * @param string $key Configuration key in dot notation
     * @param mixed $default Default value if key not found
     * @return mixed Configuration value or default
     */
    function config(\Glueful\Bootstrap\ApplicationContext $context, string $key, mixed $default = null): mixed
    {
        return $context->getConfig($key, $default);
    }
}

if (!function_exists('loadConfigWithHierarchy')) {
    /**
     * Load config file with framework + application hierarchy
     */
    function loadConfigWithHierarchy(\Glueful\Bootstrap\ApplicationContext $context, string $file): array
    {
        $frameworkConfig = [];
        $applicationConfig = [];

        // Get paths from ApplicationContext
        $configPaths = $context->getConfigPaths();
        $frameworkPath = $configPaths['framework'] ?? ($configPaths[0] ?? null);
        $applicationPath = $configPaths['application'] ?? ($configPaths[1] ?? null);

        // Load framework defaults first
        if (is_string($frameworkPath) && $frameworkPath !== '') {
            $frameworkFile = $frameworkPath . "/{$file}.php";
            if (file_exists($frameworkFile)) {
                $frameworkConfig = require $frameworkFile;
            }
        }

        // Load application config (user overrides/additions)
        if (is_string($applicationPath) && $applicationPath !== '') {
            $applicationFile = $applicationPath . "/{$file}.php";
            if (file_exists($applicationFile)) {
                $applicationConfig = require $applicationFile;
            }
        }

        // Fallback to framework config if no paths configured (package-relative)
        if ($frameworkPath === null && $applicationPath === null) {
            $fallbackPath = dirname(__DIR__) . "/config/{$file}.php";
            if (file_exists($fallbackPath)) {
                return require $fallbackPath;
            }
            return [];
        }

        // Merge configs intelligently
        return mergeConfigs($frameworkConfig, $applicationConfig);
    }
}

if (!function_exists('mergeConfigs')) {
    /**
     * Deep merge framework and application configs with sensible semantics.
     * - Associative arrays: recursively merged (app overrides framework)
     * - Numeric arrays (lists): appended + de-duplicated
     * - Per-key strategy: certain keys always treated as lists
     */
    function mergeConfigs(array $framework, array $application): array
    {
        $listKeys = [
            'middleware', 'providers', 'extensions', 'routes', 'listeners', 'handlers'
        ];

        $isAssoc = static function (array $arr): bool {
            if ($arr === []) {
                return false;
            }
            return array_keys($arr) !== range(0, count($arr) - 1);
        };

        $merge = function (array $a, array $b, array $path = []) use (&$merge, $listKeys, $isAssoc): array {
            foreach ($b as $key => $value) {
                $currentPath = array_merge($path, [$key]);
                $treatAsList = in_array((string)$key, $listKeys, true);

                if (array_key_exists($key, $a)) {
                    if (is_array($a[$key]) && is_array($value)) {
                        if ($treatAsList || (!$isAssoc($a[$key]) && !$isAssoc($value))) {
                            $a[$key] = array_values(array_unique(array_merge($a[$key], $value)));
                        } else {
                            $a[$key] = $merge($a[$key], $value, $currentPath);
                        }
                    } else {
                        $a[$key] = $value;
                    }
                } else {
                    $a[$key] = $value;
                }
            }
            return $a;
        };

        return $merge($framework, $application);
    }
}

if (!function_exists('parseConfigString')) {
    /**
     * Parses a config string in the format "key1:value1,key2:value2,..."
     *
     * @param string $configString The configuration string to parse.
     * @return array An associative array of key-value pairs.
     */

    function parseConfigString(string $configString): array
    {
        $config = [];
        $items = explode(',', $configString);
        foreach ($items as $item) {
            [$key, $value] = explode(':', $item, 2);
            $key = trim($key);
            $value = trim($value);

            // Handle boolean values
            if (strtolower($value) === 'true') {
                $value = true;
            } elseif (strtolower($value) === 'false') {
                $value = false;
            } elseif (is_numeric($value)) {
                $value = (int) $value;
            }

            $config[$key] = $value;
        }
        return $config;
    }
}
if (!function_exists('app')) {
    /**
     * Get the DI container instance or resolve a service
     *
     * Returns the global DI container instance when called without arguments,
     * or resolves and returns a specific service when called with a class name.
     *
     * @param \Glueful\Bootstrap\ApplicationContext $context Application context
     * @param string|null $abstract Service class name to resolve
     * @return mixed Container instance or resolved service
     */
    function app(\Glueful\Bootstrap\ApplicationContext $context, ?string $abstract = null): mixed
    {
        $container = $context->getContainer();

        if ($abstract === null) {
            return $container;
        }

        return $container->get($abstract);
    }
}

if (!function_exists('container')) {
    /**
     * Get the DI container instance
     *
     * Returns the global PSR-11 container instance once Framework bootstrap completes.
     * This provides access to all registered services.
     *
     * @param \Glueful\Bootstrap\ApplicationContext $context Application context
     * @return \Psr\Container\ContainerInterface Container instance
     */
    function container(\Glueful\Bootstrap\ApplicationContext $context): \Psr\Container\ContainerInterface
    {
        return $context->getContainer();
    }
}

if (!function_exists('db')) {
    /**
     * Get the database connection instance
     *
     * Provides convenient access to the database Connection with transaction
     * management capabilities including afterCommit/afterRollback callbacks.
     *
     * Usage:
     *   // Get the connection
     *   $connection = db($context);
     *
     *   // Execute a query
     *   $users = db($context)->table('users')->where('active', 1)->get();
     *
     *   // Transaction with after-commit callback
     *   db($context)->transaction(function() use ($context, $model) {
     *       $model->save();
     *       db($context)->afterCommit(fn() => $model->searchableSync());
     *   });
     *
     *   // Check transaction state
     *   if (db($context)->withinTransaction()) {
     *       db($context)->afterCommit($callback);
     *   }
     *
     * @param \Glueful\Bootstrap\ApplicationContext $context Application context
     * @return \Glueful\Database\Connection Database connection instance
     */
    function db(\Glueful\Bootstrap\ApplicationContext $context): \Glueful\Database\Connection
    {
        return app($context, \Glueful\Database\Connection::class);
    }
}

if (!function_exists('dump')) {
    /**
     * Dump the given variables using Symfony VarDumper
     *
     * This function provides beautiful, formatted variable dumps in development.
     * In production, it falls back to var_dump() for safety.
     *
     * @param mixed ...$vars Variables to dump
     * @return void
     */
    function dump(...$vars): void
    {
        if (env('APP_ENV') !== 'development' || !env('APP_DEBUG', false)) {
            // In production, fall back to simple var_dump
            foreach ($vars as $var) {
                var_dump($var);
            }
            return;
        }

        if (class_exists(\Symfony\Component\VarDumper\VarDumper::class)) {
            foreach ($vars as $var) {
                \Symfony\Component\VarDumper\VarDumper::dump($var);
            }
        } else {
            // Fallback if VarDumper not available
            foreach ($vars as $var) {
                var_dump($var);
            }
        }
    }
}

if (!function_exists('dd')) {
    /**
     * Dump the given variables and terminate script execution
     *
     * This function dumps variables using Symfony VarDumper and then
     * terminates script execution. Useful for debugging.
     *
     * @param mixed ...$vars Variables to dump before dying
     * @return never
     */
    function dd(...$vars): never
    {
        dump(...$vars);
        exit(1);
    }
}

if (!function_exists('service')) {
    /**
     * Get a service from the DI container
     *
     * Convenience function to resolve services from the container.
     * Equivalent to container()->get($id).
     *
     * @param \Glueful\Bootstrap\ApplicationContext $context Application context
     * @param string $id Service identifier
     * @return mixed Resolved service instance
     */
    function service(\Glueful\Bootstrap\ApplicationContext $context, string $id): mixed
    {
        return app($context, $id);
    }
}

if (!function_exists('parameter')) {
    /**
     * Get a parameter from the DI container
     *
     * Retrieves a parameter value from the container configuration.
     * Parameters are typically configuration values injected into services.
     *
     * @param \Glueful\Bootstrap\ApplicationContext $context Application context
     * @param string $name Parameter name
     * @return mixed Parameter value
     */
    function parameter(\Glueful\Bootstrap\ApplicationContext $context, string $name): mixed
    {
        $container = $context->getContainer();

        // Legacy Symfony-style parameter API if available
        if (is_object($container) && method_exists($container, 'getParameter')) {
            try {
                /** @var callable $callback */
                $callback = [$container, 'getParameter'];
                return $callback($name);
            } catch (\Throwable) {
                // fall through to other sources
            }
        }

        // Prefer ParamBag service if present (new container)
        try {
            if ($container instanceof \Psr\Container\ContainerInterface && $container->has('param.bag')) {
                $bag = $container->get('param.bag');
                if ($bag instanceof \Glueful\Container\Support\ParamBag) {
                    return $bag->get($name);
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        // Fallback: use config() helper if available
        return config($context, $name);
    }
}

if (!function_exists('has_service')) {
    /**
     * Check if a service exists in the container
     *
     * Determines whether the specified service is registered
     * in the DI container without attempting to instantiate it.
     *
     * @param \Glueful\Bootstrap\ApplicationContext $context Application context
     * @param string $id Service identifier
     * @return bool True if service exists
     */
    function has_service(\Glueful\Bootstrap\ApplicationContext $context, string $id): bool
    {
        if (!$context->hasContainer()) {
            return false;
        }

        return $context->getContainer()->has($id);
    }
}

if (!function_exists('is_production')) {
    /**
     * Check if application is running in production
     *
     * Determines the current environment based on configuration.
     * Used for environment-specific behavior and optimizations.
     *
     * @param \Glueful\Bootstrap\ApplicationContext $context Application context
     * @return bool True if production environment
     */
    function is_production(\Glueful\Bootstrap\ApplicationContext $context): bool
    {
        return config($context, 'app.env', 'production') === 'production';
    }
}

if (!function_exists('is_debug')) {
    /**
     * Check if debug mode is enabled
     *
     * Determines if the application is running in debug mode.
     * Debug mode enables additional logging, error reporting, and development features.
     *
     * @param \Glueful\Bootstrap\ApplicationContext $context Application context
     * @return bool True if debug mode is enabled
     */
    function is_debug(\Glueful\Bootstrap\ApplicationContext $context): bool
    {
        return config($context, 'app.debug', false) === true;
    }
}

if (!function_exists('get_service_ids')) {
    /**
     * Get all registered service IDs
     *
     * Returns an array of all service identifiers registered
     * in the DI container. Useful for debugging and introspection.
     *
     * @return array Array of service IDs
     */
    function get_service_ids(): array
    {
        // For Symfony Container, we need to get service IDs differently
        // This is a simplified implementation
        return [
            'Glueful\\Auth\\Interfaces\\SessionStoreInterface',
            'Glueful\\Repository\\UserRepository',
            'Glueful\\Extensions\\ExtensionManager',
            'Glueful\\Cache\\CacheStore',
            'Glueful\\Queue\\QueueManager',
            'Glueful\\Database\\DatabaseInterface'
        ];
    }
}

if (!function_exists('image')) {
    /**
     * Create image processor instance
     *
     * Convenience function to create and configure image processor instances.
     * Returns the ImageProcessorInterface for fluent image operations.
     *
     * @param \Glueful\Bootstrap\ApplicationContext $context Application context
     * @param string $source Image source path or URL
     * @return \Glueful\Services\ImageProcessorInterface
     */
    function image(
        \Glueful\Bootstrap\ApplicationContext $context,
        string $source
    ): \Glueful\Services\ImageProcessorInterface {
        $processor = app($context, \Glueful\Services\ImageProcessorInterface::class);
        return $processor::make($source);
    }
}

if (!function_exists('storage_path')) {
    /**
     * Get storage directory path
     *
     * Returns the absolute path to the storage directory or a specific file within it.
     * Creates the directory if it doesn't exist.
     *
     * @param \Glueful\Bootstrap\ApplicationContext $context Application context
     * @param string $path Relative path within storage directory
     * @return string Absolute path to storage location
     */
    function storage_path(\Glueful\Bootstrap\ApplicationContext $context, string $path = ''): string
    {
        $base = base_path($context, 'storage');

        if ($path === '') {
            return $base;
        }

        $fullPath = $base . '/' . ltrim($path, '/');

        // Ensure parent directory exists for convenience
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $fullPath;
    }
}

if (!function_exists('resource_path')) {
    /**
     * Get resource directory path
     *
     * Returns the absolute path to the resources directory or a specific file within it.
     * Resources typically contain assets, templates, and other non-code files.
     *
     * @param \Glueful\Bootstrap\ApplicationContext $context Application context
     * @param string $path Relative path within resources directory
     * @return string Absolute path to resource location
     */
    function resource_path(\Glueful\Bootstrap\ApplicationContext $context, string $path = ''): string
    {
        $base = base_path($context, 'resources');
        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('base_path')) {
    /**
     * Get application base directory path
     *
     * Returns the absolute path to the application root directory or a specific file within it.
     * This is the directory where composer.json and main application files are located.
     *
     * @param \Glueful\Bootstrap\ApplicationContext $context Application context
     * @param string $path Relative path within base directory
     * @return string Absolute path to base directory location
     */
    function base_path(\Glueful\Bootstrap\ApplicationContext $context, string $path = ''): string
    {
        $basePath = $context->getBasePath();
        return $path === '' ? $basePath : $basePath . '/' . ltrim($path, '/');
    }
}

if (!function_exists('config_path')) {
    /**
     * Get configuration file path with smart framework/application fallback
     *
     * Returns the path to a configuration file, following the framework's hierarchy:
     * 1. Application config directory (if file exists there)
     * 2. Framework config directory (if file exists there)
     * 3. Application config directory (default, even if file doesn't exist)
     *
     * @param \Glueful\Bootstrap\ApplicationContext $context Application context
     * @param string $path Relative path within config directory
     * @return string Absolute path to config file or directory
     */
    function config_path(\Glueful\Bootstrap\ApplicationContext $context, string $path = ''): string
    {
        $configPaths = $context->getConfigPaths();
        $frameworkConfigPath = $configPaths['framework'] ?? ($configPaths[0] ?? dirname(__DIR__) . '/config');
        $appConfigPath = $configPaths['application'] ?? ($configPaths[1] ?? base_path($context, 'config'));

        // If no specific file requested, return app config directory
        if (empty($path)) {
            return $appConfigPath;
        }

        // For specific files, check both app and framework locations
        $normalizedPath = ltrim($path, '/');
        $appFilePath = $appConfigPath . '/' . $normalizedPath;
        $frameworkFilePath = $frameworkConfigPath . '/' . $normalizedPath;

        // Return app path if file exists there
        if (file_exists($appFilePath)) {
            return $appFilePath;
        }

        // Return framework path if file exists there
        if (file_exists($frameworkFilePath)) {
            return $frameworkFilePath;
        }

        // Default to app path (for file creation, etc.)
        return $appFilePath;
    }
}

if (!function_exists('request_id')) {
    /**
     * Get unique request ID for current request
     *
     * Generates a unique identifier for the current request that persists
     * throughout the entire request lifecycle. Useful for request tracing,
     * correlation across logs, and debugging.
     *
     * @return string Unique request identifier
     */
    function request_id(): string
    {
        static $requestId = null;

        if ($requestId === null) {
            $requestId = uniqid('req_', true);
        }

        return $requestId;
    }
}

if (!function_exists('auth')) {
    /**
     * Get authentication guard instance
     *
     * Provides Laravel-style auth() helper for consistent authentication access.
     * Returns a wrapper around Glueful's authentication system.
     *
     * @param \Glueful\Bootstrap\ApplicationContext $context Application context
     * @param string|null $guard Guard name (currently unused, for future multi-guard support)
     * @return \Glueful\Auth\AuthenticationGuard|null
     */
    function auth(
        \Glueful\Bootstrap\ApplicationContext $context,
        ?string $guard = null
    ): ?\Glueful\Auth\AuthenticationGuard {
        try {
            if (has_service($context, \Glueful\Auth\AuthenticationGuard::class)) {
                return app($context, \Glueful\Auth\AuthenticationGuard::class);
            }

            // Fallback: create guard from existing services
            if (has_service($context, \Glueful\Auth\AuthenticationService::class)) {
                return new \Glueful\Auth\AuthenticationGuard(
                    app($context, \Glueful\Auth\AuthenticationService::class)
                );
            }
        } catch (\Throwable) {
            // Ignore errors in auth helper
        }

        return null;
    }
}

if (!function_exists('response')) {
    /**
     * Create a new response instance or return response helper
     *
     * This helper provides compatibility with Laravel-style response() calls:
     * - response() -> Returns ResponseHelper for method chaining
     * - response($content, $status, $headers) -> Returns Response instance directly
     *
     * @param mixed $content Response content
     * @param int $status HTTP status code
     * @param array<string, string> $headers Response headers
     * @return \Glueful\Http\Response|\Glueful\Http\ResponseHelper
     * @phpstan-return ($content is '' ? \Glueful\Http\ResponseHelper : \Glueful\Http\Response)
     */
    function response(mixed $content = '', int $status = 200, array $headers = []): mixed
    {
        if (func_num_args() === 0) {
            return new \Glueful\Http\ResponseHelper();
        }

        return new \Glueful\Http\Response($content, $status, $headers);
    }
}

if (!function_exists('api_url')) {
    /**
     * Generate a full API URL for a given path
     *
     * Uses the consolidated versioning config to build URLs that match
     * the actual route structure. Supports multiple URL patterns:
     * - Subdomain: api.example.com/v1/auth/login
     * - Path prefix: example.com/api/v1/auth/login
     * - No version: api.example.com/auth/login
     *
     * Example:
     * ```php
     * api_url('/auth/login');  // https://api.example.com/v1/auth/login
     * api_url();               // https://api.example.com/v1
     * ```
     *
     * @param \Glueful\Bootstrap\ApplicationContext $context Application context
     * @param string $path Route path (e.g., '/auth/login')
     * @return string Full URL (e.g., 'https://api.example.com/v1/auth/login')
     */
    function api_url(\Glueful\Bootstrap\ApplicationContext $context, string $path = ''): string
    {
        $baseUrl = rtrim(config($context, 'app.urls.base', 'http://localhost'), '/');
        $prefix = api_prefix($context);

        $url = $baseUrl . $prefix;

        if (!empty($path)) {
            $url .= '/' . ltrim($path, '/');
        }

        return $url;
    }
}

if (!function_exists('api_prefix')) {
    /**
     * Get the API route prefix for use in route definitions
     *
     * Returns the prefix string based on versioning configuration.
     * This is used by RouteManifest to prefix API routes and by
     * middleware to detect API requests.
     *
     * Examples based on configuration:
     * - apply_prefix=true, version_in_path=true: '/api/v1'
     * - apply_prefix=false, version_in_path=true: '/v1'
     * - apply_prefix=true, version_in_path=false: '/api'
     * - apply_prefix=false, version_in_path=false: ''
     *
     * @param \Glueful\Bootstrap\ApplicationContext $context Application context
     * @return string Prefix (e.g., '/api/v1' or '/v1' or '')
     */
    function api_prefix(\Glueful\Bootstrap\ApplicationContext $context): string
    {
        $versionConfig = config($context, 'api.versioning', []);
        $parts = [];

        // Add prefix if configured (e.g., "/api")
        $applyPrefix = $versionConfig['apply_prefix_to_routes'] ?? true;
        $prefix = $versionConfig['prefix'] ?? '/api';

        if ($applyPrefix && !empty($prefix)) {
            $parts[] = rtrim($prefix, '/');
        }

        // Add version if configured (e.g., "/v1")
        $versionInPath = $versionConfig['version_in_path'] ?? true;
        $version = $versionConfig['default'] ?? '1';

        if ($versionInPath) {
            $parts[] = '/v' . $version;
        }

        return implode('', $parts) ?: '';
    }
}

if (!function_exists('is_api_path')) {
    /**
     * Check if a path is an API route
     *
     * Determines if the given path matches the configured API prefix.
     * Used by middleware to distinguish API requests from web requests.
     *
     * Example:
     * ```php
     * is_api_path('/api/v1/users');  // true (with default config)
     * is_api_path('/admin/dashboard');  // false
     * ```
     *
     * @param \Glueful\Bootstrap\ApplicationContext $context Application context
     * @param string $path URL path to check
     * @return bool True if path is an API route
     */
    function is_api_path(\Glueful\Bootstrap\ApplicationContext $context, string $path): bool
    {
        $prefix = api_prefix($context);

        // If no prefix configured, all routes are considered API routes
        if (empty($prefix)) {
            return true;
        }

        return str_starts_with($path, $prefix);
    }
}

if (!function_exists('encrypt')) {
    /**
     * Encrypt a UTF-8 string with the framework encryption service.
     */
    function encrypt(
        \Glueful\Bootstrap\ApplicationContext $context,
        string $value,
        ?string $aad = null,
        ?string $key = null
    ): string {
        $service = app($context, \Glueful\Encryption\EncryptionService::class);
        return $key !== null
            ? $service->encryptWithKey($value, $key, $aad)
            : $service->encrypt($value, $aad);
    }
}

if (!function_exists('decrypt')) {
    /**
     * Decrypt a UTF-8 string with the framework encryption service.
     */
    function decrypt(
        \Glueful\Bootstrap\ApplicationContext $context,
        string $encrypted,
        ?string $aad = null,
        ?string $key = null
    ): string {
        $service = app($context, \Glueful\Encryption\EncryptionService::class);
        return $key !== null
            ? $service->decryptWithKey($encrypted, $key, $aad)
            : $service->decrypt($encrypted, $aad);
    }
}

if (!function_exists('encrypt_binary')) {
    /**
     * Encrypt raw bytes with the framework encryption service.
     */
    function encrypt_binary(
        \Glueful\Bootstrap\ApplicationContext $context,
        string $bytes,
        ?string $aad = null,
        ?string $key = null
    ): string {
        $service = app($context, \Glueful\Encryption\EncryptionService::class);
        return $key !== null
            ? $service->encryptBinaryWithKey($bytes, $key, $aad)
            : $service->encryptBinary($bytes, $aad);
    }
}

if (!function_exists('decrypt_binary')) {
    /**
     * Decrypt raw bytes with the framework encryption service.
     */
    function decrypt_binary(
        \Glueful\Bootstrap\ApplicationContext $context,
        string $encrypted,
        ?string $aad = null,
        ?string $key = null
    ): string {
        $service = app($context, \Glueful\Encryption\EncryptionService::class);
        return $key !== null
            ? $service->decryptBinaryWithKey($encrypted, $key, $aad)
            : $service->decryptBinary($encrypted, $aad);
    }
}

if (!function_exists('is_encrypted')) {
    /**
     * Check whether a string looks like Glueful encrypted payload.
     */
    function is_encrypted(string $value): bool
    {
        return str_starts_with($value, '$glueful$v1$');
    }
}
