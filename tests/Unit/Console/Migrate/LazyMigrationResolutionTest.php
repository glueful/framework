<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Console\Migrate;

use Glueful\Console\Commands\Migrate\RollbackCommand;
use Glueful\Console\Commands\Migrate\RunCommand;
use Glueful\Console\Commands\Migrate\StatusCommand;
use Glueful\Database\Migrations\MigrationManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The migrate commands must not resolve `MigrationManager` at CONSTRUCTION time.
 *
 * `MigrationManager::__construct()` opens a database connection and runs
 * `ensureVersionTable()` DDL. The console registers every command at boot, so an eager
 * resolution in a command constructor made EVERY invocation — `glueful list` included —
 * require a reachable, writable database: precisely the state a first-run provisioning
 * command exists to repair. Surfaced by a clean-machine first-run install audit
 * (fresh checkout, `.env` database not yet created ⇒ every console command dead on boot).
 *
 * The pin: a container whose `MigrationManager` entry THROWS. Construction must succeed
 * (nothing resolved); first use must be what fails.
 */
final class LazyMigrationResolutionTest extends TestCase
{
    /** @return ContainerInterface a container that refuses MigrationManager resolution */
    private function refusingContainer(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === MigrationManager::class) {
                    throw new \RuntimeException('database unreachable (simulated)');
                }
                throw new \RuntimeException("unexpected resolution: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === MigrationManager::class;
            }
        };
    }

    /** @return array<string, array{class-string}> */
    public static function commands(): array
    {
        return [
            'run' => [RunCommand::class],
            'rollback' => [RollbackCommand::class],
            'status' => [StatusCommand::class],
        ];
    }

    /** @dataProvider commands */
    public function test_construction_never_touches_the_database(string $commandClass): void
    {
        $command = new $commandClass($this->refusingContainer());

        self::assertInstanceOf($commandClass, $command);
    }

    /** @dataProvider commands */
    public function test_resolution_happens_on_first_use_not_registration(string $commandClass): void
    {
        $command = new $commandClass($this->refusingContainer());

        $accessor = new \ReflectionMethod($command, 'migrations');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('database unreachable (simulated)');
        $accessor->invoke($command);
    }
}
