<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Security;

use Glueful\Security\SecurityManager;
use PHPUnit\Framework\TestCase;

/**
 * The production validation recommended FORCE_HTTPS and HSTS_HEADER, two settings nothing in the
 * framework reads: following the advice changed nothing. It also judged only DB_PASSWORD, the MySQL
 * variable, so a PostgreSQL site with an empty password passed and a strong one was flagged.
 */
final class ProductionValidationHttpsTest extends TestCase
{
    private const KEYS = [
        'APP_ENV', 'APP_DEBUG', 'FORCE_HTTPS', 'HSTS_HEADER', 'DB_DRIVER', 'DB_PASSWORD', 'DB_PGSQL_PASSWORD',
    ];

    /** @var array<string, string|null> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::KEYS as $k) {
            $this->saved[$k] = $_ENV[$k] ?? null;
            unset($_ENV[$k]);
        }
        $_ENV['APP_ENV'] = 'production';
        $_ENV['APP_DEBUG'] = 'false';
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $k => $v) {
            if ($v === null) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
    }

    /** @return list<string> */
    private function mentions(string $needle): array
    {
        $v = SecurityManager::validateProductionEnvironment();

        return array_values(array_filter(
            array_merge($v['warnings'], $v['recommendations']),
            static fn (string $r): bool => str_contains($r, $needle),
        ));
    }

    public function testSettingsNothingReadsAreNeverRecommended(): void
    {
        $_ENV['FORCE_HTTPS'] = 'false';

        self::assertSame([], $this->mentions('FORCE_HTTPS'));
        self::assertSame([], $this->mentions('HSTS_HEADER'));
    }

    public function testTheActiveEnginesPasswordIsTheOneJudged(): void
    {
        $_ENV['DB_DRIVER'] = 'pgsql';
        $_ENV['DB_PGSQL_PASSWORD'] = '';
        self::assertNotSame([], $this->mentions('DB_PGSQL_PASSWORD'), 'an empty PostgreSQL password is weak');

        $_ENV['DB_PGSQL_PASSWORD'] = 'a-long-and-random-password-9f2c';
        self::assertSame([], $this->mentions('password'), 'a strong one passes, whatever DB_PASSWORD holds');
    }

    public function testSqliteHasNoPasswordToJudge(): void
    {
        $_ENV['DB_DRIVER'] = 'sqlite';

        self::assertSame([], $this->mentions('password'));
    }
}
