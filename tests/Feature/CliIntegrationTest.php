<?php

declare(strict_types=1);

namespace Glueful\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class CliIntegrationTest extends TestCase
{
    private function runCli(array $args, ?string $cwd = null): Process
    {
        // Use dirname to get the framework root directly, avoiding potential pollution from base_path()
        $frameworkRoot = dirname(dirname(__DIR__));
        $bin = $frameworkRoot . '/bin/glueful';
        $process = new Process(array_merge(['php', $bin], $args), $cwd ?? $frameworkRoot);

        // Pass test environment variables to the CLI process
        // Include PATH from current environment to find PHP
        $env = [
            'APP_ENV' => 'testing',
            'CACHE_DRIVER' => 'array',
            'DB_DRIVER' => 'sqlite',
            'DB_SQLITE_DATABASE' => ':memory:',
            'DB_POOLING_ENABLED' => 'false',
            'DB_STARTUP_VALIDATION' => 'false',
            'LOG_TO_FILE' => 'false',
            'LOG_TO_DB' => 'false',
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
        ];

        $process->setEnv($env);

        $process->run();
        return $process;
    }

    public function testCliListShowsAvailableCommands(): void
    {
        $process = $this->runCli(['list']);
        $errorMsg = 'CLI list command failed: ' . $process->getErrorOutput() .
                    "\nOutput: " . $process->getOutput();
        $this->assertTrue($process->isSuccessful(), $errorMsg);
        $out = $process->getOutput();

        $this->assertStringContainsString('serve', $out);
        $this->assertStringContainsString('system:check', $out);
        $this->assertStringContainsString('route', $out);
    }

    public function testCliHelpCommandRuns(): void
    {
        $process = $this->runCli(['help']);
        $errorMsg = 'CLI help command failed: ' . $process->getErrorOutput() .
                    "\nOutput: " . $process->getOutput();
        $this->assertTrue($process->isSuccessful(), $errorMsg);
        $this->assertStringContainsString('Usage:', $process->getOutput());
    }
}
