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
     * @param string $key Configuration key in dot notation
     * @param mixed $default Default value if key not found
     * @return mixed Configuration value or default
     */
    function config(string $key, mixed $default = null): mixed
    {
        static $config = [];

        $segments = explode('.', $key);
        $file = array_shift($segments);

        // Load config file if not cached
        if (!isset($config[$file])) {
            $config[$file] = loadConfigWithHierarchy($file);
        }

        // Return entire config if no segments left
        if (empty($segments)) {
            return $config[$file];
        }

        // Navigate through segments to get nested value
        $current = $config[$file];
        foreach ($segments as $segment) {
            if (!isset($current[$segment])) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }
}

if (!function_exists('loadConfigWithHierarchy')) {
    /**
     * Load config file with framework + application hierarchy
     */
    function loadConfigWithHierarchy(string $file): array
    {
        $frameworkConfig = [];
        $applicationConfig = [];

        // Get paths from ContainerBootstrap (set during initialization)
        $configPaths = $GLOBALS['config_paths'] ?? [];

        // Load framework defaults first
        if (isset($configPaths['framework'])) {
            $frameworkFile = $configPaths['framework'] . "/{$file}.php";
            if (file_exists($frameworkFile)) {
                $frameworkConfig = require $frameworkFile;
            }
        }

        // Load application config (user overrides/additions)
        if (isset($configPaths['application'])) {
            $applicationFile = $configPaths['application'] . "/{$file}.php";
            if (file_exists($applicationFile)) {
                $applicationConfig = require $applicationFile;
            }
        }

        // Fallback to current behavior if no paths configured
        if (empty($configPaths)) {
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
     * Intelligently merge framework and application configs
     */
    function mergeConfigs(array $framework, array $application): array
    {
        $result = $framework;

        foreach ($application as $key => $value) {
            if (isset($result[$key]) && is_array($result[$key]) && is_array($value)) {
                // Merge arrays (middleware, routes, extensions, etc.)
                $result[$key] = array_merge($result[$key], $value);
            } else {
                // Override scalars (app name, debug, environment, etc.)
                $result[$key] = $value;
            }
        }

        return $result;
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
     * @param string|null $abstract Service class name to resolve
     * @return mixed Container instance or resolved service
     */
    function app(?string $abstract = null): mixed
    {
        $container = $GLOBALS['container'] ?? null;

        if (!$container) {
            throw new \RuntimeException('DI container not initialized. Make sure bootstrap.php is loaded.');
        }

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
     * Returns the Symfony DI container instance using ContainerBootstrap.
     * This provides access to all registered services and parameters.
     *
     * @return \Glueful\DI\Container Container instance
     */
    function container(): \Glueful\DI\Container
    {
        return \Glueful\DI\ContainerBootstrap::initialize();
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
     * @param string $id Service identifier
     * @return mixed Resolved service instance
     */
    function service(string $id): mixed
    {
        return container()->get($id);
    }
}

if (!function_exists('parameter')) {
    /**
     * Get a parameter from the DI container
     *
     * Retrieves a parameter value from the container configuration.
     * Parameters are typically configuration values injected into services.
     *
     * @param string $name Parameter name
     * @return mixed Parameter value
     */
    function parameter(string $name): mixed
    {
        return container()->getParameter($name);
    }
}

if (!function_exists('has_service')) {
    /**
     * Check if a service exists in the container
     *
     * Determines whether the specified service is registered
     * in the DI container without attempting to instantiate it.
     *
     * @param string $id Service identifier
     * @return bool True if service exists
     */
    function has_service(string $id): bool
    {
        return container()->has($id);
    }
}

if (!function_exists('is_production')) {
    /**
     * Check if application is running in production
     *
     * Determines the current environment based on configuration.
     * Used for environment-specific behavior and optimizations.
     *
     * @return bool True if production environment
     */
    function is_production(): bool
    {
        return config('app.env', 'production') === 'production';
    }
}

if (!function_exists('is_debug')) {
    /**
     * Check if debug mode is enabled
     *
     * Determines if the application is running in debug mode.
     * Debug mode enables additional logging, error reporting, and development features.
     *
     * @return bool True if debug mode is enabled
     */
    function is_debug(): bool
    {
        return config('app.debug', false) === true;
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
            'Glueful\\Auth\\TokenStorageService',
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
     * @param string $source Image source path or URL
     * @return \Glueful\Services\ImageProcessorInterface
     */
    function image(string $source): \Glueful\Services\ImageProcessorInterface
    {
        return app(\Glueful\Services\ImageProcessorInterface::class)::make($source);
    }
}

if (!function_exists('storage_path')) {
    /**
     * Get storage directory path
     *
     * Returns the absolute path to the storage directory or a specific file within it.
     * Creates the directory if it doesn't exist.
     *
     * @param string $path Relative path within storage directory
     * @return string Absolute path to storage location
     */
    function storage_path(string $path = ''): string
    {
        $basePath = dirname(__DIR__, 2) . '/storage';

        if (empty($path)) {
            return $basePath;
        }

        $fullPath = $basePath . '/' . ltrim($path, '/');

        // Create directory if it doesn't exist
        $directory = dirname($fullPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
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
     * @param string $path Relative path within resources directory
     * @return string Absolute path to resource location
     */
    function resource_path(string $path = ''): string
    {
        $basePath = dirname(__DIR__, 2) . '/resources';

        if (empty($path)) {
            return $basePath;
        }

        return $basePath . '/' . ltrim($path, '/');
    }
}

if (!function_exists('base_path')) {
    /**
     * Get application base directory path
     *
     * Returns the absolute path to the application root directory or a specific file within it.
     * This is the directory where composer.json and main application files are located.
     *
     * @param string $path Relative path within base directory
     * @return string Absolute path to base directory location
     */
    function base_path(string $path = ''): string
    {
        // Try to get base path from container first
        try {
            $container = app();
            if ($container->hasParameter('app.base_path')) {
                $basePath = $container->getParameter('app.base_path');
            } else {
                // Fallback: assume we're in src/ directory, go up 2 levels
                $basePath = dirname(__DIR__, 1);
            }
        } catch (\Exception) {
            // Final fallback: assume we're in src/ directory, go up 1 level
            $basePath = dirname(__DIR__, 1);
        }

        if (empty($path)) {
            return $basePath;
        }

        return $basePath . '/' . ltrim($path, '/');
    }
}
