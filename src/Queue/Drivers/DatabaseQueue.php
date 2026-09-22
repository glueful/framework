<?php

namespace Glueful\Queue\Drivers;

use Glueful\Queue\Contracts\QueueDriverInterface;
use Glueful\Queue\Contracts\JobInterface;
use Glueful\Queue\Contracts\DriverInfo;
use Glueful\Queue\Contracts\HealthStatus;
use Glueful\Queue\Jobs\DatabaseJob;
use Glueful\Queue\Contracts\FailedJobStore;
use Glueful\Queue\Failed\FailedJobProvider;
use Glueful\Queue\QueuePayloadSigner;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Helpers\Utils;

/**
 * Database Queue Driver
 *
 * Queue driver implementation using database tables for job storage.
 * Provides reliable job queuing with transaction support and atomic operations.
 *
 * Features:
 * - ACID compliance with transaction support
 * - Atomic job reservation using row locking
 * - Priority-based job ordering
 * - Delayed job scheduling
 * - Batch operations for performance
 * - Failed job isolation
 * - Queue-level separation
 *
 * Performance Optimizations:
 * - Indexed columns for fast queries
 * - Efficient job picking with compound indexes
 * - Batch insertions for bulk operations
 * - Connection pooling support
 *
 * @package Glueful\Queue\Drivers
 */
class DatabaseQueue implements QueueDriverInterface, FailedJobStore
{
    /** @var Connection Database connection */
    private Connection $db;

    /** @var SchemaBuilderInterface Schema builder for table creation */
    private SchemaBuilderInterface $schema;

    /** @var string Queue jobs table name */
    private string $table;

    /** @var string Failed jobs table name */
    private string $failedTable;

    /** @var int Seconds before job retry */
    private int $retryAfter;
    private ?ApplicationContext $context = null;


    /**
     * Get driver information
     *
     * @return DriverInfo Driver metadata
     */
    public function getDriverInfo(): DriverInfo
    {
        return new DriverInfo(
            name: 'database',
            version: '1.0.0',
            author: 'Glueful Team',
            description: 'Database-backed queue driver with transaction support',
            supportedFeatures: [
                'delayed_jobs',
                'priority_queues',
                'bulk_operations',
                'atomic_operations',
                'failed_jobs',
                'job_batching',
                'queue_separation'
            ],
            requiredDependencies: [],
            context: $this->context
        );
    }

    /**
     * Initialize driver with configuration
     *
     * @param array<string, mixed> $config Configuration options
     * @return void
     */
    public function initialize(array $config): void
    {
        $this->context = $config['context'] ?? null;
        $this->db = Connection::fromContext($this->context);
        $this->schema = $this->db->getSchemaBuilder();
        $this->table = (string) $this->getConfig('queue.connections.database.table', 'queue_jobs');
        $this->failedTable = (string) $this->getConfig('queue.connections.database.failed_table', 'queue_failed_jobs');
        $this->retryAfter = (int) $this->getConfig('queue.connections.database.retry_after', 90);

        // Schema is owned by the core 'glueful/framework:queue' migration (registered when
        // queue.default=database). No lazy runtime DDL here — run `php glueful migrate:run`.
    }

    private function getConfig(string $key, mixed $default = null): mixed
    {
        if ($this->context === null) {
            return $default;
        }

        return config($this->context, $key, $default);
    }

    /**
     * Perform health check on database connection
     *
     * @return HealthStatus Health status
     */
    public function healthCheck(): HealthStatus
    {
        $startTime = microtime(true);

        try {
            // Test the connection itself (the query builder refuses a table-less SELECT)
            $this->db->getPDO()->query('SELECT 1');

            // Check if queue table exists using database-agnostic approach
            try {
                $this->db->table($this->table)->selectRaw("1")->limit(1)->get();
                $tableExists = true;
            } catch (\Exception $e) {
                $tableExists = false;
            }

            if (!$tableExists) {
                return HealthStatus::unhealthy(
                    "Queue table '{$this->table}' does not exist",
                    [],
                    (microtime(true) - $startTime) * 1000
                );
            }

            // Get queue statistics
            $stats = $this->db->query()->selectRaw(
                "COUNT(*) as total_jobs,
                 COUNT(CASE WHEN reserved_at IS NULL THEN 1 END) as pending_jobs,
                 COUNT(CASE WHEN reserved_at IS NOT NULL THEN 1 END) as reserved_jobs"
            )->from($this->table)->get();

            $responseTime = (microtime(true) - $startTime) * 1000;

            return HealthStatus::healthy(
                [
                    'total_jobs' => $stats[0]['total_jobs'] ?? 0,
                    'pending_jobs' => $stats[0]['pending_jobs'] ?? 0,
                    'reserved_jobs' => $stats[0]['reserved_jobs'] ?? 0,
                    'table' => $this->table
                ],
                'Database connection is healthy',
                $responseTime
            );
        } catch (\Exception $e) {
            return HealthStatus::unhealthy(
                'Database connection failed: ' . $e->getMessage(),
                [],
                (microtime(true) - $startTime) * 1000
            );
        }
    }

