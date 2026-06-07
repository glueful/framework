<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Queue;

use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Framework;
use Glueful\Queue\Contracts\WorkerMonitorInterface;
use Glueful\Queue\Jobs\QueueMaintenance;
use Glueful\Queue\Monitoring\NullWorkerMonitor;
use Glueful\Queue\QueueManager;
use Glueful\Queue\QueueWorker;
use Glueful\Queue\WorkerOptions;
use Glueful\Routing\RouteManifest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * WS3 Task 3b — Decoupling acceptance gate (core, plain checkout).
 *
 * Formalizes the hard gate for the queue-ops extraction: on a plain core
 * checkout (no extensions), the LIVE RUNTIME PATH — request handling,
 * queue:work (QueueWorker), /health/queue, and QueueMaintenance — is fully on
 * {@see NullWorkerMonitor} and references no concrete WorkerMonitor or
 * Queue\Process\* type. Real worker persistence and the autoscaler live in the
 * queue-ops capability, installed separately.
 *
 * SCOPE NOTE (WS3, copy-first): two assertions from the plan's Task 3b text are
 * IMPOSSIBLE at WS3 and are DEFERRED to Task 4e (the deletion pass):
 *
 *   - `queue:autoscale` is NOT yet absent from `commands:list` — AutoScaleCommand
 *     is still present and auto-discovered by ConsoleProvider until 4e.
 *   - The decoupling grep does NOT yet find zero `Queue\Process\*` references
 *     ANYWHERE in core — AutoScaleCommand.php still imports Process/* and the
 *     concrete WorkerMonitor until 4e.
 *
 * The achievable WS3 gate (asserted here) therefore EXCLUDES the still-present,
 * to-be-moved ops surface: src/Queue/Process/, src/Queue/Monitoring/WorkerMonitor.php,
 * and src/Console/Commands/Queue/AutoScaleCommand.php.
 *
 * DOCUMENTED DECOUPLING GREP — run from the framework repo root. With the
 * to-be-moved ops surface, the interface's own @see docblock, and the benign
 * NullWorkerMonitor binding excluded, this MUST return ZERO hits. Any other hit
 * would be a live-path reference to the concrete WorkerMonitor / Process and
 * would mean the gate is NOT met:
 *
 *   grep -rn "Queue\\Process\\\|Monitoring\\WorkerMonitor\|WorkerMonitor::class" src/ \
 *     --include="*.php" \
 *     | grep -v "src/Queue/Process/" \
 *     | grep -v "src/Queue/Monitoring/WorkerMonitor.php" \
 *     | grep -v "src/Console/Commands/Queue/AutoScaleCommand.php" \
 *     | grep -v "src/Queue/Contracts/WorkerMonitorInterface.php" \
 *     | grep -v "NullWorkerMonitor"
 *
 * (The `NullWorkerMonitor` exclusion drops the one remaining match — the
 * `WorkerMonitorInterface` → NullWorkerMonitor binding in QueueProvider, which
 * the `WorkerMonitor::class` substring pattern picks up. That binding is the
 * very thing the gate requires to be present, not a concrete reference.)
 * Observed output at WS3: empty.
 */
final class CoreQueueDecouplingTest extends TestCase
{
    private string $appPath;
    private string $dbFile;
    private Application $app;
    private ApplicationContext $context;

    protected function setUp(): void
    {
        RouteManifest::reset();

        $this->appPath = sys_get_temp_dir() . '/glueful-qdecouple-' . uniqid('', true);
        $this->dbFile = $this->appPath . '/queue.sqlite';
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);

