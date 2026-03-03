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
            'DB_DRIVER' => 'sqlite',
            'DB_SQLITE_DATABASE' => ':memory:',
            'DB_POOLING_ENABLED' => 'false',
            'DB_STARTUP_VALIDATION' => 'false',
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
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
    }
}

