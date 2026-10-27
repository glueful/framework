<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Console\Commands\System;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\Commands\System\CheckCommand;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * system:check --production validated JWT_SECRET, a variable nothing reads; the signing key is
 * JWT_KEY (config/session.php). A correctly configured app was told to set the wrong variable.
 */
final class CheckCommandJwtKeyTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/glueful-syscheck-' . uniqid('', true);
        mkdir($this->base, 0777, true);
        file_put_contents($this->base . '/.env', "APP_ENV=production\n");
    }

    protected function tearDown(): void
    {
        putenv('JWT_KEY');
        putenv('JWT_SECRET');
        exec('rm -rf ' . escapeshellarg($this->base));
    }

    /** @return list<string> */
    private function configurationDetails(): array
    {
        $context = new ApplicationContext($this->base, 'production');
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new class ("no {$id}") extends \RuntimeException implements
                    \Psr\Container\NotFoundExceptionInterface {
                };
            }

            public function has(string $id): bool
            {
                return false;
            }
        };
        $command = new CheckCommand($container, $context);

        $method = new \ReflectionMethod($command, 'checkConfiguration');
        /** @var array{details?: list<string>} $result */
        $result = $method->invoke($command, true);

        return $result['details'] ?? [];
    }

    public function testAStrongJwtKeySatisfiesTheProductionCheck(): void
    {
        putenv('JWT_KEY=' . str_repeat('k', 32));
        putenv('JWT_SECRET');

        $details = implode("\n", $this->configurationDetails());

        self::assertStringNotContainsString('JWT_SECRET', $details);
        self::assertStringNotContainsString('JWT_KEY', $details);
    }

    public function testAMissingJwtKeyIsReportedByItsRealName(): void
    {
        putenv('JWT_KEY');
        putenv('JWT_SECRET=' . str_repeat('s', 32)); // the old name must not count

        $details = implode("\n", $this->configurationDetails());

        self::assertStringContainsString('JWT_KEY must be set and at least 32 characters', $details);
    }
}
