<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Health;

use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Framework;
use Glueful\Queue\Contracts\WorkerMonitorInterface;
use Glueful\Queue\Monitoring\NullWorkerMonitor;
use Glueful\Queue\QueueManager;
use Glueful\Routing\RouteManifest;
use Glueful\Routing\Router;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * WS3 Task 3b — /health/queue under the NullWorkerMonitor.
 *
 * On a plain core checkout the queue-health endpoint must work with the no-op
 * worker monitor: it reports zero active workers (NullWorkerMonitor::getActiveWorkers
 * → []), surfaces no error, and the `no_active_workers_with_pending_jobs` signal
 * still computes against REAL queue stats (pulled from the live database queue,
 * not the monitor). This proves the health surface is decoupled from the
 * concrete WorkerMonitor that now lives in the queue-ops capability.
 */
final class QueueHealthNullMonitorTest extends TestCase
{
    private string $appPath;
    private string $dbFile;
    private Application $app;
    private Router $router;
    private ApplicationContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootFramework();
    }

    protected function tearDown(): void
    {
        if (isset($this->appPath) && is_dir($this->appPath)) {
            $this->recursiveRemoveDirectory($this->appPath);
        }
        parent::tearDown();
    }

    /**
     * With no pending jobs, the endpoint is healthy: zero active workers, no
     * error surfaced, and the no-active-workers signal does NOT fire (nothing
     * pending).
     */
    public function testQueueHealthReportsZeroWorkersAndNoErrorWithNullMonitor(): void
    {
        // Sanity: the live container resolves the no-op monitor.
        self::assertInstanceOf(
            NullWorkerMonitor::class,
            $this->app->getContainer()->get(WorkerMonitorInterface::class)
        );

        $response = $this->router->dispatch(Request::create('/health/queue', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);

        $data = $body['data'] ?? null;
        self::assertIsArray($data, 'queue health payload should nest under data');

        // No error envelope — the null monitor did not break stats collection.
        self::assertArrayNotHasKey('error', $data);
        self::assertSame('healthy', $data['status'] ?? null);

        // workers.active === 0 from the null monitor's empty getActiveWorkers().
        self::assertSame(0, $data['workers']['active'] ?? null, 'null monitor must report zero active workers');
        self::assertSame([], $data['workers']['details'] ?? null);

        // No pending jobs → the no-active-workers signal must NOT fire.
        self::assertNotContains('no_active_workers_with_pending_jobs', $data['issues'] ?? []);
    }

    /**
     * The `no_active_workers_with_pending_jobs` signal still computes against
     * REAL queue stats: with a job pushed to the DB queue (pending > 0) and zero
     * active workers (null monitor), the endpoint degrades and raises the signal.
     */
    public function testNoActiveWorkersSignalComputesAgainstRealQueueStats(): void
    {
        /** @var QueueManager $manager */
        $manager = $this->app->getContainer()->get(QueueManager::class);
        $manager->push(QueueHealthProbeJob::class, ['n' => 1], 'default');

        $response = $this->router->dispatch(Request::create('/health/queue', 'GET'));

        // status() is 200 for healthy and 503 only for the 'error' branch; a
        // 'degraded' readiness signal still returns 200 with the issue listed.
        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertIsArray($body);
        $data = $body['data'] ?? [];

        self::assertArrayNotHasKey('error', $data, 'real stats must be readable under the null monitor');
        self::assertSame(0, $data['workers']['active'] ?? null);
        self::assertGreaterThan(0, (int) ($data['queues']['pending'] ?? 0), 'the pushed job must count as pending');
        self::assertSame('degraded', $data['status'] ?? null);
        self::assertContains(
            'no_active_workers_with_pending_jobs',
            $data['issues'] ?? [],
            'the signal must fire from real queue stats, not from the monitor'
        );
    }

    private function bootFramework(): void
    {
        RouteManifest::reset();

        $this->appPath = sys_get_temp_dir() . '/glueful-qhealth-' . uniqid('', true);
        $this->dbFile = $this->appPath . '/queue.sqlite';
        $configPath = $this->appPath . '/config';
        mkdir($configPath, 0755, true);

        file_put_contents(
            $configPath . '/app.php',
            "<?php\nreturn ['name' => 'T', 'version_full' => '1.0.0', 'env' => 'testing', 'debug' => true];\n"
        );
        file_put_contents(
            $configPath . '/database.php',
            "<?php\nreturn ['engine' => 'sqlite', 'sqlite' => ['primary' => '" . $this->dbFile . "'], "
            . "'pooling' => ['enabled' => false]];\n"
        );
        file_put_contents(
            $configPath . '/cache.php',
            "<?php\nreturn ['enabled' => true, 'default' => 'array', 'stores' => ['array' => ['driver' => 'array']]];\n"
        );
        file_put_contents($configPath . '/security.php', "<?php\nreturn ['csrf' => ['enabled' => false]];\n");
        file_put_contents($configPath . '/session.php', "<?php\nreturn ['jwt_key' => 'test'];\n");
        file_put_contents(
            $configPath . '/queue.php',
            "<?php\nreturn ['default' => 'database', 'maintenance' => [], 'connections' => ['database' => "
            . "['driver' => 'database', 'table' => 'queue_jobs', 'failed_table' => 'queue_failed_jobs', "
            . "'retry_after' => 90]]];\n"
        );

        $this->app = Framework::create($this->appPath)->boot(allowReboot: true);
        $this->context = $this->app->getContext();
        $this->router = $this->app->getContainer()->get(Router::class);

        $this->createQueueSchema();
    }

    private function createQueueSchema(): void
    {
        $connection = Connection::fromContext($this->context);
        $schema = $connection->getSchemaBuilder();

        require_once dirname(__DIR__, 3) . '/migrations/queue/001_CreateQueueSystemTables.php';
        $migration = new \Glueful\Migrations\Queue\CreateQueueSystemTables();
        $migration->up($schema);
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
 * Trivial job used only to put a row in the pending queue for the readiness
 * signal computation.
 */
final class QueueHealthProbeJob
{
    /** @param array<string,mixed> $data */
    public function handle(array $data): void
    {
    }
}
