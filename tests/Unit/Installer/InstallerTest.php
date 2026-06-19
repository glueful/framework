<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Installer;

use Glueful\Installer\DatabaseConfig;
use Glueful\Installer\Installer;
use Glueful\Installer\InstallOptions;
use Glueful\Installer\InstallStep;
use PHPUnit\Framework\TestCase;

final class InstallerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/installer_' . uniqid();
        mkdir($this->dir, 0775, true);
        mkdir($this->dir . '/migrations', 0775, true); // empty: migrate() runs 0 migrations, still creates the version table
        file_put_contents($this->dir . '/.env.example', "APP_ENV=local\nAPP_KEY=\n");
    }

    protected function tearDown(): void
    {
        @array_map('unlink', glob($this->dir . '/*') ?: []);
        @array_map('unlink', glob($this->dir . '/*.sqlite') ?: []);
        @rmdir($this->dir);
    }

    public function testFailedDbPreflightLeavesNoEnv(): void
    {
        // Invariant #1, sharp version: NO .env present beforehand; a bad DB config aborts before
        // .env is created; .env still does not exist afterward.
        self::assertFileDoesNotExist($this->dir . '/.env', 'precondition: a fresh project has no .env');

        $bad = new DatabaseConfig('pgsql', host: '127.0.0.1', port: 1, database: 'x', username: 'u', password: 'p');
        $installer = new Installer($this->dir, skipCacheAndValidation: true, migrationsPath: $this->dir . '/migrations');

        $result = $installer->run(new InstallOptions(database: $bad, skipKeys: true));

        self::assertFalse($result->ok);
        self::assertFileDoesNotExist($this->dir . '/.env', 'failed preflight must not create .env');
        self::assertSame(InstallStep::FAILED, $result->steps[0]->status);
        self::assertSame('database-preflight', $result->steps[0]->name);
    }

    public function testSuccessfulInstallMigratesTheTestedDatabase(): void
    {
        // Invariant #2: migrations land in the DatabaseConfig's sqlite file, not the default.
        $dbFile = $this->dir . '/installed.sqlite';
        $config = new DatabaseConfig('sqlite', database: $dbFile);
        $installer = new Installer($this->dir, skipCacheAndValidation: true, migrationsPath: $this->dir . '/migrations');

        $result = $installer->run(new InstallOptions(database: $config, skipKeys: false));

        self::assertTrue($result->ok, json_encode(array_map(
            static fn (InstallStep $s): array => [$s->name, $s->status, $s->message],
            $result->steps,
        )));
        self::assertFileExists($this->dir . '/.env');
        self::assertFileExists($dbFile);
        self::assertStringContainsString('DB_SQLITE_DATABASE=', file_get_contents($this->dir . '/.env'));

        // Sharp invariant #2: the version table landed in the TESTED sqlite file (proves the
        // injected connection was migrated, not the default). VERSION_TABLE = 'migrations'.
        $tables = (new \PDO("sqlite:{$dbFile}"))
            ->query("SELECT name FROM sqlite_master WHERE type='table' AND name='migrations'")
            ->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('migrations', $tables);
    }
}
