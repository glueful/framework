<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Database\Driver;

use Glueful\Database\Driver\MySQLDriver;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * config/database.php's mysql.ssl block must become PDO MySQL SSL attributes.
 */
final class MySQLSslOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('PDO::MYSQL_ATTR_SSL_CA')) {
            self::markTestSkipped('pdo_mysql is not loaded');
        }
    }

    public function testEnabledSslMapsCertificatesAndVerification(): void
    {
        $options = MySQLDriver::sslOptions([
            'enabled' => true,
            'ca_cert' => '/certs/ca.pem',
            'client_cert' => '/certs/client.pem',
            'client_key' => '/certs/client.key',
            'verify_cert' => false,
        ]);

        self::assertSame([
            PDO::MYSQL_ATTR_SSL_CA => '/certs/ca.pem',
            PDO::MYSQL_ATTR_SSL_CERT => '/certs/client.pem',
            PDO::MYSQL_ATTR_SSL_KEY => '/certs/client.key',
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
        ], $options);
    }

    public function testDisabledSslAddsNothing(): void
    {
        self::assertSame([], MySQLDriver::sslOptions([
            'enabled' => false,
            'ca_cert' => '/certs/ca.pem',
        ]));
        self::assertSame([], MySQLDriver::sslOptions([]));
    }

    public function testEnabledWithoutAnyCertificateAddsNothing(): void
    {
        // No CA/cert/key configured: nothing to hand PDO, and forcing verification alone would
        // change how an existing (production-default "enabled") connection negotiates.
        self::assertSame([], MySQLDriver::sslOptions([
            'enabled' => true,
            'ca_cert' => null,
            'client_cert' => '',
            'verify_cert' => true,
        ]));
    }

    public function testStringBooleansFromEnvAreHonoured(): void
    {
        $options = MySQLDriver::sslOptions([
            'enabled' => 'true',
            'ca_cert' => '/certs/ca.pem',
            'verify_cert' => 'false',
        ]);

        self::assertSame([
            PDO::MYSQL_ATTR_SSL_CA => '/certs/ca.pem',
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
        ], $options);
    }
}
