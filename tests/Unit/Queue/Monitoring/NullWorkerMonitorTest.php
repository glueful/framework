<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Queue\Monitoring;

use Glueful\Queue\Contracts\JobInterface;
use Glueful\Queue\Contracts\WorkerMonitorInterface;
use Glueful\Queue\Monitoring\NullWorkerMonitor;
use PHPUnit\Framework\TestCase;

/**
 * WS1 Task 1a: the null monitor is a no-op, DB-free implementation of
 * WorkerMonitorInterface. It must construct with no arguments and never touch
 * a database, so the queue system can degrade gracefully when monitoring is
 * unavailable.
 */
final class NullWorkerMonitorTest extends TestCase
{
    public function testConstructsWithNoArguments(): void
    {
        $monitor = new NullWorkerMonitor();

        self::assertInstanceOf(WorkerMonitorInterface::class, $monitor);
    }

    public function testGetActiveWorkersReturnsEmptyArray(): void
    {
        $monitor = new NullWorkerMonitor();

        self::assertSame([], $monitor->getActiveWorkers());
    }

    public function testCleanupMethodsReturnFalse(): void
    {
        $monitor = new NullWorkerMonitor();

        self::assertFalse($monitor->cleanupOldWorkers());
        self::assertFalse($monitor->cleanupOldMetrics());
    }

    public function testIsEnabledReturnsFalse(): void
    {
        $monitor = new NullWorkerMonitor();

        self::assertFalse($monitor->isEnabled());
    }

    public function testLifecycleMethodsAreNoOpsWithoutDatabaseAccess(): void
    {
        $monitor = new NullWorkerMonitor();

        $monitor->registerWorker('worker-uuid', ['connection' => 'default']);
        $monitor->updateWorkerHeartbeat('worker-uuid', ['jobs_processed' => 1]);
        $monitor->unregisterWorker('worker-uuid', ['total_runtime' => 10]);
        $monitor->unregisterWorker('worker-uuid');

        // No exception thrown and no DB connection required.
        self::assertFalse($monitor->isEnabled());
    }

    public function testRecordMethodsAreNoOpsWithoutDatabaseAccess(): void
    {
        $monitor = new NullWorkerMonitor();
        $job = $this->createMock(JobInterface::class);

        $monitor->recordJobStart($job);
        $monitor->recordJobSuccess($job, 1.5);
        $monitor->recordJobFailure($job, new \Exception('boom'), 2.0);

        // No exception thrown and no DB connection required.
        self::assertSame([], $monitor->getActiveWorkers());
    }
}
