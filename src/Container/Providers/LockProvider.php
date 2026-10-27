<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Cache\CacheFactory;
use Glueful\Container\Definition\{DefinitionInterface, FactoryDefinition, AliasDefinition};
use Glueful\Database\Connection;
use Glueful\Database\DatabaseInterface;
use Glueful\Lock\LockManager;
use Glueful\Lock\Store\DatabaseLockStore;
use Glueful\Lock\Store\RedisLockStore;

final class LockProvider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        $defs[\Glueful\Lock\LockManagerInterface::class] = new FactoryDefinition(
            \Glueful\Lock\LockManagerInterface::class,
            function (\Psr\Container\ContainerInterface $c) {
                $config = function_exists('config') ? (array) config($this->context, 'lock', []) : [];
                $logger = $c->has('logger') ? $c->get('logger') : null;

                $storeType = (string) ($config['default'] ?? 'file');
                $storeCfg = (array) ($config['stores'][$storeType] ?? []);

                $store = match ($storeType) {
                    'redis' => (function () use ($c, $storeCfg) {
                        // A container-bound client wins (apps that register one); otherwise build
                        // it the way the Redis cache does, from the cache store named by
                        // `connection` ('default' = the framework's cache.stores.redis / REDIS_*).
                        if ($c->has(\Redis::class)) {
                            $redis = $c->get(\Redis::class);
                        } else {
                            $connection = (string) ($storeCfg['connection'] ?? 'default');
                            $store = in_array($connection, ['', 'default'], true) ? 'redis' : $connection;
                            $redis = CacheFactory::createRedisConnection($this->context, $store);
                        }
                        return new RedisLockStore($redis, [
                            'prefix' => $storeCfg['prefix'] ?? 'glueful_lock_',
                            'ttl' => $storeCfg['ttl'] ?? 300,
                        ]);
                    })(),
                    'database' => (function () use ($c, $storeCfg) {
                        return new DatabaseLockStore($this->lockDatabase($c, $storeCfg['connection'] ?? null), [
                            'table' => $storeCfg['table'] ?? 'locks',
                            'id_col' => $storeCfg['id_col'] ?? 'key_id',
                            'token_col' => $storeCfg['token_col'] ?? 'token',
                            'expiration_col' => $storeCfg['expiration_col'] ?? 'expiration',
                        ]);
                    })(),
                    default => (function () use ($storeCfg) {
                        $path = $storeCfg['path'] ?? 'framework/locks';
                        if (!str_starts_with((string) $path, '/')) {
                            $base = function_exists('base_path')
                                ? base_path($this->context, 'storage')
                                : (__DIR__ . '/../../../storage');
                            $path = rtrim($base, '/') . '/' . ltrim((string) $path, '/');
                        }
                        return new \Glueful\Lock\Store\FileLockStore($path, [
                            'prefix' => $storeCfg['prefix'] ?? 'lock_',
                            'extension' => $storeCfg['extension'] ?? '.lock',
                        ]);
                    })(),
                };

                $prefix = (string) ($config['prefix'] ?? 'glueful_lock_');
                $retry = (array) ($config['retry'] ?? []);
                return new LockManager(
                    $store,
                    $logger,
                    $prefix,
                    (float) ($config['ttl'] ?? 300),
                    (int) ($retry['delay'] ?? 100),
                    (float) ($retry['max_wait'] ?? 10)
                );
            }
        );

        // Alias
        $defs['lock'] = new AliasDefinition('lock', \Glueful\Lock\LockManagerInterface::class);

        return $defs;
    }

    /**
     * The database the lock store writes to: the framework's shared connection, or — when the
     * store names a `connection` engine other than the default — a dedicated Connection to it.
     */
    private function lockDatabase(\Psr\Container\ContainerInterface $c, mixed $connection): DatabaseInterface
    {
        $dbConfig = (array) config($this->context, 'database', []);
        $defaultEngine = (string) ($dbConfig['engine'] ?? env('DB_DRIVER', 'sqlite'));

        if (is_string($connection) && $connection !== '' && $connection !== $defaultEngine) {
            return new Connection(array_merge($dbConfig, ['engine' => $connection]), $this->context);
        }

        foreach ([DatabaseInterface::class, 'database'] as $id) {
            if ($c->has($id)) {
                $db = $c->get($id);
                if ($db instanceof DatabaseInterface) {
                    return $db;
                }
            }
        }

        return new Connection($dbConfig, $this->context);
    }
}