    /**
     * Push job to queue
     *
     * @param string $job Job class name
     * @param array<string, mixed> $data Job data
     * @param string|null $queue Queue name
     * @return string Job UUID
     */
    public function push(string $job, array $data = [], ?string $queue = null): string
    {
        return $this->pushToDatabase($job, $data, 0, $queue);
    }

    /**
     * Push delayed job to queue
     *
     * @param int $delay Delay in seconds
     * @param string $job Job class name
     * @param array<string, mixed> $data Job data
     * @param string|null $queue Queue name
     * @return string Job UUID
     */
    public function later(int $delay, string $job, array $data = [], ?string $queue = null): string
    {
        return $this->pushToDatabase($job, $data, $delay, $queue);
    }

    /**
     * Push job to database
     *
     * @param string $job Job class name
     * @param array<string, mixed> $data Job data
     * @param int $delay Delay in seconds
     * @param string|null $queue Queue name
     * @param string|null $batchUuid Batch UUID if part of batch
     * @return string Job UUID
     */
    private function pushToDatabase(
        string $job,
        array $data,
        int $delay = 0,
        ?string $queue = null,
        ?string $batchUuid = null
    ): string {
        $uuid = Utils::generateNanoID();
        $now = new \DateTime();
        $availableAt = clone $now;
        if ($delay > 0) {
            $availableAt->add(new \DateInterval("PT{$delay}S"));
        }

        $payload = $this->signPayload([
            'uuid' => $uuid,
            'displayName' => $job,
            'job' => $job,
            'data' => $data,
            'attempts' => 0,
            'maxAttempts' => $data['maxAttempts'] ?? 3,
            'timeout' => $data['timeout'] ?? 60,
            'pushedAt' => $now->getTimestamp()
        ]);

        $this->db->table($this->table)->insert([
            'uuid' => $uuid,
            'queue' => $queue ?? 'default',
            'payload' => json_encode($payload),
            'attempts' => 0,
            'available_at' => $availableAt->format('Y-m-d H:i:s'),
            'created_at' => $now->format('Y-m-d H:i:s'),
            'priority' => $data['priority'] ?? 0,
            'batch_uuid' => $batchUuid
        ]);

        return $uuid;
    }

    /**
     * Pop next job from queue
     *
     * @param string|null $queue Queue name
     * @return JobInterface|null Next job or null
     */
    public function pop(?string $queue = null): ?JobInterface
    {
        $queue = $queue ?? 'default';

        return $this->db->query()->transaction(function () use ($queue) {
            // Clean up expired reserved jobs
            $this->releaseExpiredJobs($queue);

            // Claim, don't just mark: the reservation only lands while the row is still
            // unreserved. A worker that selected the same row and lost the race gets 0 affected
            // rows and moves on to the next candidate, so no two workers run one job. This holds
            // on every engine without row locks.
            foreach ($this->candidates($queue, 5) as $job) {
                $claimed = $this->db->table($this->table)
                    ->where('uuid', $job['uuid'])
                    ->whereNull('reserved_at')
                    ->update([
                        'reserved_at' => date('Y-m-d H:i:s'),
                        'attempts' => $job['attempts'] + 1,
                    ]);
                if ($claimed === 1) {
                    return new DatabaseJob($this, $job, $queue, $this->context);
                }
            }

            return null;
        });
    }

    /**
     * The next jobs a worker may try to claim, best first.
     *
     * @return list<array<string, mixed>>
     */
    protected function candidates(string $queue, int $limit): array
    {
        return array_values($this->db->table($this->table)
            ->select(['*'])
            ->where('queue', $queue)
            ->whereNull('reserved_at')
            ->where('available_at', '<=', date('Y-m-d H:i:s'))
            ->orderBy('priority', 'DESC')
            ->orderBy('available_at', 'ASC')
            ->limit($limit)
            ->get());
    }

