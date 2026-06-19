<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Installer;

use Glueful\Database\Connection;
use Glueful\Installer\ConnectionTester;
use Glueful\Installer\DatabaseConfig;
use PHPUnit\Framework\TestCase;

final class ConnectionTesterTest extends TestCase
{
    public function testPgsqlDsnCarriesConnectTimeoutWhenSet(): void
    {
        // The pgsql connect timeout must land in the DSN (pdo_pgsql ignores PDO::ATTR_TIMEOUT for connect).
        $conn = new Connection((new DatabaseConfig('sqlite', database: ':memory:'))->toConnectionConfig());
        $build = new \ReflectionMethod(Connection::class, 'buildDSN');
        $build->setAccessible(true);

        $with = $build->invoke($conn, 'pgsql', ['host' => 'h', 'port' => 5432, 'db' => 'd', 'timeout' => 2]);
        $without = $build->invoke($conn, 'pgsql', ['host' => 'h', 'port' => 5432, 'db' => 'd']);

        self::assertStringContainsString('connect_timeout=2', $with);
        self::assertStringNotContainsString('connect_timeout', $without);
    }

    public function testUnreachableHostFailsFastInsteadOfHanging(): void
    {
        // 192.0.2.1 = RFC 5737 TEST-NET-1 (unrouted/blackholed). With a 1s connect timeout the
        // probe must return quickly — well under the ~75s OS default — instead of hanging a wizard.
        $config = new DatabaseConfig(
            engine: 'pgsql',
            host: '192.0.2.1',
            port: 5432,
            database: 'd',
            username: 'u',
            password: 'p',
        );

        $start = microtime(true);
        $result = (new ConnectionTester(connectTimeout: 1))->test($config);
        $elapsed = microtime(true) - $start;

        self::assertFalse($result->ok);
        self::assertLessThan(20.0, $elapsed, 'must fast-fail via the connect timeout, not hang on the OS default');
    }

    public function testOkAgainstAReachableSqliteFile(): void
    {
        $file = sys_get_temp_dir() . '/ct_ok_' . uniqid() . '.sqlite';
        $config = new DatabaseConfig('sqlite', database: $file);

        $result = (new ConnectionTester())->test($config);

        self::assertTrue($result->ok, $result->message);
        self::assertSame('sqlite', $result->engine);
        @unlink($file);
    }

    public function testFailsWithDiagnosticsAndNoPasswordOnBadCreds(): void
    {
        $config = new DatabaseConfig(
            engine: 'pgsql',
            host: '127.0.0.1',
            port: 1,            // nothing listens here -> fast refuse
            database: 'nope',
            username: 'u',
            password: 'sup3r-secret-pw',
        );

        $result = (new ConnectionTester())->test($config);

        self::assertFalse($result->ok);
        self::assertNotNull($result->exceptionClass);
        self::assertStringNotContainsString('sup3r-secret-pw', $result->message);
    }
}
