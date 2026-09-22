<?php

namespace Glueful\Queue\Failed;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Helpers\Utils;
use Glueful\Queue\Contracts\QueueDriverInterface;
use Glueful\Queue\QueuePayloadSigner;
use Glueful\Queue\QueueManager;
use Glueful\Security\SecureSerializer;

/**
 * Failed Job Provider
 *
 * The one implementation of failed-job storage over the stock `queue_failed_jobs` table
 * (`uuid`, `connection`, `queue`, `payload`, `exception`, `batch_uuid`, `failed_at`): record a
 * failure, list and find failures, put one back on its queue, forget, flush, prune, and summarise.
 * The database queue driver and the queue:failed / queue:retry / queue:forget / queue:flush
 * commands go through it. Everything here is portable across SQLite, MySQL and PostgreSQL; the
 * job class and exception class are read from the stored payload and message, not from columns.
 *
 * @package Glueful\Queue\Failed
 */
class FailedJobProvider
{
    /** @var Connection Database connection */
    private Connection $db;

    /** @var string Failed jobs table name */
    private string $table;

    /** @var int Kept for the constructor contract; see getMaxRetries() */
    private int $maxRetries;

    /** @var int Days to keep failed jobs */
    private int $retentionDays;
    private ?ApplicationContext $context;
    private ?QueueDriverInterface $requeueDriver;

    /**
     * @param Connection|null $connection Database connection (optional)
     * @param string $table Table name for failed jobs
     * @param int $maxRetries Kept for compatibility; not enforced (see getMaxRetries())
     * @param int $retentionDays Days to keep failed jobs
     * @param QueueDriverInterface|null $requeueDriver Driver a retry pushes onto; defaults to the
     *        failure's own connection from the container's QueueManager
     */
    public function __construct(
        ?Connection $connection = null,
        string $table = 'queue_failed_jobs',
        int $maxRetries = 5,
        int $retentionDays = 30,
        ?ApplicationContext $context = null,
        ?QueueDriverInterface $requeueDriver = null
    ) {
        $this->context = $context;
        $this->db = $connection ?? Connection::fromContext($this->context);
        $this->table = $table;
        $this->maxRetries = $maxRetries;
        $this->retentionDays = $retentionDays;
        $this->requeueDriver = $requeueDriver;
    }

    /**
     * Record a failed job
     *
     * @param string $connection Connection name
     * @param string $queue Queue name
     * @param string $payload Job payload as stored on the queue (signed JSON)
     * @param \Exception $exception Exception that caused failure
     * @return string Failed job UUID
     */
    public function log(string $connection, string $queue, string $payload, \Exception $exception): string
    {
        $uuid = Utils::generateNanoID();

        $this->db->table($this->table)->insert([
            'uuid' => $uuid,
            'connection' => $connection,
            'queue' => $queue,
            'payload' => $payload,
            'exception' => get_class($exception) . ': ' . $exception->getMessage()
                . "\n\n" . $exception->getTraceAsString(),
            'failed_at' => date('Y-m-d H:i:s'),
        ]);

        return $uuid;
    }

