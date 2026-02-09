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
    /**
     * Create cache driver instance
     *
     * Initializes and configures appropriate cache driver based on config.
     * Handles connection setup and error handling.
     *
     * @param string $driverOverride Optional driver override
     * @param ApplicationContext|null $context Application context for config/container access
     * @return CacheStore<mixed> Configured cache driver
     * @throws \Glueful\Http\Exceptions\Domain\DatabaseException If connection fails
     * @throws \Glueful\Http\Exceptions\Domain\BusinessLogicException If cache type is not supported
     */
    public static function create(string $driverOverride = '', ?ApplicationContext $context = null): CacheStore
    {
        $cacheType = $driverOverride !== ''
            ? $driverOverride
            : (string) self::getConfig($context, 'cache.default', 'redis');

        if ($cacheType === 'redis') {
            $redis = new Redis();
            $host = self::getConfig($context, 'cache.stores.redis.host', null) ?? env('REDIS_HOST', '127.0.0.1');
            $port = (int) (self::getConfig($context, 'cache.stores.redis.port', null) ?? env('REDIS_PORT', 6379));
            $timeout = (float) (
                self::getConfig($context, 'cache.stores.redis.timeout', null) ?? env('REDIS_TIMEOUT', 2.5)
            );
            $password = self::getConfig($context, 'cache.stores.redis.password', null) ?? env('REDIS_PASSWORD');

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
                $database = (int) (
                    self::getConfig($context, 'cache.stores.redis.database', null) ?? env('REDIS_DB', 0)
                );
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

                return new RedisCacheDriver($redis);
            } catch (\RedisException $e) {
                throw DatabaseException::connectionFailed(
                    "Redis connection error: " . $e->getMessage(),
                    $e
                );
            }
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

        $path = self::getConfig($context, 'app.paths.storage_path', __DIR__ . '/../../storage') . '/cache/';
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

        $fileFinder = $context !== null
            ? container($context)->get(FileFinder::class)
            : new FileFinder();

        $storage = new \Glueful\Storage\StorageManager($storageConfig, new \Glueful\Storage\PathGuard());

        return new \Glueful\Cache\Drivers\FileCacheDriver($path, $storage, $fileFinder, 'cache');
    }

    private static function getConfig(?ApplicationContext $context, string $key, mixed $default = null): mixed
    {
        if ($context === null) {
            return $default;
        }

        return config($context, $key, $default);
    }
}
