<?php

declare(strict_types=1);

namespace Glueful\DI\ServiceFactories;

use Glueful\Bootstrap\ConfigurationCache;
use Glueful\Database\Connection;
use Glueful\Database\ConnectionPoolManager;
use Glueful\Database\PooledConnection;

class DatabaseFactory
{
    public static function create(): Connection|PooledConnection
    {
        $config = ConfigurationCache::get('database', []);

        // Use connection pooling if enabled
        $poolEnabled = (bool)($config['pool']['enabled'] ?? false);
        if ($poolEnabled && class_exists(ConnectionPoolManager::class)) {
            $poolManager = new ConnectionPoolManager();
            $defaultEngine = $config['default'] ?? 'mysql';
            $pool = $poolManager->getPool($defaultEngine);
            return $pool->acquire();
        }

        // Create direct connection
        return new Connection($config);
    }
}
