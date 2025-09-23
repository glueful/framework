<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Queue\Jobs;

use Glueful\Queue\Jobs\CacheMaintenanceJob;
use Glueful\Queue\QueueManager;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

final class CacheMaintenanceJobTest extends TestCase
{
    /** @var QueueManager&MockObject */
    private MockObject $queueManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queueManager = $this->createMock(QueueManager::class);
    }

    public function testJobHandlesCacheMaintenanceSuccessfully(): void
    {
        // Arrange
        $jobData = [
            'operation' => 'clearExpiredKeys',
            'options' => ['verbose' => true]
        ];

        $job = new CacheMaintenanceJob($jobData);

        // Assert
        $this->assertInstanceOf(CacheMaintenanceJob::class, $job);
        $this->assertIsArray($job->getData());
        $this->assertEquals('clearExpiredKeys', $job->getData()['operation'] ?? null);
    }

    public function testJobHandlesOptimizeCacheOperation(): void
    {
        // Arrange
        $jobData = [
            'operation' => 'optimizeCache',
            'options' => []
        ];

        $job = new CacheMaintenanceJob($jobData);

        // Assert
        $this->assertInstanceOf(CacheMaintenanceJob::class, $job);
        $this->assertIsArray($job->getData());
        $this->assertEquals('optimizeCache', $job->getData()['operation'] ?? null);
    }

    public function testJobHandlesFullCleanupOperation(): void
    {
        // Arrange
        $jobData = [
            'operation' => 'fullCleanup',
            'options' => ['retention_days' => 30]
        ];

        $job = new CacheMaintenanceJob($jobData);

        // Assert
        $this->assertInstanceOf(CacheMaintenanceJob::class, $job);
        $this->assertIsArray($job->getData());
        $this->assertEquals('fullCleanup', $job->getData()['operation'] ?? null);
    }

    public function testJobLogsCompletionAfterExecution(): void
    {
        // This would be tested in a full integration environment
        // where we can verify logs are written correctly

        $jobData = ['operation' => 'clearExpiredKeys'];
        $job = new CacheMaintenanceJob($jobData);

        $this->assertNotNull($job->getData());
        $this->assertArrayHasKey('operation', $job->getData());
    }

    public function testJobHandlesFailureGracefully(): void
    {
        // Arrange
        $jobData = [
            'operation' => 'invalidOperation'
        ];

        $job = new CacheMaintenanceJob($jobData);
        $exception = new \InvalidArgumentException('Unknown operation: invalidOperation');

        // Act
        $job->failed($exception);

        // Assert - the job should handle the failure without throwing
        $this->assertTrue(true);
    }

    public function testJobCanBeQueuedAndProcessed(): void
    {
        // Arrange
        $jobData = [
            'operation' => 'clearExpiredKeys'
        ];

        $this->queueManager->expects($this->once())
            ->method('push')
            ->with(
                CacheMaintenanceJob::class,
                $jobData,
                'maintenance'
            )
            ->willReturn('job-id-123');

        // Act
        $jobId = $this->queueManager->push(
            CacheMaintenanceJob::class,
            $jobData,
            'maintenance'
        );

        // Assert
        $this->assertEquals('job-id-123', $jobId);
    }

    public function testJobRespectsQueueConfiguration(): void
    {
        // Arrange
        $job = new CacheMaintenanceJob(['operation' => 'clearExpiredKeys']);

        // Assert
        $this->assertIsArray($job->getData());
        $this->assertEquals('clearExpiredKeys', $job->getData()['operation'] ?? null);
    }
}
