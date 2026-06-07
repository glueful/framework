<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Queue;

use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Framework;
use Glueful\Queue\Contracts\JobInterface;
use Glueful\Queue\Contracts\WorkerMonitorInterface;
use Glueful\Queue\Jobs\QueueMaintenance;
use Glueful\Routing\RouteManifest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * WS1 Task 1b: QueueMaintenance must acquire its worker monitor through the
 * WorkerMonitorInterface seam (resolved from the container) rather than
 * instantiating the concrete WorkerMonitor directly.
 *
 * - When a context is present, the maintenance job's cleanup steps must run
 *   against the interface implementation bound in the container (proved here
 *   with a spy that records the calls).
 * - When the context is null, the job must still run to completion — the
 *   monitor steps no-op via NullWorkerMonitor instead of fataling.
 */
final class QueueMaintenanceMonitorSeamTest extends TestCase
{
    private string $appPath;
    private Application $app;

    protected function tearDown(): void
    {
        if (isset($this->appPath) && is_dir($this->appPath)) {
            $this->recursiveRemoveDirectory($this->appPath);
        }
        parent::tearDown();
    }

    public function testCleanupStepsRunThroughInjectedMonitorInterface(): void
    {
        $context = $this->bootContext();

        $spy = new class implements WorkerMonitorInterface {
            /** @var array<int, int> */
            public array $cleanupOldWorkersCalls = [];
            /** @var array<int, int> */
            public array $cleanupOldMetricsCalls = [];

            public function registerWorker(string $workerUuid, array $workerData): void
            {
            }

            public function updateWorkerHeartbeat(string $workerUuid, array $data): void
            {
            }

            public function unregisterWorker(string $workerUuid, array $finalStats = []): void
            {
            }

            public function recordJobStart(JobInterface $job): void
            {
            }

            public function recordJobSuccess(JobInterface $job, float $processingTime): void
            {
            }

            public function recordJobFailure(JobInterface $job, \Exception $exception, float $processingTime): void
            {
            }

            public function getActiveWorkers(): array
            {
                return [];
            }

            public function cleanupOldWorkers(int $daysOld = 7): bool
            {
                $this->cleanupOldWorkersCalls[] = $daysOld;
                return true;
            }

            public function cleanupOldMetrics(int $daysOld = 30): bool
            {
                $this->cleanupOldMetricsCalls[] = $daysOld;
                return true;
            }

            public function isEnabled(): bool
            {
                return true;
            }
        };

        // Wrap the real container so WorkerMonitorInterface resolves to the spy,
        // while everything else (QueueManager/FailedJobProvider deps) delegates
        // to the booted container.
        $real = $this->app->getContainer();
        $decorated = new class ($real, $spy) implements ContainerInterface {
            public function __construct(
                private ContainerInterface $inner,
                private WorkerMonitorInterface $spy
            ) {
            }

            public function get(string $id): mixed
            {
                if ($id === WorkerMonitorInterface::class) {
                    return $this->spy;
                }
                return $this->inner->get($id);
            }

            public function has(string $id): bool
            {
                if ($id === WorkerMonitorInterface::class) {
                    return true;
                }
                return $this->inner->has($id);
            }
        };
        $context->setContainer($decorated);

        $maintenance = new QueueMaintenance($context);
        $maintenance->handle();

        // The cleanup steps must have flowed through the injected interface.
        self::assertSame([7], $spy->cleanupOldWorkersCalls, 'cleanupOldWorkers must run via the injected interface');
        self::assertSame([30], $spy->cleanupOldMetricsCalls, 'cleanupOldMetrics must run via the injected interface');
    }

    public function testHandleRunsToCompletionWithNullContext(): void
    {
        // No context, no container — the monitor steps must no-op via
        // NullWorkerMonitor rather than fatal.
        $maintenance = new QueueMaintenance(null);
        $maintenance->handle();

        $stats = $maintenance->getStats();

        // handle() ran to completion without fatal. The no-op monitor's cleanup
        // returns false (nothing cleaned) rather than throwing.
        self::assertArrayHasKey('start_time', $stats);
        self::assertFalse($stats['cleaned_workers']);
        self::assertFalse($stats['cleaned_metrics']);
    }

    private function bootContext(): ApplicationContext
    {
        RouteManifest::reset();

        $this->appPath = sys_get_temp_dir() . '/glueful-qmaint-' . uniqid();
        $configPath = $this->appPath . '/config';
        mkdir($configPath, 0755, true);

        file_put_contents(
            $configPath . '/app.php',
            "<?php\nreturn ['name' => 'T', 'version_full' => '1.0.0', 'env' => 'testing', 'debug' => true];\n"
        );
        file_put_contents(
            $configPath . '/database.php',
            "<?php\nreturn ["
            . "'engine' => 'sqlite', "
            . "'sqlite' => ['primary' => ':memory:'], "
            . "'pooling' => ['enabled' => false]"
            . "];\n"
        );
        file_put_contents(
            $configPath . '/cache.php',
            "<?php\nreturn ['enabled' => true, 'default' => 'array', "
            . "'stores' => ['array' => ['driver' => 'array']]];\n"
        );
        file_put_contents($configPath . '/security.php', "<?php\nreturn ['csrf' => ['enabled' => false]];\n");
        file_put_contents($configPath . '/session.php', "<?php\nreturn ['jwt_key' => 'test'];\n");
        file_put_contents(
            $configPath . '/queue.php',
            "<?php\nreturn ['default' => 'database', 'maintenance' => []];\n"
        );

        $this->app = Framework::create($this->appPath)->boot(allowReboot: true);

        return $this->app->getContext();
    }

    private function recursiveRemoveDirectory(string $dir): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->recursiveRemoveDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
