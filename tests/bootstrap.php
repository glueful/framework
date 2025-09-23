<?php

// PHPUnit bootstrap for Glueful framework
// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

declare(strict_types=1);

// Container will be initialized after autoloader is available

// Ensure Composer autoloader is available
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
];

$loaded = false;
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    fwrite(STDERR, "Composer autoload not found. Run 'composer install'.\n");
    exit(1);
}

// Now setup the proper container with mocked services
try {
    // Create a minimal container for testing with mocked services
    $mockLogger = new class {
        public function info($message, $context = [])
        {
        }
        public function error($message, $context = [])
        {
        }
        public function warning($message, $context = [])
        {
        }
        public function debug($message, $context = [])
        {
        }
    };

    $container = new \Glueful\Container\Container();
    $container->load([
        \Glueful\Logging\LogManager::class => new \Glueful\Container\Definition\ValueDefinition(
            \Glueful\Logging\LogManager::class,
            $mockLogger
        )
    ]);
    $GLOBALS['container'] = $container;
} catch (\Throwable $e) {
    // If container can't be created, create a mock one
    $GLOBALS['container'] = new class implements \Psr\Container\ContainerInterface {
        public function get(string $id)
        {
            // Return mock services for common dependencies
            if ($id === \Glueful\Logging\LogManager::class) {
                return new class {
                    public function info($message, $context = [])
                    {
                    }
                    public function error($message, $context = [])
                    {
                    }
                    public function warning($message, $context = [])
                    {
                    }
                    public function debug($message, $context = [])
                    {
                    }
                };
            }
            return null;
        }

        public function has(string $id): bool
        {
            return $id === \Glueful\Logging\LogManager::class;
        }
    };
}

// Set environment variables for tests
$_ENV['APP_ENV'] = 'testing';
$_ENV['DB_HOST'] = 'localhost';
$_ENV['DB_DATABASE'] = 'glueful_test';

// Create test directories
$testDirs = [
    __DIR__ . '/../storage/cache',
    __DIR__ . '/../storage/logs',
    sys_get_temp_dir() . '/test_backups'
];

foreach ($testDirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

// Mock basic framework functions for unit tests
if (!function_exists('config')) {
    function config($key, $default = null)
    {
        $configs = [
            'database.driver' => 'mysql',
            'database.host' => 'localhost',
            'database.database' => 'test_db',
            'database.username' => 'root',
            'database.password' => '',
            'app.paths.backups' => sys_get_temp_dir() . '/test_backups',
            'app.paths.logs' => __DIR__ . '/../storage/logs',
            'app.paths.cache' => __DIR__ . '/../storage/cache'
        ];
        return $configs[$key] ?? $default;
    }
}

if (!function_exists('base_path')) {
    function base_path($path = '')
    {
        return __DIR__ . '/..' . ($path ? '/' . ltrim($path, '/') : '');
    }
}

if (!function_exists('app')) {
    function app($service = null)
    {
        // Use the same container as the global one
        $container = $GLOBALS['container'] ?? null;

        if (!$container) {
            // Fallback to a mock container if needed
            $container = new class implements \Psr\Container\ContainerInterface {
                public function get(string $id)
                {
                    return null;
                }

                public function has(string $id): bool
                {
                    return false;
                }
            };
        }

        if ($service === null) {
            return $container;
        }

        return $container->get($service);
    }
}

// Put ExceptionHandler in test mode when available
if (class_exists(Glueful\Exceptions\ExceptionHandler::class)) {
      Glueful\Exceptions\ExceptionHandler::setTestMode(true);
}

// Set default timezone for consistent test results
date_default_timezone_set('UTC');

// Reduce noise during tests
ini_set('display_errors', '1');
ini_set('error_reporting', (string) (E_ALL));
