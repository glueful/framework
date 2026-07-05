<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Console\Commands\Extensions;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\Drivers\ArrayCacheDriver;
use Glueful\Console\Commands\Extensions\InstallRunCommand;
use Glueful\Extensions\Install\InstallJobStore;
use Glueful\Support\Process\ProcessRunner;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class InstallRunCommandTest extends TestCase
{
    private ?string $prevComposerEnv = null;

    protected function setUp(): void
    {
        // Composer resolution must succeed so execute() reaches the runner.
        $this->prevComposerEnv = getenv('COMPOSER_BINARY') ?: null;
        putenv('COMPOSER_BINARY=' . PHP_BINARY);
    }

    protected function tearDown(): void
    {
        $this->prevComposerEnv === null
            ? putenv('COMPOSER_BINARY')
            : putenv('COMPOSER_BINARY=' . $this->prevComposerEnv);
    }

    public function test_success_path_runs_composer_then_fresh_enable_and_finishes_succeeded(): void
    {
        $store = new InstallJobStore(new ArrayCacheDriver());
        $jobId = $store->create('glueful/aegis');
        $calls = [];
        $runner = new FakeProcessRunner(function (array $cmd) use (&$calls): array {
            $calls[] = $cmd;
            if (in_array('require', $cmd, true)) {
                return ['exitCode' => 0, 'output' => "Installing\n"];
            }
            return ['exitCode' => 0, 'output' => json_encode(['ok' => true, 'provider' => 'X']) . "\n"];
        });

        $this->runCommand($store, $runner, $jobId, 'glueful/aegis');

        $this->assertSame('succeeded', $store->get($jobId)['status']);
        $this->assertContains('require', $calls[0]);                      // composer first
        $this->assertContains('extensions:enable-installed', $calls[1]);  // fresh enable second
        $this->assertNull($store->get($jobId)['enableError']);
    }

    public function test_composer_failure_short_circuits_before_enable(): void
    {
        $store = new InstallJobStore(new ArrayCacheDriver());
        $jobId = $store->create('glueful/aegis');
        $calls = [];
        $runner = new FakeProcessRunner(function (array $cmd) use (&$calls): array {
            $calls[] = $cmd;
            return ['exitCode' => 1, 'output' => "Could not resolve\n"];
        });

        $this->runCommand($store, $runner, $jobId, 'glueful/aegis');

        $this->assertSame('failed', $store->get($jobId)['status']);
        $this->assertCount(1, $calls); // enable never ran
    }

    public function test_composer_ok_but_enable_fails_ends_installed_not_enabled(): void
    {
        $store = new InstallJobStore(new ArrayCacheDriver());
        $jobId = $store->create('glueful/aegis');
        $runner = new FakeProcessRunner(function (array $cmd): array {
            if (in_array('require', $cmd, true)) {
                return ['exitCode' => 0, 'output' => "Installing\n"];
            }
            return ['exitCode' => 1, 'output' => json_encode(['ok' => false, 'error' => 'missing dependency']) . "\n"];
        });

        $this->runCommand($store, $runner, $jobId, 'glueful/aegis');

        $rec = $store->get($jobId);
        $this->assertSame('installed_not_enabled', $rec['status']); // NOT failed, NOT plain succeeded
        $this->assertSame('missing dependency', $rec['enableError']);
    }

    private function runCommand(InstallJobStore $store, ProcessRunner $runner, string $jobId, string $package): void
    {
        $context = ApplicationContext::forTesting(sys_get_temp_dir());
        $container = new class ($context, $store) implements ContainerInterface {
            public function __construct(private ApplicationContext $context, private InstallJobStore $store)
            {
            }

            public function get(string $id): mixed
            {
                return match ($id) {
                    ApplicationContext::class => $this->context,
                    InstallJobStore::class => $this->store,
                    default => throw new class ("No {$id}") extends \RuntimeException implements
                        \Psr\Container\NotFoundExceptionInterface {},
                };
            }

            public function has(string $id): bool
            {
                return in_array($id, [ApplicationContext::class, InstallJobStore::class], true);
            }
        };

        $command = new InstallRunCommand($container, $context, $runner);
        (new CommandTester($command))->execute(['jobId' => $jobId, 'package' => $package]);
    }
}

/**
 * Scripts ProcessRunner responses by a callback; records nothing itself (callers
 * capture cmds via the callback closure).
 */
final class FakeProcessRunner implements ProcessRunner
{
    /** @param \Closure(list<string>):array{exitCode:int,output:string} $handler */
    public function __construct(private \Closure $handler)
    {
    }

    public function run(array $cmd, string $cwd, float $timeout, ?callable $onOutput = null): array
    {
        $result = ($this->handler)($cmd);
        if ($onOutput !== null && $result['output'] !== '') {
            $onOutput($result['output']);
        }
        return $result;
    }
}
