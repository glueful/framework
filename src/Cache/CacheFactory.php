<?php

declare(strict_types=1);

namespace Glueful\Cache;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\Drivers\{RedisCacheDriver, MemcachedCacheDriver, ArrayCacheDriver};
use Glueful\Services\FileFinder;
use Redis;
use Memcached;
use Glueful\Http\Exceptions\Domain\BusinessLogicException;
use Glueful\Http\Exceptions\Domain\DatabaseException;

/**
 * Cache Factory
 *
 * Creates and configures cache driver instances based on configuration.
 * Supports Redis and Memcached implementations.
 */
class CacheFactory
{
    /** The application's context, set at boot, for callers that create a cache without one. */
    private static ?ApplicationContext $defaultContext = null;

    public static function setDefaultContext(?ApplicationContext $context): void
    {
        self::$defaultContext = $context;
    }

    /**
     * Create cache driver instance
     *
     * Initializes and configures appropriate cache driver based on config.
     * Handles connection setup and error handling.
     *
     * @param string $driverOverride Optional driver override
     * @param ApplicationContext|null $context Application context for config/container access
     * @return CacheStore<mixed> Configured cache driver
     * @throws DatabaseException If connection fails
     * @throws BusinessLogicException If cache type is not supported
     */
    public static function create(string $driverOverride = '', ?ApplicationContext $context = null): CacheStore
    {
        $context ??= self::$defaultContext;
        // Resolution order: explicit override → config (when context available)
        // → CACHE_DRIVER env var → 'redis' default. The env-var fallback is
        // what lets phpunit.xml's `CACHE_DRIVER=array` take effect for tests
        // that resolve `cache.store` without a context (CI environments
        // without Redis would otherwise fail here).
        $cacheType = $driverOverride !== ''
            ? $driverOverride
            : (string) (self::getConfig($context, 'cache.default', null) ?? env('CACHE_DRIVER', 'redis'));

        if ($cacheType === 'redis') {
            return new RedisCacheDriver(self::createRedisConnection($context));
        }

        if ($cacheType === 'memcached') {
            try {
                $memcached = new Memcached();
                $host = self::getConfig($context, 'cache.stores.memcached.host', null)
                    ?? env('MEMCACHED_HOST', '127.0.0.1');
                $port = (int) (
                    self::getConfig($context, 'cache.stores.memcached.port', null) ?? env('MEMCACHED_PORT', 11211)
                );

                $memcached->addServer($host, $port);

                // Check if server is added successfully
                $serverList = $memcached->getServerList();
                if (count($serverList) === 0) {
                    throw DatabaseException::connectionFailed(
                        "Failed to add Memcached server at {$host}:{$port}"
                    );
                }

                // Check connection with a simple get operation
                $testKey = 'connection_test_' . uniqid();
                $memcached->set($testKey, 'test', 10);
                $testGet = $memcached->get($testKey);
                if ($testGet !== 'test') {
                    throw DatabaseException::connectionFailed(
                        "Memcached connection test failed: " . $memcached->getResultMessage()
                    );
                }

                return new MemcachedCacheDriver($memcached);
            } catch (\Exception $e) {
                throw DatabaseException::connectionFailed(
                    "Memcached connection error: " . $e->getMessage(),
                    $e
                );
            }
        }

        // Support array driver for testing
        if ($cacheType === 'array') {
            return new ArrayCacheDriver();
        }

        // Fall back to file-based caching if enabled
        $fallbackToFile = (bool) self::getConfig($context, 'cache.fallback_to_file', false);
        if ($cacheType === 'file' || ($driverOverride === '' && $fallbackToFile)) {
            return self::createFileDriver($context);
        }

        throw BusinessLogicException::operationNotAllowed(
            'cache_creation',
            "Unsupported cache type: {$cacheType}"
        );
    }