        file_put_contents(
            $cfg . '/app.php',
            "<?php\nreturn ['name' => 'T', 'version_full' => '1.0.0', 'env' => 'testing', 'debug' => true];\n"
        );
        file_put_contents(
            $cfg . '/database.php',
            "<?php\nreturn ['engine' => 'sqlite', 'sqlite' => ['primary' => '" . $this->dbFile . "'], "
            . "'pooling' => ['enabled' => false]];\n"
        );
        file_put_contents(
            $cfg . '/cache.php',
            "<?php\nreturn ['enabled' => true, 'default' => 'array', 'stores' => ['array' => ['driver' => 'array']]];\n"
        );
        file_put_contents($cfg . '/security.php', "<?php\nreturn ['csrf' => ['enabled' => false]];\n");
        file_put_contents($cfg . '/session.php', "<?php\nreturn ['jwt_key' => 'test'];\n");
        file_put_contents(
            $cfg . '/queue.php',
            "<?php\nreturn ['default' => 'database', 'maintenance' => [], 'connections' => ['database' => "
            . "['driver' => 'database', 'table' => 'queue_jobs', 'failed_table' => 'queue_failed_jobs', "
            . "'retry_after' => 90]]];\n"
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

    /**
     * Gate 1: the core WorkerMonitorInterface seam resolves to the no-op
     * NullWorkerMonitor on a plain checkout (no queue-ops capability binding).
     */
    public function testWorkerMonitorInterfaceResolvesToNullMonitor(): void
    {
        $monitor = $this->app->getContainer()->get(WorkerMonitorInterface::class);

        self::assertInstanceOf(
            NullWorkerMonitor::class,
            $monitor,
            'Core DI must resolve WorkerMonitorInterface to NullWorkerMonitor by default'
        );
    }

    /**
     * Gate 2: QueueMaintenance::handle() runs to completion under the null
     * monitor — the worker/metric cleanup steps no-op (return false) instead of
     * fataling, while real failed-job cleanup still runs against the DB.
     */
    public function testQueueMaintenanceRunsToCompletionUnderNullMonitor(): void
    {
        $maintenance = new QueueMaintenance($this->context);
        $maintenance->handle();

        $stats = $maintenance->getStats();

        // handle() completed without fatal and produced its stats array.
        self::assertArrayHasKey('start_time', $stats);
        self::assertArrayHasKey('errors', $stats);

        // Worker/metric cleanup no-op'd under the null monitor (cleanupOldWorkers
        // and cleanupOldMetrics both return false → nothing cleaned).
        self::assertFalse($stats['cleaned_workers'], 'worker cleanup must no-op under the null monitor');
        self::assertFalse($stats['cleaned_metrics'], 'metric cleanup must no-op under the null monitor');

        // Real failed-job cleanup ran against the live queue_failed_jobs table
        // (FailedJobProvider is unaffected by the null monitor). It must NOT have
        // recorded an error — proving the live DB-backed cleanup path still works
        // while only the monitor-backed steps no-op.
        $failedCleanupErrors = array_filter(
            $stats['errors'],
            static fn(string $e): bool => str_contains($e, 'Failed jobs cleanup')
        );
        self::assertSame([], $failedCleanupErrors, 'failed-job cleanup must succeed against the live DB');
    }

    /**
     * Gate 3: the lean queue:work path (QueueWorker, resolved with the container's
     * NullWorkerMonitor) drains a job while the monitor no-ops.
     *
     * "No monitoring writes" form used: resolved-monitor + drained-state. We
     * resolve the worker AND its monitor from the live container (the same seam
     * queue:work uses), drain one job to completion via daemon(stopWhenEmpty),
     * and assert the monitor is the NullWorkerMonitor and getActiveWorkers()
     * stays [] after the drain — i.e. registerWorker/unregisterWorker wrote
     * nothing. (This form was chosen over "assert no queue_workers rows" because
     * the null monitor never touches a queue_workers table at all — there is no
     * such table in the core schema — so an empty-table assertion would be
     * vacuous; the resolved-monitor + empty-active-workers form proves the no-op
     * directly.)
     */
    public function testLeanQueueWorkerNoOpsMonitoringUnderNullMonitor(): void
    {
        DecouplingProbeJob::$ran = 0;

        /** @var QueueManager $manager */
        $manager = $this->app->getContainer()->get(QueueManager::class);
        $manager->push(DecouplingProbeJob::class, ['n' => 1], 'default');

        /** @var WorkerMonitorInterface $monitor */
        $monitor = $this->app->getContainer()->get(WorkerMonitorInterface::class);
        self::assertInstanceOf(NullWorkerMonitor::class, $monitor);

        /** @var QueueWorker $worker */
        $worker = $this->app->getContainer()->get(QueueWorker::class);
        $exit = $worker->daemon('database', ['default'], $this->options(['stopWhenEmpty' => true]));

        self::assertSame(QueueWorker::SUCCESS, $exit, 'daemon must drain and exit cleanly');
        self::assertSame(1, DecouplingProbeJob::$ran, 'the queued job must have been processed');
        self::assertSame(0, $this->queueSize('default'), 'queue must be drained');

        // The monitor wrote nothing: no active workers were registered/persisted.
        self::assertSame(
            [],
            $monitor->getActiveWorkers(),
            'NullWorkerMonitor must record no active workers after a drain (register/unregister no-oped)'
        );
        self::assertFalse($monitor->isEnabled(), 'the live-path monitor must report itself disabled');
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
            stopWhenEmpty: $overrides['stopWhenEmpty'] ?? true,
            maxAttempts: $overrides['maxAttempts'] ?? 3,
            maxRuntime: $overrides['maxRuntime'] ?? 0,
        );
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

    private function recursiveRemoveDirectory(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}

/**
 * Job that records its executions, used to prove the lean worker drained a job.
 */
final class DecouplingProbeJob
{
    public static int $ran = 0;

    /** @param array<string,mixed> $data */
    public function handle(array $data): void
    {
        self::$ran++;
    }
}
