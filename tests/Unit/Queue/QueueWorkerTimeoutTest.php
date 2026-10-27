<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Queue;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

/**
 * queue:work --timeout was stored in WorkerOptions but never enforced. A job that runs past it
 * is now released (or failed when out of attempts) and the worker exits for its process manager
 * to restart. The worker exits, so each case runs in a child process.
 */
#[RequiresPhpExtension('pcntl')]
final class QueueWorkerTimeoutTest extends TestCase
{
    /**
     * @return array{exit: int, state: array<string, mixed>}
     */
    private function runSlowJob(int $attempts, int $maxAttempts): array
    {
        $root = dirname(__DIR__, 3);
        $script = <<<PHP
            require '{$root}/vendor/autoload.php';
            require_once '{$root}/tests/Unit/Queue/QueueWorkerTest.php';

            use Glueful\\Tests\\Unit\\Queue\\FakeDriver;
            use Glueful\\Tests\\Unit\\Queue\\FakeJob;
            use Glueful\\Tests\\Unit\\Queue\\SpyWorkerMonitor;

            \$job = new FakeJob(attempts: {$attempts}, maxAttempts: {$maxAttempts});
            \$job->fireSleeps = 5;
            \$driver = new FakeDriver([\$job]);
            \$manager = new class (\$driver) extends \\Glueful\\Queue\\QueueManager {
                public function __construct(private \\Glueful\\Queue\\Contracts\\QueueDriverInterface \$d)
                {
                }
                public function connection(?string \$name = null): \\Glueful\\Queue\\Contracts\\QueueDriverInterface
                {
                    return \$this->d;
                }
            };
            register_shutdown_function(function () use (\$job): void {
                echo json_encode([
                    'released' => \$job->releasedWith,
                    'failed' => \$job->failedWith?->getMessage(),
                ]);
            });
            \$worker = new \\Glueful\\Queue\\QueueWorker(\$manager, new SpyWorkerMonitor(), new \\Psr\\Log\\NullLogger());
            \$worker->runOnce('database', ['default'], new \\Glueful\\Queue\\WorkerOptions(sleep: 1, timeout: 1, maxAttempts: 3));
            echo 'NOT-TIMED-OUT';
            PHP;

        $started = microtime(true);
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1', $out, $exit);
        self::assertLessThan(4.5, microtime(true) - $started, 'the 5s job must be cut off at the 1s timeout');

        $output = implode("\n", $out);
        self::assertStringNotContainsString('NOT-TIMED-OUT', $output);
        /** @var array<string, mixed> $state */
        $state = json_decode($output, true) ?? self::fail('unexpected child output: ' . $output);

        return ['exit' => $exit, 'state' => $state];
    }

    public function testJobPastTheTimeoutIsReleasedAndTheWorkerExits(): void
    {
        $result = $this->runSlowJob(attempts: 0, maxAttempts: 3);

        self::assertSame(1, $result['exit']);
        self::assertSame(1, $result['state']['released']);
        self::assertNull($result['state']['failed']);
    }

    public function testJobOutOfAttemptsIsFailedOnTimeout(): void
    {
        $result = $this->runSlowJob(attempts: 3, maxAttempts: 3);

        self::assertSame(1, $result['exit']);
        self::assertSame(0, $result['state']['released']);
        self::assertStringContainsString('worker timeout of 1s', (string) $result['state']['failed']);
    }
}