    /**
     * Open the framework's Redis connection from a `cache.stores.<store>` block.
     *
     * The single place a raw phpredis client is built from config, shared by the Redis cache
     * driver and the Redis lock store. Each key falls back to its REDIS_* env var (host, port,
     * password, database, timeout), so a store with no explicit block still connects.
     *
     * @param ApplicationContext|null $context Application context for config access
     * @param string $store Name of the store under `cache.stores` (default: 'redis')
     * @throws DatabaseException If the connection, authentication or ping fails
     */
    public static function createRedisConnection(?ApplicationContext $context = null, string $store = 'redis'): Redis
    {
        $context ??= self::$defaultContext;
        $prefix = 'cache.stores.' . $store . '.';

        $redis = new Redis();
        $host = self::getConfig($context, $prefix . 'host', null) ?? env('REDIS_HOST', '127.0.0.1');
        $port = (int) (self::getConfig($context, $prefix . 'port', null) ?? env('REDIS_PORT', 6379));
        $timeout = (float) (self::getConfig($context, $prefix . 'timeout', null) ?? env('REDIS_TIMEOUT', 2.5));
        $password = self::getConfig($context, $prefix . 'password', null) ?? env('REDIS_PASSWORD');

        try {
            // Set connection timeout to prevent long hangs - use shorter timeout
            $actualTimeout = min($timeout, 1.0); // Max 1 second for local connections
            $connected = $redis->connect($host, $port, $actualTimeout);

            if (!$connected) {
                throw DatabaseException::connectionFailed(
                    "Failed to connect to Redis at {$host}:{$port}"
                );
            }

            // Authenticate if password is set
            if (is_string($password) && $password !== '') {
                $authenticated = $redis->auth($password);
                if (!$authenticated) {
                    throw DatabaseException::connectionFailed(
                        'Redis authentication failed'
                    );
                }
            }

            // Select database if specified
            $database = (int) (self::getConfig($context, $prefix . 'database', null) ?? env('REDIS_DB', 0));
            if ($database > 0) {
                $redis->select($database);
            }

            // Test connection with a ping
            $ping = $redis->ping();
            if ($ping !== "+PONG" && $ping !== true) {
                throw DatabaseException::connectionFailed(
                    "Redis ping failed: " . var_export($ping, true)
                );
            }

            return $redis;
        } catch (\RedisException $e) {
            throw DatabaseException::connectionFailed(
                "Redis connection error: " . $e->getMessage(),
                $e
            );
        }
    }

    /**
     * Create a file-based cache driver as fallback
     *
     * @return CacheStore<mixed> File-based cache driver
     */
    private static function createFileDriver(?ApplicationContext $context = null): CacheStore
    {
        if (!class_exists('\\Glueful\\Cache\\Drivers\\FileCacheDriver')) {
            throw BusinessLogicException::operationNotAllowed(
                'cache_creation',
                'FileCacheDriver class not found'
            );
        }

        $path = self::fileCachePath($context);
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }

        // Build a dedicated StorageManager for the cache directory
        $storageConfig = [
            'default' => 'cache',
            'disks' => [
                'cache' => [
                    'driver' => 'local',
                    'root' => $path,
                    'visibility' => 'private',
                ],
            ],
        ];

        $fileFinder = $context !== null && $context->hasContainer()
            ? container($context)->get(FileFinder::class)
            : new FileFinder();

        $storage = new \Glueful\Storage\StorageManager($storageConfig, new \Glueful\Storage\PathGuard());

        return new \Glueful\Cache\Drivers\FileCacheDriver($path, $storage, $fileFinder, 'cache');
    }

    /**
     * Where the file cache lives: the app's `cache.stores.file.path` (CACHE_FILE_PATH), else
     * `<base>/storage/cache`. Only without an application context does it fall back to the
     * framework's own folder — under vendor/, which the web server often cannot write.
     */
    private static function fileCachePath(?ApplicationContext $context): string
    {
        $configured = self::getConfig($context, 'cache.stores.file.path');
        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/') . '/';
        }
        if ($context !== null) {
            return rtrim($context->getBasePath(), '/') . '/storage/cache/';
        }

        return __DIR__ . '/../../storage/cache/';
    }

    private static function getConfig(?ApplicationContext $context, string $key, mixed $default = null): mixed
    {
        if ($context === null) {
            return $default;
        }

        return config($context, $key, $default);
    }
}
