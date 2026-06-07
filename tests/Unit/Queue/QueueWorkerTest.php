<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Queue;

use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Framework;
use Glueful\Queue\Contracts\JobInterface;
use Glueful\Queue\Contracts\QueueDriverInterface;
use Glueful\Queue\Contracts\WorkerMonitorInterface;
use Glueful\Queue\QueueManager;
use Glueful\Queue\QueueWorker;
use Glueful\Queue\WorkerOptions;
use Glueful\Routing\RouteManifest;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;

/**
 * WS2 Task 2a: exhaustive coverage of the lean QueueWorker — the single
 * job-draining loop in the framework. Ports WorkCommand::executeProcess()
 * semantics 1:1.
 *
 * Real-driver cases run against a file-based SQLite DatabaseQueue harness
 * (pooling off so the worker's Connection::fromContext() and the test's
 * verification connection share the db by path). Fine-grained release/fail/
 * throwable cases use a fake JobInterface for full control over attempts and
 * the thrown type; the --connection case uses a mocked QueueManager.
 */
final class QueueWorkerTest extends TestCase
{
    private string $appPath;
    private string $dbFile;
    private Application $app;
    private ApplicationContext $context;

    protected function setUp(): void
    {
        RouteManifest::reset();

        $this->appPath = sys_get_temp_dir() . '/glueful-qworker-' . uniqid('', true);
        $this->dbFile = $this->appPath . '/queue.sqlite';
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);

        file_put_contents($cfg . '/app.php', "<?php\nreturn ['name' => 'T', 'version_full' => '1.0.0', 'env' => 'testing', 'debug' => true];\n");
        file_put_contents(
            $cfg . '/database.php',
            "<?php\nreturn ['engine' => 'sqlite', 'sqlite' => ['primary' => '" . $this->dbFile . "'], "
            . "'pooling' => ['enabled' => false]];\n"
        );
        file_put_contents($cfg . '/cache.php', "<?php\nreturn ['enabled' => true, 'default' => 'array', 'stores' => ['array' => ['driver' => 'array']]];\n");
        file_put_contents($cfg . '/security.php', "<?php\nreturn ['csrf' => ['enabled' => false]];\n");
        file_put_contents($cfg . '/session.php', "<?php\nreturn ['jwt_key' => 'test'];\n");
        file_put_contents(
            $cfg . '/queue.php',
            "<?php\nreturn ['default' => 'database', 'connections' => ['database' => ['driver' => 'database', "
            . "'table' => 'queue_jobs', 'failed_table' => 'queue_failed_jobs', 'retry_after' => 90]]];\n"
        );

        $this->app = Framework::create($this->appPath)->boot(allowReboot: true);
        $this->context = $this->app->getContext();

