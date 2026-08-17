<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Installer;

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Installer\DatabaseConfig;
use Glueful\Services\FileFinder;
use PHPUnit\Framework\TestCase;

final class MigrationManagerInjectedConnectionTest extends TestCase
{
    public function testUsesTheInjectedConnectionNotFromContext(): void
    {
        $file = sys_get_temp_dir() . '/mm_injected_' . uniqid() . '.sqlite';
        $migrationsDir = sys_get_temp_dir() . '/mm_injected_dir_' . uniqid();
        mkdir($migrationsDir);
        $config = new DatabaseConfig('sqlite', database: $file);
        $connection = new Connection($config->toConnectionConfig());

        // migrate() must ensure the version table on THAT db, i.e. the manager must not
        // resolve a connection from the (null) context. Construction itself performs no
        // database work under the lazy-ledger contract, so the assertion runs after migrate().
        $manager = new MigrationManager($migrationsDir, new FileFinder(), null, $connection);
        $manager->migrate();

        self::assertFileExists($file);
        $tables = $connection->getPDO()
            ->query("SELECT name FROM sqlite_master WHERE type='table'")
            ->fetchAll(\PDO::FETCH_COLUMN);
        self::assertNotEmpty($tables, 'version table should have been created on the injected connection');
        @unlink($file);
        @rmdir($migrationsDir);
    }
}
