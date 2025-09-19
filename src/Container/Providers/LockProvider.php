<?php

declare(strict_types=1);

namespace Glueful\Container\Providers;

use Glueful\Container\Definition\{DefinitionInterface, FactoryDefinition, AliasDefinition};

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
                $config = function_exists('config') ? (array) config('lock', []) : [];
                $logger = $c->has('logger') ? $c->get('logger') : null;

                $storeType = (string) ($config['default'] ?? 'file');
                $storeCfg = (array) ($config['stores'][$storeType] ?? []);

                $store = match ($storeType) {
                    'redis' => (function () use ($c, $storeCfg) {
                        if (!$c->has(\Redis::class)) {
                            throw new \RuntimeException('Redis service not found');
                        }
                        $redis = $c->get(\Redis::class);
                        return new \Glueful\Lock\Store\RedisLockStore($redis, [
                            'prefix' => $storeCfg['prefix'] ?? 'glueful_lock_',
                            'ttl' => $storeCfg['ttl'] ?? 300,
                        ]);
                    })(),
                    'database' => (function () use ($c, $storeCfg) {
                        if (!$c->has(\Glueful\Database\DatabaseInterface::class)) {
                            throw new \RuntimeException('Database service not found');
                        }
                        $db = $c->get(\Glueful\Database\DatabaseInterface::class);
                        return new \Glueful\Lock\Store\DatabaseLockStore($db, [
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
                                ? base_path('storage')
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
                return new \Glueful\Lock\LockManager($store, $logger, $prefix);
            }
        );

        // Alias
        $defs['lock'] = new AliasDefinition('lock', \Glueful\Lock\LockManagerInterface::class);

        return $defs;
    }
}