        $this->createQueueSchema();
    }

    protected function tearDown(): void
    {
        if (isset($this->appPath) && is_dir($this->appPath)) {
            $this->recursiveRemoveDirectory($this->appPath);
        }
        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Real-driver cases
    // ---------------------------------------------------------------------

    public function testRunOnceProcessesSuccessJobAndRemovesItFromQueue(): void
    {
        TestSuccessJob::$ran = 0;
        $manager = $this->manager();
        $manager->push(TestSuccessJob::class, ['n' => 1], 'default');

        $spy = new SpyWorkerMonitor();
        $worker = new QueueWorker($manager, $spy, new NullLogger(), $this->context);

        $ran = $worker->runOnce('database', ['default'], $this->options());

        self::assertTrue($ran, 'runOnce should report a job ran');
        self::assertSame(1, TestSuccessJob::$ran, 'job fire() should have executed exactly once');
        self::assertSame(0, $this->queueSize('default'), 'job should be removed from the queue');
    }

    public function testRunOnceReturnsFalseWhenQueueEmpty(): void
    {
        $manager = $this->manager();
        $worker = new QueueWorker($manager, new SpyWorkerMonitor(), new NullLogger(), $this->context);

        self::assertFalse($worker->runOnce('database', ['default'], $this->options()));
    }

    public function testRunOnceProcessesAtMostOneJob(): void
    {
        TestSuccessJob::$ran = 0;
        $manager = $this->manager();
        $manager->push(TestSuccessJob::class, ['n' => 1], 'default');
        $manager->push(TestSuccessJob::class, ['n' => 2], 'default');

        $worker = new QueueWorker($manager, new SpyWorkerMonitor(), new NullLogger(), $this->context);
        $worker->runOnce('database', ['default'], $this->options());

        self::assertSame(1, TestSuccessJob::$ran, 'runOnce must process at most one job');
        self::assertSame(1, $this->queueSize('default'), 'the second job must remain queued');
    }

    public function testDaemonDrainsQueueWithStopWhenEmpty(): void
    {
        TestSuccessJob::$ran = 0;
        $manager = $this->manager();
        $manager->push(TestSuccessJob::class, ['n' => 1], 'default');
        $manager->push(TestSuccessJob::class, ['n' => 2], 'default');
        $manager->push(TestSuccessJob::class, ['n' => 3], 'default');

        $worker = new QueueWorker($manager, new SpyWorkerMonitor(), new NullLogger(), $this->context);
        $exit = $worker->daemon('database', ['default'], $this->options(['stopWhenEmpty' => true]));

        self::assertSame(QueueWorker::SUCCESS, $exit);
        self::assertSame(3, TestSuccessJob::$ran);
        self::assertSame(0, $this->queueSize('default'));
    }

    public function testDaemonStopsAfterMaxJobs(): void
    {
        TestSuccessJob::$ran = 0;
        $manager = $this->manager();
        for ($i = 0; $i < 5; $i++) {
            $manager->push(TestSuccessJob::class, ['n' => $i], 'default');
        }

        $worker = new QueueWorker($manager, new SpyWorkerMonitor(), new NullLogger(), $this->context);
        $exit = $worker->daemon('database', ['default'], $this->options(['maxJobs' => 2, 'stopWhenEmpty' => true]));

        self::assertSame(QueueWorker::SUCCESS, $exit);
        self::assertSame(2, TestSuccessJob::$ran, 'daemon must stop after maxJobs');
        self::assertSame(3, $this->queueSize('default'), 'remaining jobs stay queued');
    }

    public function testMaxJobsZeroRunsUnbounded(): void
    {
        // maxJobs = 0 means unlimited; bound the test with stopWhenEmpty.
        TestSuccessJob::$ran = 0;
        $manager = $this->manager();
        for ($i = 0; $i < 4; $i++) {
            $manager->push(TestSuccessJob::class, ['n' => $i], 'default');
        }

        $worker = new QueueWorker($manager, new SpyWorkerMonitor(), new NullLogger(), $this->context);
        $worker->daemon('database', ['default'], $this->options(['maxJobs' => 0, 'stopWhenEmpty' => true]));

        self::assertSame(4, TestSuccessJob::$ran, 'maxJobs=0 must not cap processing');
    }

    public function testMaxRuntimeZeroDoesNotImmediatelyStop(): void
    {
        // maxRuntime = 0 means unlimited; with stopWhenEmpty it still drains.
        TestSuccessJob::$ran = 0;
        $manager = $this->manager();
        $manager->push(TestSuccessJob::class, ['n' => 1], 'default');

        $worker = new QueueWorker($manager, new SpyWorkerMonitor(), new NullLogger(), $this->context);
        $worker->daemon('database', ['default'], $this->options(['maxRuntime' => 0, 'stopWhenEmpty' => true]));

        self::assertSame(1, TestSuccessJob::$ran, 'maxRuntime=0 must not stop before draining');
    }

    public function testMonitoringRecordsStartBeforeSuccessForSameUuid(): void
    {
        TestSuccessJob::$ran = 0;
        $manager = $this->manager();
        $manager->push(TestSuccessJob::class, ['n' => 1], 'default');

        $spy = new SpyWorkerMonitor();
        $worker = new QueueWorker($manager, $spy, new NullLogger(), $this->context);
        $worker->runOnce('database', ['default'], $this->options());

        self::assertCount(2, $spy->calls);
        self::assertSame('recordJobStart', $spy->calls[0][0]);
        self::assertSame('recordJobSuccess', $spy->calls[1][0]);
        self::assertSame(
            $spy->calls[0][1],
            $spy->calls[1][1],
            'start and success must reference the same job_uuid'
        );
    }

    // ---------------------------------------------------------------------
    // Fake-job cases (fine-grained release / fail / throwable control)
    // ---------------------------------------------------------------------

    public function testThrowingJobBelowMaxIsReleasedWithComputedBackoff(): void
    {
        $job = new FakeJob(attempts: 0, maxAttempts: 3);
        $job->fireThrows = new \RuntimeException('boom');

        $driver = new FakeDriver([$job]);
        $manager = $this->fakeManager($driver);

        $spy = new SpyWorkerMonitor();
        $worker = new QueueWorker($manager, $spy, new NullLogger(), $this->context);
        $worker->runOnce('database', ['default'], $this->options(['sleep' => 7, 'maxAttempts' => 3]));

        self::assertSame(1, $job->releasedWith, 'released delay count');
        // The booted app merges the framework's default queue.php which carries an
        // exponential backoff (base 2): base * 2^attempts(0) = 2.
        self::assertSame(2, $job->releaseDelay, 'release uses the computed backoff delay');
        self::assertNull($job->failedWith, 'must not be marked failed below the attempts ceiling');
        self::assertSame('recordJobFailure', $spy->calls[1][0]);
    }

    public function testBackoffFallsBackToFlatSleepWithoutContext(): void
    {
        // No ApplicationContext → computeBackoff returns flat $options->sleep,
        // preserving the original WorkCommand::executeProcess() semantics.
        $job = new FakeJob(attempts: 0, maxAttempts: 3);
        $job->fireThrows = new \RuntimeException('boom');

        $driver = new FakeDriver([$job]);
        $manager = $this->fakeManager($driver);

        $worker = new QueueWorker($manager, new SpyWorkerMonitor(), new NullLogger(), null);
        $worker->runOnce('database', ['default'], $this->options(['sleep' => 7, 'maxAttempts' => 3]));

        self::assertSame(7, $job->releaseDelay, 'flat-sleep fallback when no context is available');
    }

    public function testThrowingJobAtMaxAttemptsIsFailedNotReleased(): void
    {
        // attempts already at the ceiling: min(maxAttempts=2, options.maxAttempts=3) = 2.
        $job = new FakeJob(attempts: 2, maxAttempts: 2);
        $job->fireThrows = new \RuntimeException('dead');

        $driver = new FakeDriver([$job]);
        $manager = $this->fakeManager($driver);

        $worker = new QueueWorker($manager, new SpyWorkerMonitor(), new NullLogger(), $this->context);
        $worker->runOnce('database', ['default'], $this->options(['maxAttempts' => 3]));

        self::assertSame(0, $job->releasedWith, 'must not be released at the ceiling');
        self::assertInstanceOf(\RuntimeException::class, $job->failedWith);
        self::assertSame('dead', $job->failedWith->getMessage());
    }

    public function testNonExceptionThrowableIsWrappedAndSameInstanceReachesMonitor(): void
    {
        $job = new FakeJob(attempts: 1, maxAttempts: 1);
        $job->fireThrows = new \Error('fatal-error');

        $driver = new FakeDriver([$job]);
        $manager = $this->fakeManager($driver);

        $spy = new SpyWorkerMonitor();
        $worker = new QueueWorker($manager, $spy, new NullLogger(), $this->context);
        $worker->runOnce('database', ['default'], $this->options(['maxAttempts' => 1]));

        // failed() received a RuntimeException wrapping the Error.
        self::assertInstanceOf(\RuntimeException::class, $job->failedWith);
        self::assertSame('fatal-error', $job->failedWith->getMessage());
        self::assertInstanceOf(\Error::class, $job->failedWith->getPrevious());

        // The SAME wrapped instance was passed to recordJobFailure.
        self::assertSame($job->failedWith, $spy->failureException, 'identical wrapped instance to monitor');
    }

    public function testReleaseUsesExponentialBackoffWhenConfigured(): void
    {
        // Write a fresh app whose queue config carries a backoff strategy.
        $ctx = $this->bootWithBackoffConfig();

        $job = new FakeJob(attempts: 2, maxAttempts: 5);
        $job->fireThrows = new \RuntimeException('boom');

        $driver = new FakeDriver([$job]);
        $manager = $this->fakeManager($driver);

        $worker = new QueueWorker($manager, new SpyWorkerMonitor(), new NullLogger(), $ctx);
        $worker->runOnce('database', ['default'], $this->options(['sleep' => 3, 'maxAttempts' => 5]));

        // exponential: base(2) * 2^attempts(2) = 2 * 4 = 8.
        self::assertSame(8, $job->releaseDelay, 'exponential backoff: base * 2^attempts');
    }

    // ---------------------------------------------------------------------
    // --connection targeting
    // ---------------------------------------------------------------------

    public function testDaemonResolvesRequestedConnectionFromManager(): void
    {
        $driver = new FakeDriver([]); // empty → daemon exits immediately with stopWhenEmpty
        $manager = $this->createMock(QueueManager::class);
        $manager->expects(self::once())
            ->method('connection')
            ->with('reporting')
            ->willReturn($driver);

        $worker = new QueueWorker($manager, new SpyWorkerMonitor(), new NullLogger(), $this->context);
        $worker->daemon('reporting', ['default'], $this->options(['stopWhenEmpty' => true]));
    }

    public function testRunOnceResolvesRequestedConnectionFromManager(): void
    {
        $driver = new FakeDriver([]);
        $manager = $this->createMock(QueueManager::class);
        $manager->expects(self::once())
            ->method('connection')
            ->with('reporting')
            ->willReturn($driver);

        $worker = new QueueWorker($manager, new SpyWorkerMonitor(), new NullLogger(), $this->context);
        self::assertFalse($worker->runOnce('reporting', ['default'], $this->options()));
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /** @param array<string,mixed> $overrides */
    private function options(array $overrides = []): WorkerOptions
    {
        return new WorkerOptions(
            sleep: $overrides['sleep'] ?? 1,
            memory: $overrides['memory'] ?? 128,
            timeout: $overrides['timeout'] ?? 60,
            maxJobs: $overrides['maxJobs'] ?? 0,
            stopWhenEmpty: $overrides['stopWhenEmpty'] ?? false,
            maxAttempts: $overrides['maxAttempts'] ?? 3,
            maxRuntime: $overrides['maxRuntime'] ?? 0,
        );
    }

    private function manager(): QueueManager
    {
        /** @var QueueManager $manager */
        $manager = $this->app->getContainer()->get(QueueManager::class);
        return $manager;
    }

    private function fakeManager(QueueDriverInterface $driver): QueueManager
    {
        $manager = $this->createMock(QueueManager::class);
        $manager->method('connection')->willReturn($driver);
        return $manager;
    }

    private function createQueueSchema(): void
    {
        $connection = Connection::fromContext($this->context);
        $schema = $connection->getSchemaBuilder();

        require_once dirname(__DIR__, 3) . '/migrations/queue/001_CreateQueueSystemTables.php';
        $migration = new \Glueful\Migrations\Queue\CreateQueueSystemTables();
        $migration->up($schema);
    }

    private function queueSize(string $queue): int
    {
        $connection = Connection::fromContext($this->context);
        return (int) $connection->table('queue_jobs')->where('queue', $queue)->count();
    }

    private function bootWithBackoffConfig(): ApplicationContext
    {
        RouteManifest::reset();
        $path = sys_get_temp_dir() . '/glueful-qworker-bo-' . uniqid('', true);
        $cfg = $path . '/config';
        mkdir($cfg, 0755, true);
        file_put_contents($cfg . '/app.php', "<?php\nreturn ['name' => 'T', 'version_full' => '1.0.0', 'env' => 'testing', 'debug' => true];\n");
        file_put_contents($cfg . '/database.php', "<?php\nreturn ['engine' => 'sqlite', 'sqlite' => ['primary' => ':memory:'], 'pooling' => ['enabled' => false]];\n");
        file_put_contents($cfg . '/cache.php', "<?php\nreturn ['enabled' => true, 'default' => 'array', 'stores' => ['array' => ['driver' => 'array']]];\n");
        file_put_contents($cfg . '/security.php', "<?php\nreturn ['csrf' => ['enabled' => false]];\n");
        file_put_contents($cfg . '/session.php', "<?php\nreturn ['jwt_key' => 'test'];\n");
        file_put_contents(
            $cfg . '/queue.php',
            "<?php\nreturn ['default' => 'database', 'workers' => ['performance' => "
            . "['backoff_strategy' => 'exponential', 'backoff_base' => 2, 'max_backoff' => 3600]]];\n"
        );
        $app = Framework::create($path)->boot(allowReboot: true);
        // Track for cleanup via the primary appPath teardown is not enough; remove now-unused tree on tear-down.
        $this->extraPaths[] = $path;
        return $app->getContext();
    }

    /** @var array<int, string> */
    private array $extraPaths = [];

    private function recursiveRemoveDirectory(string $dir): void
    {
        foreach (array_merge([$dir], $this->extraPaths) as $target) {
            if (!is_dir($target)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($target, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($target);
        }
    }
}

/**
 * Job that records its executions.
 */
final class TestSuccessJob
{
    public static int $ran = 0;

    /** @param array<string,mixed> $data */
    public function handle(array $data): void
    {
        self::$ran++;
    }
}

/**
 * Spy WorkerMonitor recording call order + job_uuids.
 */
final class SpyWorkerMonitor implements WorkerMonitorInterface
{
    /** @var array<int, array{0:string,1:?string}> */
    public array $calls = [];
    public ?\Exception $failureException = null;

    public function registerWorker(string $workerUuid, array $workerData): void
    {
        $this->calls[] = ['registerWorker', $workerUuid];
    }

    public function updateWorkerHeartbeat(string $workerUuid, array $data): void
    {
        $this->calls[] = ['updateWorkerHeartbeat', $workerUuid];
    }

    public function unregisterWorker(string $workerUuid, array $finalStats = []): void
    {
        $this->calls[] = ['unregisterWorker', $workerUuid];
    }

    public function recordJobStart(JobInterface $job): void
    {
        $this->calls[] = ['recordJobStart', $job->getUuid()];
    }

    public function recordJobSuccess(JobInterface $job, float $processingTime): void
    {
        $this->calls[] = ['recordJobSuccess', $job->getUuid()];
    }

    public function recordJobFailure(JobInterface $job, \Exception $exception, float $processingTime): void
    {
        $this->calls[] = ['recordJobFailure', $job->getUuid()];
        $this->failureException = $exception;
    }

    public function getActiveWorkers(): array
    {
        return [];
    }

    public function cleanupOldWorkers(int $daysOld = 7): bool
    {
        return false;
    }

    public function cleanupOldMetrics(int $daysOld = 30): bool
    {
        return false;
    }

    public function isEnabled(): bool
    {
        return false;
    }
}

/**
 * Fake job with full control over attempts and the thrown type.
 */
final class FakeJob implements JobInterface
{
    public ?\Throwable $fireThrows = null;
    public int $releasedWith = 0;
    public int $releaseDelay = 0;
    public ?\Exception $failedWith = null;
    private string $uuid;
    private ?QueueDriverInterface $driver = null;

    public function __construct(private int $attempts, private int $maxAttempts)
    {
        $this->uuid = 'fake-' . uniqid('', true);
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getQueue(): ?string
    {
        return 'default';
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getPayload(): array
    {
        return [];
    }

    public function fire(): void
    {
        if ($this->fireThrows !== null) {
            throw $this->fireThrows;
        }
    }

    public function release(int $delay = 0): void
    {
        $this->releasedWith++;
        $this->releaseDelay = $delay;
    }

    public function delete(): void
    {
    }

    public function failed(\Exception $exception): void
    {
        $this->failedWith = $exception;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function getTimeout(): int
    {
        return 60;
    }

    public function getBatchUuid(): ?string
    {
        return null;
    }

    public function shouldRetry(): bool
    {
        return $this->attempts < $this->maxAttempts;
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function setAttempts(int $attempts): void
    {
        $this->attempts = $attempts;
    }

    public function getDescription(): string
    {
        return 'FakeJob';
    }

    public function getDriver(): ?QueueDriverInterface
    {
        return $this->driver;
    }

    public function setDriver(QueueDriverInterface $driver): void
    {
        $this->driver = $driver;
    }
}

/**
 * Fake driver returning a fixed sequence of jobs from pop().
 */
final class FakeDriver implements QueueDriverInterface
{
    /** @param array<int, JobInterface> $jobs */
    public function __construct(private array $jobs)
    {
    }

    public function pop(?string $queue = null): ?JobInterface
    {
        return array_shift($this->jobs);
    }

    public function getDriverInfo(): \Glueful\Queue\Contracts\DriverInfo
    {
        throw new \BadMethodCallException('not used');
    }

    public function initialize(array $config): void
    {
    }

    public function push(string $job, array $data = [], ?string $queue = null): string
    {
        return 'noop';
    }

    public function later(int $delay, string $job, array $data = [], ?string $queue = null): string
    {
        return 'noop';
    }

    public function release(JobInterface $job, int $delay = 0): void
    {
    }

    public function delete(JobInterface $job): void
    {
    }

    public function size(?string $queue = null): int
    {
        return count($this->jobs);
    }

    public function getFeatures(): array
    {
        return [];
    }

    public function getConfigSchema(): array
    {
        return [];
    }

    public function bulk(array $jobs, ?string $queue = null): array
    {
        return [];
    }

    public function failed(JobInterface $job, \Exception $exception): void
    {
    }

    public function purge(?string $queue = null): int
    {
        return 0;
    }

    public function getStats(?string $queue = null): array
    {
        return [];
    }

    public function healthCheck(): \Glueful\Queue\Contracts\HealthStatus
    {
        throw new \BadMethodCallException('not used');
    }
}
