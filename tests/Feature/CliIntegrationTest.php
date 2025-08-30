<?php

declare(strict_types=1);

namespace Glueful\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class CliIntegrationTest extends TestCase
{
    private function runCli(array $args, ?string $cwd = null): Process
    {
        $bin = base_path('bin/glueful');
        $process = new Process(array_merge(['php', $bin], $args), $cwd ?? base_path());
        $process->run();
        return $process;
    }

    public function testCliListShowsAvailableCommands(): void
    {
        $process = $this->runCli(['list']);
        $this->assertTrue($process->isSuccessful(), 'CLI list command failed: ' . $process->getErrorOutput());
        $out = $process->getOutput();

        $this->assertStringContainsString('serve', $out);
        $this->assertStringContainsString('system:check', $out);
        $this->assertStringContainsString('route', $out);
    }

    public function testCliHelpCommandRuns(): void
    {
        $process = $this->runCli(['help']);
        $this->assertTrue($process->isSuccessful(), 'CLI help command failed: ' . $process->getErrorOutput());
        $this->assertStringContainsString('Usage:', $process->getOutput());
    }
}