    /**
     * Release expired reserved jobs back to queue
     *
     * @param string $queue Queue name
     * @return void
     */
    private function releaseExpiredJobs(string $queue): void
    {
        $expiredTime = (new \DateTime())
            ->sub(new \DateInterval("PT{$this->retryAfter}S"))
            ->format('Y-m-d H:i:s');

        // Update expired reserved jobs using fluent interface
        $this->db->table($this->table)
            ->where('queue', $queue)
            ->where('reserved_at', '<', $expiredTime)
            ->update(['reserved_at' => null]);
    }

    /**
     * Release job back to queue
     *
     * @param JobInterface $job Job to release
     * @param int $delay Delay before retry
     * @return void
     */
    public function release(JobInterface $job, int $delay = 0): void
    {
        if (!$job instanceof DatabaseJob) {
            throw new \InvalidArgumentException('Job must be a DatabaseJob instance');
        }

        $availableAt = new \DateTime();
        if ($delay > 0) {
            $availableAt->add(new \DateInterval("PT{$delay}S"));
        }

        $this->db->table($this->table)->where('uuid', $job->getUuid())->update([
            'reserved_at' => null,
            'available_at' => $availableAt->format('Y-m-d H:i:s')
        ]);
    }

    /**
     * Delete job from queue
     *
     * @param JobInterface $job Job to delete
     * @return void
     */
    public function delete(JobInterface $job): void
    {
        if (!$job instanceof DatabaseJob) {
            throw new \InvalidArgumentException('Job must be a DatabaseJob instance');
        }

        $this->db->table($this->table)->where('uuid', $job->getUuid())->delete();
    }

    /**
     * Get number of jobs in queue
     *
     * @param string|null $queue Queue name
     * @return int Number of jobs
     */
    public function size(?string $queue = null): int
    {
        $query = $this->db->table($this->table);
        if ($queue !== null) {
            $query->where('queue', $queue);
        }

        $result = $query->count();
        return $result;
    }

    /**
     * Push multiple jobs in bulk
     *
     * @param array<int, array<string, mixed>> $jobs Array of job definitions
     * @param string|null $queue Queue name
     * @return array<int, string> Array of job UUIDs
     */
    public function bulk(array $jobs, ?string $queue = null): array
    {
        $uuids = [];
        $rows = [];
        $now = new \DateTime();

        foreach ($jobs as $jobDef) {
            $uuid = Utils::generateNanoID();
            $uuids[] = $uuid;

            $delay = $jobDef['delay'] ?? 0;
            $availableAt = clone $now;
            if ($delay > 0) {
                $availableAt->add(new \DateInterval("PT{$delay}S"));
            }

            $payload = $this->signPayload([
                'uuid' => $uuid,
                'displayName' => $jobDef['job'],
                'job' => $jobDef['job'],
                'data' => $jobDef['data'] ?? [],
                'attempts' => 0,
                'maxAttempts' => $jobDef['data']['maxAttempts'] ?? 3,
                'timeout' => $jobDef['data']['timeout'] ?? 60,
                'pushedAt' => $now->getTimestamp()
            ]);

            $rows[] = [
                'uuid' => $uuid,
                'queue' => $queue ?? 'default',
                'payload' => json_encode($payload),
                'attempts' => 0,
                'available_at' => $availableAt->format('Y-m-d H:i:s'),
                'created_at' => $now->format('Y-m-d H:i:s'),
                'priority' => $jobDef['data']['priority'] ?? 0,
                'batch_uuid' => $jobDef['batch_uuid'] ?? null
            ];
        }

        // Use batch insert for performance
        if (count($rows) > 0) {
            $this->db->table($this->table)->insertBatch($rows);
        }

        return $uuids;
    }

    /**
     * Remove all jobs from queue
     *
     * @param string|null $queue Queue name
     * @return int Number of jobs purged
     */
    public function purge(?string $queue = null): int
    {
        $query = $this->db->table($this->table);
        if ($queue !== null) {
            $query->where('queue', $queue);
        }

        // Get count before deletion
        $count = $query->count();

        // Delete all matching jobs
        $deleteQuery = $this->db->table($this->table);
        if ($queue !== null) {
            $deleteQuery->where('queue', $queue);
        }
        $deleteQuery->delete();

        return $count;
    }

