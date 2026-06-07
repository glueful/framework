<?php

declare(strict_types=1);

namespace Glueful\Queue;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Helpers\Utils;
use Glueful\Queue\Contracts\JobInterface;
use Glueful\Queue\Contracts\WorkerMonitorInterface;
use Psr\Log\LoggerInterface;

/**
 * Queue Worker
 *
 * The lean, dependency-free job-draining loop for the framework. This is the
 * single worker entrypoint used by `php glueful queue:work`; supervised fleets,
 * autoscaling and process management live in the `glueful/queue-ops` extension.
 *
 * Semantics are ported 1:1 from the legacy `WorkCommand::executeProcess()` leaf
 * loop, with the stdout IPC lines stripped (IPC stays with the supervisor) and
 * worker/job monitoring routed through {@see WorkerMonitorInterface} (a no-op
 * under {@see \Glueful\Queue\Monitoring\NullWorkerMonitor} on plain core).
 *
 * @package Glueful\Queue
 */
final class QueueWorker
{
    /** Success exit code (mirrors Symfony\Component\Console\Command\Command::SUCCESS). */
    public const SUCCESS = 0;

    /** Seconds between worker heartbeats. */
    private const HEARTBEAT_INTERVAL = 15;

    public function __construct(
        private QueueManager $manager,
        private WorkerMonitorInterface $monitor,
        private LoggerInterface $logger,
        private ?ApplicationContext $context = null,
    ) {
    }

    /**
     * Run the worker daemon loop until a stop condition is reached or a
     * termination signal is received.
     *
     * @param array<int, string> $queues
     * @return int Exit code (always SUCCESS unless a stop condition returns it).
     */
    public function daemon(string $connection, array $queues, WorkerOptions $options): int
    {
        $driver = $this->manager->connection($connection);

        $startedAt = time();
        $processedJobs = 0;
        $running = true;
        $lastHeartbeatAt = 0;

        $workerUuid = $this->generateWorkerUuid();
        $this->monitor->registerWorker($workerUuid, [
            'connection' => $connection,
            'queues' => $queues,
            'pid' => getmypid(),
            'started_at' => date('Y-m-d H:i:s', $startedAt),
            'options' => $options->toArray(),
        ]);

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function () use (&$running): void {
                $running = false;
            });
            pcntl_signal(SIGINT, function () use (&$running): void {
                $running = false;
            });
        }

        try {
            while ($running) {
                if ((time() - $lastHeartbeatAt) >= self::HEARTBEAT_INTERVAL) {
                    $lastHeartbeatAt = time();
                    $this->monitor->updateWorkerHeartbeat($workerUuid, [
                        'last_heartbeat' => date('Y-m-d H:i:s'),
                        'jobs_processed' => $processedJobs,
                        'memory_usage' => memory_get_usage(true),
                    ]);
                }

                $jobProcessedInCycle = false;

                foreach ($queues as $queue) {
                    $queue = trim($queue);
                    if ($queue === '') {
                        continue;
                    }

                    $job = $driver->pop($queue);
                    if ($job === null) {
                        continue;
                    }

                    $jobProcessedInCycle = true;
                    $this->process($job, $queue, $options);
                    $processedJobs++;

                    if ($options->maxJobs > 0 && $processedJobs >= $options->maxJobs) {
                        return self::SUCCESS;
                    }
                }

                if ($options->maxRuntime > 0 && (time() - $startedAt) >= $options->maxRuntime) {
                    return self::SUCCESS;
                }

                if ($options->stopWhenEmpty && !$jobProcessedInCycle) {
                    return self::SUCCESS;
                }

                if (!$jobProcessedInCycle) {
                    sleep($options->sleep);
                }

                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
            }

            return self::SUCCESS;
        } finally {
            $this->monitor->unregisterWorker($workerUuid, [
                'stopped_at' => date('Y-m-d H:i:s'),
                'jobs_processed' => $processedJobs,
            ]);
        }
    }

    /**
     * Process at most one ready job across the given queues.
     *
     * @param array<int, string> $queues
     * @return bool True if a job ran, false if every queue was empty.
     */
    public function runOnce(string $connection, array $queues, WorkerOptions $options): bool
    {
        $driver = $this->manager->connection($connection);

        foreach ($queues as $queue) {
            $queue = trim($queue);
            if ($queue === '') {
                continue;
            }

            $job = $driver->pop($queue);
            if ($job === null) {
                continue;
            }

            $this->process($job, $queue, $options);
            return true;
        }

        return false;
    }

    /**
     * Run a single job through the seed → fire → success/throw pipeline.
     *
     * Mirrors WorkCommand::executeProcess() per-job semantics 1:1.
     */
    private function process(JobInterface $job, string $queue, WorkerOptions $options): void
    {
        // Row-seed contract: recordJobStart MUST precede record success/failure
        // because the concrete monitor updates the metrics row by job_uuid.
        $this->monitor->recordJobStart($job);

        $start = microtime(true);

        try {
            $job->fire();
            $seconds = microtime(true) - $start;
            $this->monitor->recordJobSuccess($job, $seconds);
        } catch (\Throwable $e) {
            $seconds = microtime(true) - $start;

            // JobInterface::failed() and WorkerMonitorInterface::recordJobFailure()
            // both require \Exception, so wrap non-Exception throwables once and
            // pass the SAME instance to both call sites.
            $wrapped = $e instanceof \Exception ? $e : new \RuntimeException($e->getMessage(), 0, $e);

            if ($job->getAttempts() < min($job->getMaxAttempts(), $options->maxAttempts)) {
                $job->release($this->computeBackoff($job->getAttempts(), $options));
            } else {
                $job->failed($wrapped);
            }

            $this->monitor->recordJobFailure($job, $wrapped, $seconds);

            $this->logger->error('Queue job failed', [
                'queue' => $queue,
                'uuid' => $job->getUuid(),
                'attempts' => $job->getAttempts(),
                'error' => $wrapped->getMessage(),
            ]);
        }
    }

    /**
     * Compute the release backoff delay for a failed job.
     *
     * Derives from the optional `queue.workers.performance.backoff_*` config;
     * when no config is available it falls back to flat `$options->sleep`,
     * preserving the original `WorkCommand::executeProcess()` semantics.
     */
    private function computeBackoff(int $attempts, WorkerOptions $options): int
    {
        if ($this->context === null) {
            return $options->sleep;
        }

        $strategy = config($this->context, 'queue.workers.performance.backoff_strategy');
        if ($strategy === null) {
            // No backoff config → preserve original flat-sleep behavior.
            return $options->sleep;
        }

        $base = (int) config($this->context, 'queue.workers.performance.backoff_base', 2);
        $max = (int) config($this->context, 'queue.workers.performance.max_backoff', 3600);
        $attempts = max(0, $attempts);

        $delay = match ((string) $strategy) {
            'fixed' => $base,
            'linear' => $base * ($attempts + 1),
            'exponential' => $base * (2 ** $attempts),
            default => $options->sleep,
        };

        return (int) max(0, min($delay, $max));
    }

    private function generateWorkerUuid(): string
    {
        return Utils::generateNanoID();
    }
}
