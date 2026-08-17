<?php

declare(strict_types=1);

namespace Glueful\Extensions\Schema;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;

/**
 * The ONE driver selector for migration locks (schema policy spec B4): pgsql → advisory locks,
 * mysql → named locks, otherwise flock. CoreProvider's binding and the framework Installer both
 * come through here, so an injected installer connection can never drift onto a different lock
 * backend than the container's.
 */
final class MigrationLockFactory
{
    private function __construct()
    {
    }

    public static function forConnection(
        Connection $connection,
        ?ApplicationContext $context = null,
    ): MigrationLockInterface {
        return self::forDriver($connection->getDriverName(), $connection, self::lockDir($context));
    }

    public static function forDriver(string $driver, Connection $connection, string $lockDir): MigrationLockInterface
    {
        return match ($driver) {
            'pgsql' => new PgsqlAdvisoryMigrationLock($connection),
            'mysql' => new MysqlNamedMigrationLock($connection),
            default => new FileMigrationLock($lockDir),
        };
    }

    private static function lockDir(?ApplicationContext $context): string
    {
        if ($context !== null && \function_exists('base_path')) {
            return base_path($context, 'storage/framework/locks');
        }
        return sys_get_temp_dir() . '/glueful-schema-locks';
    }
}
