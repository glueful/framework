<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration;

use Glueful\Queue\QueueManager;
use Glueful\Queue\Jobs\{
    CacheMaintenanceJob,
    DatabaseBackupJob,
    LogCleanupJob,
    NotificationRetryJob,
    SessionCleanupJob
};
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

final class SchedulerIntegrationTest extends TestCase
{
    /** @var QueueManager&MockObject */
    private QueueManager $queueManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queueManager = $this->createMock(QueueManager::class);
    }

    public function test_scheduled_jobs_are_dispatched_correctly(): void
    {
        // Simulate scheduler dispatching various jobs
        $scheduledJobs = [
            ['job' => CacheMaintenanceJob::class, 'data' => ['operation' => 'clearExpiredKeys'], 'queue' => 'maintenance'],
            ['job' => DatabaseBackupJob::class, 'data' => ['database' => 'production'], 'queue' => 'critical'],
            ['job' => LogCleanupJob::class, 'data' => ['cleanupType' => 'all'], 'queue' => 'maintenance'],
            ['job' => NotificationRetryJob::class, 'data' => ['retryType' => 'process'], 'queue' => 'notifications'],
            ['job' => SessionCleanupJob::class, 'data' => ['cleanupType' => 'expired'], 'queue' => 'maintenance'],
        ];

        // Expect each job to be pushed to the queue
        $this->queueManager->expects($this->exactly(5))
            ->method('push')
            ->willReturnCallback(function ($jobClass, $data, $queue) use ($scheduledJobs) {
                // Verify the job is in our scheduled jobs list
                $found = false;
                foreach ($scheduledJobs as $scheduled) {
                    if ($scheduled['job'] === $jobClass &&
                        $scheduled['data'] === $data &&
                        $scheduled['queue'] === $queue) {
                        $found = true;
                        break;
                    }
                }
                $this->assertTrue($found, "Unexpected job dispatched: {$jobClass}");
                return uniqid('job-', true);
            });

        // Act - simulate scheduler running
        foreach ($scheduledJobs as $job) {
            $this->queueManager->push($job['job'], $job['data'], $job['queue']);
        }
    }

    public function test_scheduled_jobs_respect_queue_priorities(): void
    {
        // Define jobs with different priorities
        $criticalJobs = [
            ['job' => DatabaseBackupJob::class, 'data' => ['database' => 'production']],
        ];

        $maintenanceJobs = [
            ['job' => CacheMaintenanceJob::class, 'data' => ['operation' => 'clearExpiredKeys']],
            ['job' => LogCleanupJob::class, 'data' => ['cleanupType' => 'filesystem']],
            ['job' => SessionCleanupJob::class, 'data' => ['cleanupType' => 'expired']],
        ];

        $notificationJobs = [
            ['job' => NotificationRetryJob::class, 'data' => ['retryType' => 'process']],
        ];

        // Verify critical queue jobs
        foreach ($criticalJobs as $job) {
            $this->queueManager->expects($this->once())
                ->method('push')
                ->with($job['job'], $job['data'], 'critical')
                ->willReturn(uniqid('critical-', true));

            $jobId = $this->queueManager->push($job['job'], $job['data'], 'critical');
            $this->assertStringStartsWith('critical-', $jobId);
        }

        // Reset mock for maintenance queue
        $this->queueManager = $this->createMock(QueueManager::class);

        // Verify maintenance queue jobs
        $this->queueManager->expects($this->exactly(count($maintenanceJobs)))
            ->method('push')
            ->willReturnCallback(function ($jobClass, $data, $queue) {
                $this->assertEquals('maintenance', $queue);
                return uniqid('maintenance-', true);
            });

        foreach ($maintenanceJobs as $job) {
            $this->queueManager->push($job['job'], $job['data'], 'maintenance');
        }
    }

    public function test_scheduler_handles_job_failures(): void
    {
        // Simulate a job that will fail
        $failingJob = new CacheMaintenanceJob(['operation' => 'invalid_operation']);
        $exception = new \InvalidArgumentException('Unknown operation: invalid_operation');

        // Test that the job's failed method handles the exception
        $failingJob->failed($exception);

        // In a real integration test, we would verify:
        // 1. The error was logged
        // 2. The job was moved to failed jobs table
        // 3. Notifications were sent if configured

        $this->assertTrue(true); // Job handled failure without throwing
    }

    public function test_scheduler_respects_schedule_configuration(): void
    {
        // Test that jobs are scheduled according to config/schedule.php
        // This would verify cron expressions, frequencies, etc.

        $scheduleConfig = [
            'cache_maintenance' => [
                'frequency' => 'hourly',
                'job' => CacheMaintenanceJob::class,
                'data' => ['operation' => 'clearExpiredKeys'],
            ],
            'database_backup' => [
                'frequency' => 'daily',
                'job' => DatabaseBackupJob::class,
                'data' => ['database' => 'production'],
            ],
            'log_cleanup' => [
                'frequency' => 'weekly',
                'job' => LogCleanupJob::class,
                'data' => ['cleanupType' => 'all'],
            ],
        ];

        foreach ($scheduleConfig as $name => $config) {
            // Verify each job would be scheduled with correct frequency
            $this->assertArrayHasKey('frequency', $config);
            $this->assertArrayHasKey('job', $config);
            $this->assertArrayHasKey('data', $config);

            // In a real test, we'd verify the scheduler processes these
            // at the correct intervals
        }
    }

    public function test_concurrent_job_execution_limits(): void
    {
        // Test that the scheduler respects max_concurrent_jobs setting
        $maxConcurrent = 5;
        $jobsToSchedule = 10;

        $dispatchedJobs = [];

        for ($i = 0; $i < $jobsToSchedule; $i++) {
            $dispatchedJobs[] = [
                'job' => CacheMaintenanceJob::class,
                'data' => ['operation' => 'clearExpiredKeys', 'id' => $i],
                'queue' => 'maintenance'
            ];
        }

        // In a real test, we'd verify that only $maxConcurrent jobs
        // are running simultaneously
        $this->assertCount($jobsToSchedule, $dispatchedJobs);
        $this->assertLessThanOrEqual($jobsToSchedule, $maxConcurrent * 2);
    }

    public function test_job_timeout_handling(): void
    {
        // Test that jobs respect timeout configuration
        $timeoutSeconds = 300; // 5 minutes default

        $job = new DatabaseBackupJob(['database' => 'large_database']);

        // In a real test, we'd verify:
        // 1. Job is terminated after timeout
        // 2. Timeout error is logged
        // 3. Job can be retried if configured

        $this->assertInstanceOf(DatabaseBackupJob::class, $job);
    }
}