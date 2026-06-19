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
        $config = new DatabaseConfig('sqlite', database: $file);
        $connection = new Connection($config->toConnectionConfig());

        // Constructing with an injected connection must ensure the version table on THAT db,
        // i.e. it must not throw resolving a context and the file must exist + carry the table.
        // A migrations path + FileFinder are supplied because the constructor resolves those
        // before ensureVersionTable() and, with a null context, cannot derive them from config.
        new MigrationManager(sys_get_temp_dir(), new FileFinder(), null, $connection);

        self::assertFileExists($file);
        $tables = $connection->getPDO()
            ->query("SELECT name FROM sqlite_master WHERE type='table'")
            ->fetchAll(\PDO::FETCH_COLUMN);
        self::assertNotEmpty($tables, 'version table should have been created on the injected connection');
        @unlink($file);
    }
}
