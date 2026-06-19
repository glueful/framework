<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Installer;

use Glueful\Installer\ConnectionTester;
use Glueful\Installer\DatabaseConfig;
use PHPUnit\Framework\TestCase;

final class ConnectionTesterTest extends TestCase
{
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