    /**
     * Failed jobs, newest first. Each row carries the stored columns plus `job`, the job class
     * read from its payload.
     *
     * @param array<string, mixed> $filters `connection`, `queue`, `from_date`, `to_date`
     * @param int $limit Result limit
     * @param int $offset Result offset
     * @return array<int, array<string, mixed>> Failed jobs
     */
    public function all(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $query = $this->db->table($this->table)->select(['*']);
        $this->applyFilters($query, $filters);
        $rows = $query->orderBy('failed_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();

        return array_values(array_map(fn(array $row): array => $this->withJobClass($row), $rows));
    }

    /**
     * Find failed job by UUID
     *
     * @return array<string, mixed>|null Failed job data, with `job`
     */
    public function find(string $uuid): ?array
    {
        $row = $this->db->table($this->table)->where('uuid', $uuid)->first();

        return $row === null ? null : $this->withJobClass($row);
    }

    /** Forget failed job by UUID */
    public function forget(string $uuid): bool
    {
        return $this->db->table($this->table)->where('uuid', $uuid)->delete() > 0;
    }

    /**
     * Forget failed jobs, all or those matching the filters
     *
     * @param array<string, mixed> $filters See all()
     * @return bool True if any were forgotten
     */
    public function flush(array $filters = []): bool
    {
        return $this->flushCount($filters) > 0;
    }

    /**
     * Forget failed jobs and say how many
     *
     * @param array<string, mixed> $filters See all()
     */
    public function flushCount(array $filters = []): int
    {
        $query = $this->db->table($this->table);
        $this->applyFilters($query, $filters);
        if ($filters === []) {
            $query->where('id', '>', 0);
        }

        return $query->delete();
    }

    /**
     * Put a failed job back on the queue it failed on, as a new job with fresh attempts, and
     * drop the failure. The stored payload's signature is verified first, so a payload altered
     * after it failed is refused, never re-signed and run.
     *
     * @return string|null The new job's uuid, or null for an unknown failed-job uuid
     * @throws \RuntimeException when the payload is unreadable, unsigned or altered
     */
    public function requeue(string $uuid): ?string
    {
        $row = $this->db->table($this->table)->where('uuid', $uuid)->first();
        if ($row === null) {
            return null;
        }

        $stored = $this->decodePayload((string) $row['payload']);
        if ($stored === null) {
            throw new \RuntimeException("Failed job {$uuid} has an unreadable payload");
        }
        $payload = (new QueuePayloadSigner($this->context))->verify($stored);
        $job = $payload['job'] ?? null;
        if (!is_string($job) || $job === '') {
            throw new \RuntimeException("Failed job {$uuid} names no job class");
        }
        $driver = $this->driverFor((string) $row['connection']);

        return $this->db->query()->transaction(function () use ($driver, $job, $payload, $row, $uuid): string {
            $newUuid = $driver->push($job, (array) ($payload['data'] ?? []), (string) $row['queue']);
            $this->db->table($this->table)->where('uuid', $uuid)->delete();

            return $newUuid;
        });
    }

    /**
     * Retry failed job
     *
     * @return bool True if the job was put back on its queue
     */
    public function retry(string $uuid): bool
    {
        try {
            return $this->requeue($uuid) !== null;
        } catch (\Throwable $e) {
            error_log("Failed to retry job {$uuid}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Retry multiple failed jobs
     *
     * @param array<int, string> $uuids Array of failed job UUIDs
     * @return array<string, bool> uuid => whether it was put back
     */
    public function retryMultiple(array $uuids): array
    {
        $results = [];
        foreach ($uuids as $uuid) {
            $results[$uuid] = $this->retry($uuid);
        }
        return $results;
    }

    /**
     * Retry every failed job matching the filters
     *
     * @param array<string, mixed> $filters See all()
     * @return array<string, bool> uuid => whether it was put back
     */
    public function retryAll(array $filters = []): array
    {
        $query = $this->db->table($this->table)->select(['uuid']);
        $this->applyFilters($query, $filters);

        return $this->retryMultiple(array_column($query->get(), 'uuid'));
    }

    /**
     * Failed job statistics
     *
     * Every stored failure can be retried, so `retryable` equals `total_failed`.
     *
     * @param array<string, mixed> $filters See all()
     * @return array<string, mixed>
     */
    public function getStats(array $filters = []): array
    {
        $totalQuery = $this->db->table($this->table);
        $this->applyFilters($totalQuery, $filters);
        $total = $totalQuery->count();

        $recentQuery = $this->db->table($this->table);
        $this->applyFilters($recentQuery, $filters);
        $recent = $recentQuery->where('failed_at', '>=', date('Y-m-d H:i:s', time() - 86400))->count();

        return [
            'total_failed' => $total,
            'retryable' => $total,
            'non_retryable' => 0,
            'recent_failures' => $recent,
            'failure_patterns' => $this->getFailurePatterns($filters),
        ];
    }

    /**
     * Failure patterns over the most recent 1 000 failures: counts per job class and per
     * exception class, and per hour of day for the last seven days. Computed in PHP, so it runs
     * the same on every engine.
     *
     * @param array<string, mixed> $filters See all()
     * @return array{job_classes: array<string, int>, exception_types: array<string, int>,
     *               hourly_trends: array<int, int>}
     */
    public function getFailurePatterns(array $filters = []): array
    {
        $query = $this->db->table($this->table)->select(['payload', 'exception', 'failed_at']);
        $this->applyFilters($query, $filters);
        $rows = $query->orderBy('failed_at', 'DESC')->limit(1000)->get();

        $jobs = [];
        $exceptions = [];
        $hours = array_fill(0, 24, 0);
        $weekAgo = time() - 7 * 86400;
        foreach ($rows as $row) {
            $job = $this->withJobClass($row)['job'];
            $jobs[$job] = ($jobs[$job] ?? 0) + 1;

            $class = strstr((string) $row['exception'], ':', true);
            $class = $class !== false && !str_contains($class, ' ') ? $class : 'unknown';
            $exceptions[$class] = ($exceptions[$class] ?? 0) + 1;

            $at = strtotime((string) $row['failed_at']);
            if ($at !== false && $at >= $weekAgo) {
                $hours[(int) date('G', $at)]++;
            }
        }
        arsort($jobs);
        arsort($exceptions);

        return [
            'job_classes' => array_slice($jobs, 0, 10, true),
            'exception_types' => array_slice($exceptions, 0, 10, true),
            'hourly_trends' => $hours,
        ];
    }

    /**
     * Clean up old failed jobs
     *
     * @param int|null $daysOld Days old to clean up (the retention setting if null)
     * @return bool True if any were removed
     */
    public function cleanup(?int $daysOld = null): bool
    {
        return $this->prune($daysOld ?? $this->retentionDays) > 0;
    }

    /** Remove failures older than the given number of days and say how many went. */
    public function prune(int $daysOld): int
    {
        return $this->db->table($this->table)
            ->where('failed_at', '<', date('Y-m-d H:i:s', time() - $daysOld * 86400))
            ->delete();
    }

    /**
     * Export failed jobs data
     *
     * @param array<string, mixed> $filters See all()
     * @param string $format Export format (json, csv)
     */
    public function export(array $filters = [], string $format = 'json'): string
    {
        $query = $this->db->table($this->table)->select(['*']);
        $this->applyFilters($query, $filters);
        $failedJobs = $query->orderBy('failed_at', 'DESC')->get();

        return $format === 'csv'
            ? $this->exportToCsv($failedJobs)
            : json_encode($failedJobs, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    }

    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * @deprecated since 1.86 — a retry creates a new job with fresh attempts and the stock table
     *             keeps no retry count, so nothing enforces this value; remove in 1.88.
     */
    public function setMaxRetries(int $maxRetries): void
    {
        $this->maxRetries = $maxRetries;
    }

    /**
     * @deprecated since 1.86 — not enforced (see setMaxRetries()); remove in 1.88.
     */
    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    public function setRetentionDays(int $retentionDays): void
    {
        $this->retentionDays = $retentionDays;
    }

    public function getRetentionDays(): int
    {
        return $this->retentionDays;
    }

    /**
     * Apply the supported filters. Anything else is refused rather than silently ignored.
     *
     * @param array<string, mixed> $filters
     */
    private function applyFilters(mixed $query, array $filters): void
    {
        $columns = ['connection' => 'connection', 'queue' => 'queue'];
        foreach ($filters as $key => $value) {
            if (isset($columns[$key])) {
                $query->where($columns[$key], $value);
            } elseif ($key === 'from_date') {
                $query->where('failed_at', '>=', $value);
            } elseif ($key === 'to_date') {
                $query->where('failed_at', '<=', $value);
            } else {
                throw new \InvalidArgumentException(
                    "Unsupported failed-job filter '{$key}': use connection, queue, from_date or to_date"
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function withJobClass(array $row): array
    {
        $payload = $this->decodePayload((string) ($row['payload'] ?? ''));
        $row['job'] = is_array($payload) && is_string($payload['job'] ?? null) ? $payload['job'] : 'unknown';

        return $row;
    }

    private function driverFor(string $connection): QueueDriverInterface
    {
        if ($this->requeueDriver !== null) {
            return $this->requeueDriver;
        }
        if ($this->context === null) {
            throw new \RuntimeException('Retrying a failed job needs the application context or a queue driver');
        }

        return container($this->context)->get(QueueManager::class)
            ->connection($connection !== '' ? $connection : null);
    }

    /**
     * Decode job payload
     *
     * @return array<string, mixed>|null Decoded payload data
     */
    private function decodePayload(string $payload): ?array
    {
        try {
            $data = json_decode($payload, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return is_array($data) ? $data : null;
            }

            $data = SecureSerializer::forQueue()->unserialize($payload, [
                'Glueful\\Queue\\Job',
                'Glueful\\Queue\\Jobs\\*',
            ]);
            if ($data !== false) {
                return is_array($data) ? $data : ['data' => $data];
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $failedJobs
     */
    private function exportToCsv(array $failedJobs): string
    {
        if (count($failedJobs) === 0) {
            return '';
        }

        $headers = array_keys($failedJobs[0]);
        $csv = implode(',', $headers) . "\n";

        foreach ($failedJobs as $job) {
            $row = [];
            foreach ($headers as $header) {
                $value = (string) ($job[$header] ?? '');
                if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
                    $value = '"' . str_replace('"', '""', $value) . '"';
                }
                $row[] = $value;
            }
            $csv .= implode(',', $row) . "\n";
        }

        return $csv;
    }
}
