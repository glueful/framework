<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceFactories;

use Glueful\Bootstrap\ConfigurationCache;
use Glueful\Cache\Drivers\RedisCacheDriver;
use Glueful\Cache\Drivers\MemcachedCacheDriver;
use Glueful\Cache\Drivers\ArrayCacheDriver;
use Psr\SimpleCache\CacheInterface;

class CacheStoreFactory
{
    public static function create(): CacheInterface
    {
        $config = ConfigurationCache::get('cache', []);

        return match ($config['driver'] ?? 'array') {
            'redis' => new RedisCacheDriver($config['redis'] ?? []),
            'memcached' => new MemcachedCacheDriver($config['memcached'] ?? []),
            default => new ArrayCacheDriver()
        };
    }
}
