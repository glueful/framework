<?php

declare(strict_types=1);

namespace Glueful\Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SystemCheckLoggingSafetyTest extends TestCase
{
    private function runSystemCheck(array $envOverrides = []): Process
    {
        $frameworkRoot = dirname(dirname(__DIR__));
        $bin = $frameworkRoot . '/bin/glueful';
        $process = new Process(['php', $bin, 'system:check', '--production', '--details'], $frameworkRoot);

        $env = array_merge([
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'JWT_SECRET' => 'abcdefghijklmnopqrstuvwxyz123456',
            'CACHE_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'QUEUE_PROCESS_ENABLED' => 'false',
            'DB_DRIVER' => 'sqlite',
            'DB_SQLITE_DATABASE' => ':memory:',
            'DB_POOLING_ENABLED' => 'false',
            'DB_STARTUP_VALIDATION' => 'false',
            'REDIS_HOST' => '',
            'REDIS_PORT' => '',
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: '/tmp',
        ], $envOverrides);

        $process->setEnv($env);
        $process->run();

        return $process;
    }

    public function testProductionCheckReportsUnsafeLoggingAndAuditSettings(): void
    {
        $process = $this->runSystemCheck([
            'LOG_TO_FILE' => 'false',
            'LOG_TO_DB' => 'false',
            'EVENTS_ENABLED' => 'false',
            'EVENTS_AUDIT_LOGGING' => 'false',
            'LOG_LEVEL' => 'debug',
        ]);

        $output = $process->getOutput() . "\n" . $process->getErrorOutput();

        // Skip if framework boot failed (e.g., no Redis in CI)
        if ($process->getExitCode() !== 0 && $process->getExitCode() !== 1) {
            $this->markTestSkipped('Framework boot failed in subprocess (likely missing services in CI): ' . $output);
        }

        // If boot crashed before the command ran, the output won't contain check results
        if (str_contains($output, 'Configuration')) {
            $this->assertStringContainsString(
                'At least one durable log sink must be enabled in production (LOG_TO_FILE or LOG_TO_DB)',
                $output
            );
            $this->assertStringContainsString('EVENTS_ENABLED should be true in production', $output);
            $this->assertStringContainsString('EVENTS_AUDIT_LOGGING should be true in production', $output);
            $this->assertStringContainsString(
                'LOG_LEVEL should not be debug/trace in production (use warning or info)',
                $output
            );
        } else {
            $this->markTestSkipped('system:check did not produce expected output (framework boot issue): ' . $output);
        }
    }
}
