<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Installer;

use Glueful\Installer\DatabaseConfig;
use PHPUnit\Framework\TestCase;

final class DatabaseConfigTest extends TestCase
{
    public function testPgsqlConnectionConfigUsesInternalKeysAndDisablesPooling(): void
    {
        $c = new DatabaseConfig(
            engine: 'pgsql',
            host: 'db.example',
            port: 5432,
            database: 'lemma',
            username: 'lemma_user',
            password: 'secret',
            schema: 'app',
            sslMode: 'require',
        );

        $cfg = $c->toConnectionConfig();
        self::assertSame('pgsql', $cfg['engine']);
        self::assertFalse($cfg['pooling']['enabled']);
        self::assertSame('db.example', $cfg['pgsql']['host']);
        self::assertSame('lemma', $cfg['pgsql']['db']);
        self::assertSame('lemma_user', $cfg['pgsql']['user']);
        self::assertSame('secret', $cfg['pgsql']['pass']);
        self::assertSame('app', $cfg['pgsql']['schema']);
        self::assertSame('require', $cfg['pgsql']['sslmode']);
    }

    public function testPgsqlEnvPairsUsePrefixedKeysAndOmitEmptyOptionals(): void
    {
        $c = new DatabaseConfig('pgsql', 'h', 5432, 'd', 'u', 'p'); // no schema/sslMode
        $pairs = $c->toEnvPairs();

        self::assertSame('pgsql', $pairs['DB_DRIVER']);
        self::assertSame('h', $pairs['DB_PGSQL_HOST']);
        self::assertSame('5432', $pairs['DB_PGSQL_PORT']);
        self::assertSame('d', $pairs['DB_PGSQL_DATABASE']);
        self::assertArrayNotHasKey('DB_PGSQL_SCHEMA', $pairs);
        self::assertArrayNotHasKey('DB_PGSQL_SSL_MODE', $pairs);
    }

    public function testSqliteMapsToPrimaryAndSingleEnvKey(): void
    {
        $c = new DatabaseConfig('sqlite', database: '/tmp/x.sqlite');
        self::assertSame('/tmp/x.sqlite', $c->toConnectionConfig()['sqlite']['primary']);
        self::assertSame(
            ['DB_DRIVER' => 'sqlite', 'DB_SQLITE_DATABASE' => '/tmp/x.sqlite'],
            $c->toEnvPairs(),
        );
    }

    public function testConnectTimeoutIsIncludedOnlyWhenRequested(): void
    {
        $c = new DatabaseConfig('pgsql', 'h', 5432, 'd', 'u', 'p');

        // No timeout by default (the migration build) — and it never reaches the persisted env.
        self::assertArrayNotHasKey('timeout', $c->toConnectionConfig()['pgsql']);

        // Short timeout when the tester asks for one.
        self::assertSame(3, $c->toConnectionConfig(3)['pgsql']['timeout']);

        $mysql = new DatabaseConfig('mysql', 'h', 3306, 'd', 'u', 'p');
        self::assertSame(3, $mysql->toConnectionConfig(3)['mysql']['timeout']);

        // Never persisted to .env.
        self::assertArrayNotHasKey('DB_PGSQL_TIMEOUT', $c->toEnvPairs());
    }
}
