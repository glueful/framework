<?php

declare(strict_types=1);

namespace Glueful\Async\Exceptions;

/**
 * Exception thrown when async operations exceed configured resource limits.
 *
 * ResourceLimitException is thrown by FiberScheduler when resource consumption
 * limits are exceeded. This provides protection against resource exhaustion from
 * runaway tasks, infinite loops, or excessive concurrency that could crash the
 * application or degrade system performance.
 *
 * Resource Limits:
 * - maxConcurrentTasks: Maximum number of tasks that can run simultaneously
 * - maxTaskExecutionSeconds: Maximum time a single task can execute
 *
 * When Thrown:
 * - Attempting to spawn a task when maxConcurrentTasks limit is reached
 * - A task executes longer than maxTaskExecutionSeconds duration
 * - Resource limits configured in FiberScheduler constructor are violated
 * - System attempts to exceed safe concurrency thresholds
 *
 * Purpose:
 * - Prevent resource exhaustion from too many concurrent tasks
 * - Detect and stop runaway tasks that never complete
 * - Protect against infinite loops in async code
 * - Enforce fair resource usage in multi-tenant systems
 * - Enable graceful degradation under heavy load
 *
 * Usage Examples:
 * ```php
 * // Example 1: Limiting concurrent tasks
 * use Glueful\Async\FiberScheduler;
 * use Glueful\Async\Exceptions\ResourceLimitException;
 *
 * // Allow maximum 10 concurrent tasks
 * $scheduler = new FiberScheduler(
 *     maxConcurrentTasks: 10
 * );
 *
 * $tasks = [];
 * try {
 *     // Try to spawn 20 tasks
 *     for ($i = 0; $i < 20; $i++) {
 *         $tasks[] = $scheduler->spawn(function() use ($scheduler, $i) {
 *             $scheduler->sleep(5); // Long-running task
 *             return $i;
 *         });
 *     }
 * } catch (ResourceLimitException $e) {
 *     // Task limit exceeded
 *     logger()->warning('Concurrent task limit reached', [
 *         'limit' => 10,
 *         'error' => $e->getMessage()
 *     ]);
 *
 *     // Process tasks that were successfully spawned
 *     $results = $scheduler->all($tasks);
 * }
 *
 * // Example 2: Preventing runaway tasks
 * // Limit individual task execution to 30 seconds
 * $scheduler = new FiberScheduler(
 *     maxTaskExecutionSeconds: 30.0
 * );
 *
 * try {
 *     $task = $scheduler->spawn(function() {
 *         // Infinite loop - will be stopped
 *         while (true) {
 *             processItem();
 *         }
 *     });
 *
 *     $result = $task->getResult();
 * } catch (ResourceLimitException $e) {
 *     echo "Task exceeded execution time limit of 30 seconds\n";
 *     logger()->error('Runaway task detected', ['error' => $e->getMessage()]);
 * }
 *
 * // Example 3: Combined limits with metrics
 * use Glueful\Async\Metrics;
 *
 * $metrics = new Metrics();
 * $scheduler = new FiberScheduler(
 *     metrics: $metrics,
 *     maxConcurrentTasks: 50,
 *     maxTaskExecutionSeconds: 60.0
 * );
 *
 * try {
 *     $results = $scheduler->all($tasks);
 * } catch (ResourceLimitException $e) {
 *     // Log which limit was hit
 *     $summary = $metrics->getSummary();
 *     logger()->error('Resource limit exceeded', [
 *         'error' => $e->getMessage(),
 *         'tasks_completed' => $summary['tasks_completed'],
 *         'tasks_failed' => $summary['tasks_failed'],
 *         'concurrent_peak' => $summary['concurrent_tasks_peak']
 *     ]);
 * }
 *
 * // Example 4: Graceful degradation with queue
 * $scheduler = new FiberScheduler(maxConcurrentTasks: 10);
 * $queue = [];
 *
 * foreach ($workItems as $item) {
 *     try {
 *         $tasks[] = $scheduler->spawn(function() use ($item) {
 *             return processWorkItem($item);
 *         });
 *     } catch (ResourceLimitException $e) {
 *         // Queue remaining items for later processing
 *         $queue[] = $item;
 *         logger()->info('Task queued due to resource limits', [
 *             'queued_count' => count($queue)
 *         ]);
 *     }
 * }
 *
 * // Process spawned tasks
 * $results = $scheduler->all($tasks);
 *
 * // Later: process queued items when resources available
 * if (!empty($queue)) {
 *     processQueuedItems($queue);
 * }
 *
 * // Example 5: Dynamic limit adjustment based on load
 * function createSchedulerForLoad(int $loadLevel): FiberScheduler
 * {
 *     return match($loadLevel) {
 *         1 => new FiberScheduler(maxConcurrentTasks: 100),
 *         2 => new FiberScheduler(maxConcurrentTasks: 50),
 *         3 => new FiberScheduler(maxConcurrentTasks: 25),
 *         default => new FiberScheduler(maxConcurrentTasks: 10)
 *     };
 * }
 *
 * $currentLoad = getSystemLoad();
 * $scheduler = createSchedulerForLoad($currentLoad);
 *
 * try {
 *     $results = $scheduler->all($tasks);
 * } catch (ResourceLimitException $e) {
 *     // Load is too high, reduce concurrency further
 *     $scheduler = createSchedulerForLoad($currentLoad + 1);
 *     $results = $scheduler->all($tasks);
 * }
 *
 * // Example 6: Monitoring and alerting
 * $scheduler = new FiberScheduler(
 *     maxConcurrentTasks: 50,
 *     maxTaskExecutionSeconds: 120.0
 * );
 *
 * $limitHits = 0;
 *
 * foreach ($workload as $work) {
 *     try {
 *         $tasks[] = $scheduler->spawn(function() use ($work) {
 *             return processWork($work);
 *         });
 *     } catch (ResourceLimitException $e) {
 *         $limitHits++;
 *
 *         // Alert if hitting limits frequently
 *         if ($limitHits > 10) {
 *             sendAlert('Resource limits hit repeatedly', [
 *                 'limit_hits' => $limitHits,
 *                 'max_concurrent' => 50
 *             ]);
 *         }
 *
 *         // Back off and retry
 *         usleep(100000); // Wait 100ms
 *         $tasks[] = $scheduler->spawn(function() use ($work) {
 *             return processWork($work);
 *         });
 *     }
 * }
 * ```
 *
 * Prevention Strategies:
 * - Set appropriate maxConcurrentTasks based on system resources
 * - Use maxTaskExecutionSeconds to catch infinite loops early
 * - Monitor metrics to tune limits for your workload
 * - Implement task queuing for graceful degradation
 * - Use batch processing with controlled concurrency
 * - Profile tasks to understand typical execution times
 * - Adjust limits dynamically based on system load
 *
 * Choosing Limits:
 * - Start conservative (e.g., 50 tasks, 60s execution time)
 * - Monitor actual usage patterns with Metrics
 * - Increase limits gradually while monitoring memory/CPU
 * - Consider available system resources (RAM, CPU cores)
 * - Account for external resource limits (database connections)
 * - Test under peak load scenarios
 *
 * Best Practices:
 * - Always set resource limits in production environments
 * - Log ResourceLimitException with context for analysis
 * - Implement fallback strategies (queuing, retry, backoff)
 * - Monitor limit violations to detect problematic code
 * - Use Metrics to understand actual resource usage
 * - Alert on repeated limit violations
 * - Review and adjust limits based on production data
 *
 * Debugging:
 * - Check Metrics::getSummary() for concurrent task peaks
 * - Identify which tasks are hitting execution time limits
 * - Profile long-running tasks to find bottlenecks
 * - Review task spawn patterns for bursts
 * - Monitor system resources (memory, CPU) during limit hits
 * - Use logging to track limit violations over time
 * - Check if limits are too restrictive for legitimate workload
 *
 * vs Other Exceptions:
 * - ResourceLimitException: System-imposed resource constraints
 * - TimeoutException: Time-based operation limit
 * - CancelledException: Explicit user-requested cancellation
 *
 * @see \Glueful\Async\FiberScheduler
 * @see \Glueful\Async\Metrics
 * @see \Glueful\Async\Contracts\Task
 */
class ResourceLimitException extends AsyncException
{
}
