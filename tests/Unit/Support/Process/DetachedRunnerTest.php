<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Support\Process;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Support\Process\DetachedRunner;
use PHPUnit\Framework\TestCase;

final class DetachedRunnerTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->rmrf($dir);
        }
    }

    public function test_buildArgv_is_pure_array_and_targets_the_runner(): void
    {
        $runner = new DetachedRunner($this->context());
        $argv = $runner->buildArgv('job123', 'glueful/aegis');

        $this->assertContains('extensions:install-run', $argv);
        $this->assertContains('glueful/aegis', $argv);
        $this->assertContains('job123', $argv);
        // PHP binary sits two before the command name (works with or without setsid).
        $idx = (int) array_search('extensions:install-run', $argv, true);
        $this->assertSame(PHP_BINARY, $argv[$idx - 2]);
    }

    public function test_toShellCommand_escapes_every_segment(): void
    {
        $hostile = 'a b; rm -rf /';
        $cmd = DetachedRunner::toShellCommand(['php', 'glueful', 'extensions:install-run', 'j', $hostile]);

        $this->assertStringContainsString(escapeshellarg($hostile), $cmd);
        // Nothing outside the escaped token leaks the metacharacters.
        $remainder = str_replace(escapeshellarg($hostile), '', $cmd);
        $this->assertStringNotContainsString('; rm -rf /', $remainder);
    }

    public function test_spawnInstall_returns_when_injected_spawn_returns(): void
    {
        $seen = null;
        $spy = function (array $argv, string $cwd, string $log) use (&$seen): void {
            $seen = ['argv' => $argv, 'cwd' => $cwd, 'log' => $log];
        };
        $runner = new DetachedRunner($this->context(), $spy);
        $runner->spawnInstall('job1', 'glueful/aegis');

        $this->assertNotNull($seen);
        $this->assertContains('glueful/aegis', $seen['argv']);
        $this->assertStringContainsString('ext-install-job1.log', $seen['log']);
    }

    public function test_real_detached_spawn_returns_before_child_completes(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open unavailable');
        }
        $marker = sys_get_temp_dir() . '/mark_' . bin2hex(random_bytes(4));
        $log = sys_get_temp_dir() . '/log_' . bin2hex(random_bytes(4));

        // Real detached child: sleeps 400ms, then writes the marker. Pure argv, no shell.
        $t0 = microtime(true);
        DetachedRunner::detachedSpawn(
            [PHP_BINARY, '-r', 'usleep(400000); file_put_contents($argv[1], "x");', $marker],
            sys_get_temp_dir(),
            $log,
        );
        $elapsed = microtime(true) - $t0;

        // Non-blocking: returned well before the child's 400ms sleep elapsed...
        $this->assertLessThan(0.3, $elapsed, 'detachedSpawn must return before the child finishes');
        $this->assertFileDoesNotExist($marker, 'child should still be running when spawn returns');

        // ...and the detached child completes independently afterward.
        $deadline = microtime(true) + 3.0;
        while (!file_exists($marker) && microtime(true) < $deadline) {
            usleep(20000);
        }
        $this->assertFileExists($marker, 'detached child ran to completion after spawn returned');
        @unlink($marker);
        @unlink($log);
    }

    private function context(): ApplicationContext
    {
        $base = sys_get_temp_dir() . '/dr_' . bin2hex(random_bytes(6));
        mkdir($base, 0755, true);
        $this->tempDirs[] = $base;
        return ApplicationContext::forTesting($base);
    }

    private function rmrf(string $dir): void
    {
        if (!file_exists($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmrf($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