    /**
     * Get queue statistics
     *
     * @param string|null $queue Queue name
     * @return array<string, mixed> Statistics
     */
    public function getStats(?string $queue = null): array
    {
        $conditions = [];
        if ($queue !== null) {
            $conditions['queue'] = $queue;
        }

        // Get various counts using fluent query builder for better performance
        $currentTime = date('Y-m-d H:i:s');
        $query = $this->db->query()->selectRaw(
            "COUNT(*) as total,
             COUNT(CASE WHEN reserved_at IS NULL THEN 1 END) as pending,
             COUNT(CASE WHEN reserved_at IS NOT NULL THEN 1 END) as reserved,
             COUNT(CASE WHEN reserved_at IS NULL AND available_at > '$currentTime' THEN 1 END) as delayed"
        )->from($this->table);

        if ($queue !== null) {
            $query->where('queue', $queue);
        }

        $stats = $query->get();

        $total = $stats[0]['total'] ?? 0;
        $pending = $stats[0]['pending'] ?? 0;
        $reserved = $stats[0]['reserved'] ?? 0;
        $delayed = $stats[0]['delayed'] ?? 0;

        // Get failed job count
        $failedQuery = $this->db->table($this->failedTable);
        if ($queue !== null) {
            $failedQuery->where('queue', $queue);
        }
        $failed = $failedQuery->count();

        return [
            'total' => $total,
            'pending' => $pending,
            'reserved' => $reserved,
            'delayed' => $delayed,
            'failed' => $failed,
            'queues' => $this->getQueueList()
        ];
    }

    /**
     * Get list of all queues
     *
     * @return array<int, string> Queue names
     */
    private function getQueueList(): array
    {
        $result = $this->db->table($this->table)
            ->select(['queue'])
            ->distinct()
            ->orderBy('queue')
            ->get();

        return array_column($result, 'queue');
    }

    /**
     * Get supported features
     *
     * @return array<int, string> Feature list
     */
    public function getFeatures(): array
    {
        return $this->getDriverInfo()->supportedFeatures;
    }

    /**
     * Get configuration schema
     *
     * @return array<string, mixed> Schema definition
     */
    public function getConfigSchema(): array
    {
        return [
            'table' => [
                'type' => 'string',
                'required' => false,
                'default' => 'queue_jobs',
                'description' => 'Database table for queue jobs'
            ],
            'failed_table' => [
                'type' => 'string',
                'required' => false,
                'default' => 'queue_failed_jobs',
                'description' => 'Database table for failed jobs'
            ],
            'retry_after' => [
                'type' => 'int',
                'required' => false,
                'default' => 90,
                'description' => 'Seconds before retrying reserved jobs'
            ]
        ];
    }

    /**
     * Handle failed job (interface requirement)
     *
     * @param JobInterface $job Failed job
     * @param \Exception $exception Exception that caused failure
     * @return void
     */
    public function failed(JobInterface $job, \Exception $exception): void
    {
        $this->fail($job, $exception);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function signPayload(array $payload): array
    {
        return (new QueuePayloadSigner($this->context))->sign($payload);
    }

    /** Failed-job storage for this connection: the one implementation, over the same table. */
    public function failures(): FailedJobProvider
    {
        return new FailedJobProvider($this->db, $this->failedTable, 5, 30, $this->context, $this);
    }

    /**
     * Failed jobs, newest first, with the job class read from the stored payload.
     *
     * @return list<array{uuid: string, queue: string, job: string, exception: string, failed_at: string}>
     */
    public function failedJobs(?string $queue = null, int $limit = 50, int $offset = 0): array
    {
        $rows = $this->failures()->all($queue !== null ? ['queue' => $queue] : [], $limit, $offset);

        return array_values(array_map(static fn(array $row): array => [
            'uuid' => (string) $row['uuid'],
            'queue' => (string) $row['queue'],
            'job' => (string) $row['job'],
            'exception' => (string) $row['exception'],
            'failed_at' => (string) $row['failed_at'],
        ], $rows));
    }

    /**
     * Put a failed job back on its queue as a new job (see FailedJobProvider::requeue()).
     *
     * @return string|null The new job's uuid, or null for an unknown uuid
     */
    public function retryFailed(string $uuid): ?string
    {
        return $this->failures()->requeue($uuid);
    }

    /** Delete one failed job. */
    public function forgetFailed(string $uuid): bool
    {
        return $this->failures()->forget($uuid);
    }

    /** Delete every failed job, or only one queue's. Returns how many were removed. */
    public function flushFailed(?string $queue = null): int
    {
        return $this->failures()->flushCount($queue !== null ? ['queue' => $queue] : []);
    }

    /**
     * Mark job as failed
     *
     * @param JobInterface $job Failed job
     * @param \Exception $exception Exception that caused failure
     * @return void
     */
    public function fail(JobInterface $job, \Exception $exception): void
    {
        $this->db->query()->transaction(function () use ($job, $exception) {
            // Move to failed jobs table
            $this->failures()->log(
                'database',
                (string) $job->getQueue(),
                (string) json_encode($job->getPayload()),
                $exception
            );

            // Remove from main queue
            $this->delete($job);
        });
    }
}
