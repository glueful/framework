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
        // Use fast ConfigurationCache if loaded (modern bootstrap)
        if (\Glueful\Bootstrap\ConfigurationCache::isLoaded()) {
            return \Glueful\Bootstrap\ConfigurationCache::get($key, $default);
        }

        // Legacy repository fallback removed; use direct file loading

        // Final fallback to direct file loading (legacy support)
        static $config = [];
        $segments = explode('.', $key);
        $file = array_shift($segments);

        if (!isset($config[$file])) {
            $config[$file] = loadConfigWithHierarchy($file);
        }

        if (empty($segments)) {
            return $config[$file];
        }

        $current = $config[$file];
        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
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

        // Fallback to framework config if no paths configured (package-relative)
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
     * @param string|null $abstract Service class name to resolve
     * @return mixed Container instance or resolved service
     */
    function app(?string $abstract = null): mixed
    {
        $container = $GLOBALS['container'] ?? null;

        if (!$container) {
            throw new \RuntimeException('DI container not initialized. Make sure framework bootstrap is complete.');
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
     * Returns the global PSR-11 container instance once Framework bootstrap completes.
     * This provides access to all registered services.
     *
     * @return \Psr\Container\ContainerInterface Container instance
     */
    function container(): \Psr\Container\ContainerInterface
    {
        // Simply return the global container - don't try to initialize
        $container = $GLOBALS['container'] ?? null;

        if (!$container) {
            throw new \RuntimeException('DI container not initialized. Framework bootstrap must run first.');
        }

        // Prefer PSR-11 containers;
        if ($container instanceof \Psr\Container\ContainerInterface) {
            return $container;
        }

        throw new \RuntimeException('DI container is not PSR-11 compatible.');
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
        return app($id);
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
        $container = $GLOBALS['container'] ?? null;

        if (!$container) {
            throw new \RuntimeException('DI container not initialized.');
        }

        // Legacy Symfony-style parameter API if available
        if (is_object($container) && method_exists($container, 'getParameter')) {
            try {
                /** @var mixed $val */
                $val = $container->getParameter($name);
                return $val;
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
        if (function_exists('config')) {
            return config($name);
        }

        throw new \RuntimeException("Parameter '{$name}' not found");
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
        $container = $GLOBALS['container'] ?? null;

        if (!$container) {
            return false;
        }

        return $container->has($id);
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
     * @param string $source Image source path or URL
     * @return \Glueful\Services\ImageProcessorInterface
     */
    function image(string $source): \Glueful\Services\ImageProcessorInterface
    {
        $processor = app(\Glueful\Services\ImageProcessorInterface::class);
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
     * @param string $path Relative path within storage directory
     * @return string Absolute path to storage location
     */
    function storage_path(string $path = ''): string
    {
        $base = base_path('storage');

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
     * @param string $path Relative path within resources directory
     * @return string Absolute path to resource location
     */
    function resource_path(string $path = ''): string
    {
        $base = base_path('resources');
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
     * @param string $path Relative path within base directory
     * @return string Absolute path to base directory location
     */
    function base_path(string $path = ''): string
    {
        static $basePath = null;

        // Special reset mechanism for testing
        if ($path === '__RESET__') {
            $basePath = null;
            return '';
        }

        if ($basePath === null) {
            // Priority 1: Explicit global set by bootstrap
            if (isset($GLOBALS['base_path'])) {
                $basePath = $GLOBALS['base_path'];
            }

            // Priority 3: Intelligent path detection
            if ($basePath === null) {
                $basePath = dirname(__DIR__); // Default: framework src -> project root

                // Try to detect if we're in a vendor directory
                $composerPath = $basePath . '/composer.json';
                if (!file_exists($composerPath)) {
                    // We might be in vendor/glueful/framework/src, go up to project root
                    $potentialRoot = dirname(dirname(dirname($basePath)));
                    if (file_exists($potentialRoot . '/composer.json')) {
                        $basePath = $potentialRoot;
                    }
                }
            }
        }

        if (empty($path)) {
            return $basePath;
        }

        return $basePath . '/' . ltrim($path, '/');
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
     * @param string $path Relative path within config directory
     * @return string Absolute path to config file or directory
     */
    function config_path(string $path = ''): string
    {
        static $appConfigPath = null;

        // Special reset mechanism for testing
        if ($path === '__RESET__') {
            $appConfigPath = null;
            return '';
        }

        if ($appConfigPath === null) {
            // Priority 1: Explicit global set by bootstrap
            if (isset($GLOBALS['config_paths']['application'])) {
                $appConfigPath = $GLOBALS['config_paths']['application'];
            }

            // Priority 3: Derive from base_path
            if ($appConfigPath === null) {
                $appConfigPath = base_path('config');
            }
        }

        // If no specific file requested, return app config directory
        if (empty($path)) {
            return $appConfigPath;
        }

        // For specific files, check both app and framework locations
        $frameworkConfigPath = dirname(__DIR__) . '/config';
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
     * @param string|null $guard Guard name (currently unused, for future multi-guard support)
     * @return \Glueful\Auth\AuthenticationGuard|null
     */
    function auth(?string $guard = null): ?\Glueful\Auth\AuthenticationGuard
    {
        try {
            if (has_service(\Glueful\Auth\AuthenticationGuard::class)) {
                return app(\Glueful\Auth\AuthenticationGuard::class);
            }

            // Fallback: create guard from existing services
            if (has_service(\Glueful\Auth\AuthenticationService::class)) {
                return new \Glueful\Auth\AuthenticationGuard(
                    app(\Glueful\Auth\AuthenticationService::class)
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

// ============================================================================
// Async/Await Helper Functions
// ============================================================================

if (!function_exists('scheduler')) {
    /**
     * Get the async scheduler instance from the current request or container
     *
     * Retrieves the FiberScheduler instance for spawning and coordinating async tasks.
     * Attempts to get scheduler from:
     * 1. Current request attributes (if in HTTP request context)
     * 2. DI container (if scheduler service registered)
     * 3. Creates new FiberScheduler instance as fallback
     *
     * @param \Symfony\Component\HttpFoundation\Request|null $request Optional request to extract scheduler from
     * @return \Glueful\Async\Contracts\Scheduler Scheduler instance
     */
    function scheduler(?\Symfony\Component\HttpFoundation\Request $request = null): \Glueful\Async\Contracts\Scheduler
    {
        // Try to get from request attributes first
        if ($request !== null) {
            $scheduler = $request->attributes->get(\Glueful\Async\Middleware\AsyncMiddleware::ATTR_SCHEDULER);
            if ($scheduler instanceof \Glueful\Async\Contracts\Scheduler) {
                return $scheduler;
            }
        }

        // Try to get from DI container
        try {
            if (has_service(\Glueful\Async\Contracts\Scheduler::class)) {
                return app(\Glueful\Async\Contracts\Scheduler::class);
            }
        } catch (\Throwable) {
            // Container not available or scheduler not registered
        }

        // Fallback: create new FiberScheduler instance
        return new \Glueful\Async\FiberScheduler();
    }
}

if (!function_exists('async')) {
    /**
     * Spawn an async task using the scheduler
     *
     * Convenience function to spawn async tasks without explicitly getting the scheduler.
     * Creates a fiber-based task that runs concurrently with other tasks.
     *
     * Example:
     * ```php
     * $task1 = async(fn() => fetchUser(1));
     * $task2 = async(fn() => fetchUser(2));
     * $results = await_all([$task1, $task2]);
     * ```
     *
     * @param callable $fn Async function to execute
     * @param \Glueful\Async\Contracts\CancellationToken|null $token Optional cancellation token
     * @param \Symfony\Component\HttpFoundation\Request|null $request Optional request to extract scheduler from
     * @return \Glueful\Async\Contracts\Task Spawned task instance
     */
    function async(
        callable $fn,
        ?\Glueful\Async\Contracts\CancellationToken $token = null,
        ?\Symfony\Component\HttpFoundation\Request $request = null
    ): \Glueful\Async\Contracts\Task {
        return scheduler($request)->spawn($fn, $token);
    }
}

if (!function_exists('await')) {
    /**
     * Wait for a task to complete and return its result
     *
     * Blocks until the task completes and returns the result.
     * Throws exception if task failed.
     *
     * Example:
     * ```php
     * $task = async(fn() => fetchUser(1));
     * $user = await($task);
     * ```
     *
     * @param \Glueful\Async\Contracts\Task $task Task to wait for
     * @return mixed Task result
     * @throws \Throwable If task failed
     */
    function await(\Glueful\Async\Contracts\Task $task): mixed
    {
        return $task->getResult();
    }
}

if (!function_exists('await_all')) {
    /**
     * Wait for all tasks to complete and return their results
     *
     * Blocks until all tasks complete. Returns array of results preserving keys.
     * If any task fails, waits for all to complete then throws the first exception.
     *
     * Example:
     * ```php
     * $tasks = [
     *     async(fn() => fetchUser(1)),
     *     async(fn() => fetchUser(2)),
     *     async(fn() => fetchUser(3)),
     * ];
     * $users = await_all($tasks);
     * ```
     *
     * @param array<int|string, \Glueful\Async\Contracts\Task> $tasks Tasks to wait for
     * @param \Symfony\Component\HttpFoundation\Request|null $request Optional request to extract scheduler from
     * @return array<int|string, mixed> Array of results
     * @throws \Throwable If any task failed
     */
    function await_all(array $tasks, ?\Symfony\Component\HttpFoundation\Request $request = null): array
    {
        return scheduler($request)->all($tasks);
    }
}

if (!function_exists('await_race')) {
    /**
     * Wait for the first task to complete and return its result
     *
     * Blocks until at least one task completes. Returns the result of the first
     * completed task. Other tasks continue running in the background.
     *
     * Example:
     * ```php
     * $tasks = [
     *     async(fn() => fetchFromPrimaryDb()),
     *     async(fn() => fetchFromSecondaryDb()),
     *     async(fn() => fetchFromCache()),
     * ];
     * $result = await_race($tasks); // Returns fastest result
     * ```
     *
     * @param array<int|string, \Glueful\Async\Contracts\Task> $tasks Tasks to race
     * @param \Symfony\Component\HttpFoundation\Request|null $request Optional request to extract scheduler from
     * @return mixed Result of first completed task
     * @throws \Throwable If all tasks failed
     */
    function await_race(array $tasks, ?\Symfony\Component\HttpFoundation\Request $request = null): mixed
    {
        return scheduler($request)->race($tasks);
    }
}

if (!function_exists('async_sleep')) {
    /**
     * Async sleep that yields control to other tasks
     *
     * Suspends the current task for the specified duration without blocking
     * the entire event loop. Other tasks can continue executing.
     *
     * Example:
     * ```php
     * async(function() {
     *     echo "Starting...\n";
     *     async_sleep(1.0); // Sleep for 1 second
     *     echo "Done!\n";
     * });
     * ```
     *
     * @param float $seconds Number of seconds to sleep
     * @param \Glueful\Async\Contracts\CancellationToken|null $token Optional cancellation token
     * @param \Symfony\Component\HttpFoundation\Request|null $request Optional request to extract scheduler from
     * @return void
     */
    function async_sleep(
        float $seconds,
        ?\Glueful\Async\Contracts\CancellationToken $token = null,
        ?\Symfony\Component\HttpFoundation\Request $request = null
    ): void {
        scheduler($request)->sleep($seconds, $token);
    }
}

if (!function_exists('cancellation_token')) {
    /**
     * Create a new cancellation token
     *
     * Creates a mutable cancellation token that can be passed to async operations
     * to enable cooperative cancellation.
     *
     * Example:
     * ```php
     * $token = cancellation_token();
     * $task = async(fn() => longRunningOperation($token), $token);
     *
     * // Later, cancel the operation
     * $token->cancel();
     * ```
     *
     * @return \Glueful\Async\SimpleCancellationToken Cancellation token
     */
    function cancellation_token(): \Glueful\Async\SimpleCancellationToken
    {
        return new \Glueful\Async\SimpleCancellationToken();
    }
}
