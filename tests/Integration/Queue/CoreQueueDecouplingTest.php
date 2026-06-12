<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Queue;

use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Framework;
use Glueful\Queue\Contracts\WorkerMonitorInterface;
use Glueful\Queue\Job;
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
 * COMPLETED AT TASK 4e (the deletion pass): two assertions from the plan's
 * Task 3b text that were IMPOSSIBLE at WS3 are now achievable and asserted here,
 * because the ops surface has been deleted from core:
 *
 *   - `queue:autoscale` is now absent — AutoScaleCommand.php is deleted, so
 *     ConsoleProvider's #[AsCommand] discovery no longer registers it. Asserted
 *     by {@see testAutoScaleCommandIsAbsentFromCore()}.
 *   - The decoupling grep now finds zero `Queue\Process\*` / concrete
 *     `WorkerMonitor` references ANYWHERE in core — src/Queue/Process/, the
 *     concrete src/Queue/Monitoring/WorkerMonitor.php, and AutoScaleCommand.php
 *     are all gone, so NO ops-surface exclusions are needed any more. Asserted
 *     by {@see testNoConcreteWorkerMonitorOrProcessReferencesRemainInCore()}.
 *
 * DOCUMENTED DECOUPLING GREP — run from the framework repo root. After the 4e
 * deletions, only the interface's own @see docblock and the benign
 * NullWorkerMonitor binding remain; this MUST return ZERO hits. Any other hit
 * would be a live-path reference to a concrete WorkerMonitor / Process and would
 * mean the gate is NOT met (NOTE: no Process-dir / WorkerMonitor.php /
 * AutoScaleCommand.php exclusions remain — those files no longer exist):
 *
 *   grep -rn "Queue\\Process\\\|Monitoring\\WorkerMonitor\|WorkerMonitor::class" src/ \
 *     --include="*.php" \
 *     | grep -v "src/Queue/Contracts/WorkerMonitorInterface.php" \
 *     | grep -v "NullWorkerMonitor"
 *
 * (The `WorkerMonitorInterface.php` exclusion drops its own `{@see ...WorkerMonitor}`
 * prose docblock; the `NullWorkerMonitor` exclusion drops the one remaining
 * match — the `WorkerMonitorInterface` → NullWorkerMonitor binding in
 * QueueProvider, which the `WorkerMonitor::class` substring pattern picks up.
 * That binding is the very thing the gate requires to be present, not a concrete
 * reference.) Observed output at Task 4e: empty.
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

    /**
     * Gate 4 (Task 4e): `queue:autoscale` is no longer registered/discoverable in
     * core. AutoScaleCommand.php has been deleted, so ConsoleProvider's
     * #[AsCommand] auto-discovery (a recursive scan of src/Console/Commands) can
     * no longer pick it up.
     *
     * Form used (the prompt's acceptable alternative to a full console boot): we
     * assert (a) the command class no longer exists, and (b) a direct scan of
     * src/Console/Commands — mirroring ConsoleProvider's discovery — yields no
     * class declaring `queue:autoscale` via #[AsCommand]. Together these prove the
     * command is unreachable through the core console kernel.
     */
    public function testAutoScaleCommandIsAbsentFromCore(): void
    {
        self::assertFalse(
            class_exists(\Glueful\Console\Commands\Queue\AutoScaleCommand::class),
            'AutoScaleCommand must be deleted from core (moved to glueful/queue-ops)'
        );

        $commandsDir = dirname(__DIR__, 3) . '/src/Console/Commands';
        self::assertDirectoryExists($commandsDir);

        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($commandsDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            // ConsoleProvider only registers classes carrying #[AsCommand]; a file
            // naming 'queue:autoscale' inside such an attribute would re-expose it.
            if (
                str_contains($source, 'queue:autoscale')
                && preg_match('/#\[AsCommand[^]]*queue:autoscale/s', $source) === 1
            ) {
                $offenders[] = $file->getPathname();
            }
        }

        self::assertSame(
            [],
            $offenders,
            "No core console command may register 'queue:autoscale' (it lives in glueful/queue-ops)"
        );
    }

    /**
     * Gate 5 (Task 4e): the decoupling grep over src/ now finds ZERO references to
     * the concrete WorkerMonitor or any Queue\Process\* type — with NO ops-surface
     * exclusions (src/Queue/Process/, src/Queue/Monitoring/WorkerMonitor.php, and
     * AutoScaleCommand.php no longer exist). Only the interface's own @see docblock
     * and the benign NullWorkerMonitor binding are excluded.
     *
     * Exact command (run from the framework repo root), observed empty at 4e:
     *
     *   grep -rn 'Queue\\Process\\\|Monitoring\\WorkerMonitor\|WorkerMonitor::class' src/ \
     *     --include='*.php' \
     *     | grep -v 'src/Queue/Contracts/WorkerMonitorInterface.php' \
     *     | grep -v 'NullWorkerMonitor'
     */
    public function testNoConcreteWorkerMonitorOrProcessReferencesRemainInCore(): void
    {
        $srcDir = dirname(__DIR__, 3) . '/src';
        self::assertDirectoryExists($srcDir);

        // The to-be-zero set: any Queue\Process\* reference, the concrete
        // Monitoring\WorkerMonitor, or a WorkerMonitor::class literal.
        $needles = ['Queue\\Process\\', 'Monitoring\\WorkerMonitor', 'WorkerMonitor::class'];

        $hits = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();

            // Mirrors the documented `grep -v` exclusions:
            //  - the interface's own {@see ...WorkerMonitor} prose docblock;
            //  - the NullWorkerMonitor binding/usages (the required seam, not a
            //    concrete reference — its name contains "WorkerMonitor").
            if (str_contains($path, 'src/Queue/Contracts/WorkerMonitorInterface.php')) {
                continue;
            }

            foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $lineNo => $line) {
                if (str_contains($line, 'NullWorkerMonitor')) {
                    continue;
                }
                foreach ($needles as $needle) {
                    if (str_contains($line, $needle)) {
                        $hits[] = $path . ':' . ($lineNo + 1) . ' ' . trim($line);
                    }
                }
            }
        }

        self::assertSame(
            [],
            $hits,
            "Core must contain no concrete WorkerMonitor / Queue\\Process references after Task 4e"
        );
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
final class DecouplingProbeJob extends Job
{
    public static int $ran = 0;

    public function handle(): void
    {
        self::$ran++;
    }
}
