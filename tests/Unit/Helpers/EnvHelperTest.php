<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Helpers;

use PHPUnit\Framework\TestCase;

/**
 * env() must see a variable however it reached the process. PHP populates $_ENV only when
 * variables_order includes "E" (the php.ini defaults do not), and Dotenv's immutable loader
 * skips any key already present in the real environment — so under a CI job or a container
 * that exports DB_* and APP_* as real environment variables, reading $_ENV alone returned the
 * defaults (a Postgres install silently fell to sqlite for whatever connected before the
 * installer published its credentials).
 */
final class EnvHelperTest extends TestCase
{
    private const KEY = 'GLUEFUL_ENV_PROBE';

    protected function tearDown(): void
    {
        unset($_ENV[self::KEY], $_SERVER[self::KEY]);
        putenv(self::KEY);
    }

    public function testAVariableOnlyInTheRealEnvironmentIsSeen(): void
    {
        unset($_ENV[self::KEY], $_SERVER[self::KEY]);
        putenv(self::KEY . '=from-real-env');

        self::assertSame('from-real-env', env(self::KEY));
        self::assertFalse(env(self::KEY . '_ABSENT', false));
    }

    public function testDollarEnvWinsOverTheRealEnvironment(): void
    {
        putenv(self::KEY . '=real');
        $_ENV[self::KEY] = 'dotenv';

        self::assertSame('dotenv', env(self::KEY));
    }

    public function testCastingAppliesToRealEnvironmentValuesToo(): void
    {
        unset($_ENV[self::KEY], $_SERVER[self::KEY]);
        putenv(self::KEY . '=false');

        self::assertFalse(env(self::KEY, true));
    }

    public function testAnAbsentVariableReturnsTheDefault(): void
    {
        self::assertSame('dflt', env(self::KEY, 'dflt'));
    }
}
