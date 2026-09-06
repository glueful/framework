<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Bootstrap;

use Glueful\Bootstrap\BootProfiler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The boot profiler's phase-breakdown dump is opt-in and best-effort: nothing is written
 * unless a dump path is configured, and a dump that cannot be written never aborts boot.
 * (Before this, every boot wrote a hard-coded /tmp/boot_profile.log — a shared path that a
 * second OS user on the same host could not write, which turned a profiler nicety into a
 * fatal ErrorException under the framework's error handler.)
 */
final class BootProfilerTest extends TestCase
{
    private const LEGACY_PATH = '/tmp/boot_profile.log';

    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    public function testSummaryWritesNothingWhenNoDumpPathIsConfigured(): void
    {
        $before = is_file(self::LEGACY_PATH) ? md5_file(self::LEGACY_PATH) : null;

        $profiler = $this->profilerWithOnePhase();
        $profiler->logSummary();

        $after = is_file(self::LEGACY_PATH) ? md5_file(self::LEGACY_PATH) : null;
        self::assertSame($before, $after, 'logSummary() must not touch the legacy /tmp path');
    }

    public function testSummaryDumpsThePhaseBreakdownToTheConfiguredPath(): void
    {
        $path = $this->tempPath();

        $profiler = $this->profilerWithOnePhase();
        $profiler->setDumpPath($path);
        $profiler->logSummary();

        self::assertFileExists($path);
        $dump = (string) file_get_contents($path);
        self::assertStringContainsString('Total Boot Time', $dump);
        self::assertStringContainsString('environment', $dump);
    }

    public function testAnUnwritableDumpPathNeverThrows(): void
    {
        // Mirror the framework's ExceptionHandler, which promotes PHP warnings to exceptions.
        set_error_handler(static function (int $errno, string $errstr): bool {
            throw new \ErrorException($errstr, 0, $errno);
        });

        try {
            $profiler = $this->profilerWithOnePhase();
            $profiler->setDumpPath('/nonexistent-dir-' . uniqid() . '/boot_profile.log');
            $profiler->logSummary();
        } finally {
            restore_error_handler();
        }

        $this->addToAssertionCount(1); // reaching here is the assertion
    }

    /**
     * @dataProvider disabledEnvValues
     */
    public function testDumpPathFromEnvIsNullWhenDisabled(mixed $value): void
    {
        self::assertNull(BootProfiler::dumpPathFromEnv($value));
    }

    /** @return iterable<string, array{mixed}> */
    public static function disabledEnvValues(): iterable
    {
        yield 'unset' => [null];
        yield 'false' => [false];
        yield 'empty string' => [''];
        yield 'zero' => ['0'];
    }

    public function testDumpPathFromEnvUsesAPerUserTempFileWhenMerelyEnabled(): void
    {
        $path = BootProfiler::dumpPathFromEnv(true);

        self::assertNotNull($path);
        self::assertStringStartsWith(sys_get_temp_dir(), $path);
        self::assertStringContainsString('glueful-boot-profile-' . getmyuid(), $path);
    }

    public function testDumpPathFromEnvHonoursAnExplicitPath(): void
    {
        self::assertSame('/var/tmp/custom-boot.log', BootProfiler::dumpPathFromEnv('/var/tmp/custom-boot.log'));
    }

    private function profilerWithOnePhase(): BootProfiler
    {
        $profiler = new BootProfiler();
        $profiler->setLogger(new NullLogger()); // keep error_log() fallback out of test output
        $profiler->time('environment', static fn(): int => 1);

        return $profiler;
    }

    private function tempPath(): string
    {
        $path = sys_get_temp_dir() . '/glueful-boot-profile-test-' . uniqid() . '.log';
        $this->cleanup[] = $path;

        return $path;
    }
}
