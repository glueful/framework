<?php

declare(strict_types=1);

namespace Glueful\Queue;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Queue\Contracts\JobInterface;

/**
 * Job Handler Resolver
 *
 * Single gate for turning a class name read from a stored payload (queue
 * tables, Redis queues, scheduled_jobs) into a live handler instance.
 *
 * Stored payloads are data, not code: anyone who can write to the queue
 * backend chooses the class name. Requiring the JobInterface marker before
 * instantiation means an attacker-supplied class name cannot trigger an
 * arbitrary constructor — only classes that opted into being queue jobs
 * are eligible.
 */
final class JobHandlerResolver
{
    /**
     * Resolve a job handler class name from a stored payload into an instance.
     *
     * @param string $class Handler class name from the stored payload
     * @param array<string, mixed> $data Payload data, passed to Job-subclass constructors
     * @param ApplicationContext|null $context Application context for container resolution
     * @return JobInterface Handler instance
     * @throws \RuntimeException If the class is missing or not a job class
     */
    public static function resolve(string $class, array $data = [], ?ApplicationContext $context = null): JobInterface
    {
        if (!class_exists($class)) {
            throw new \RuntimeException("Job class '{$class}' not found");
        }

        if (!is_subclass_of($class, JobInterface::class)) {
            throw new \RuntimeException(
                "Refusing to run job class '{$class}': handlers instantiated from stored payloads must implement "
                    . JobInterface::class
            );
        }

        // Canonical framework jobs share the base-class constructor; hand them
        // their payload data (and context) directly so getData() works.
        if (is_subclass_of($class, Job::class)) {
            return new $class($data, $context);
        }

        // Other JobInterface implementations: prefer container-managed
        // construction so dependencies are injected.
        if ($context !== null && function_exists('container')) {
            try {
                $container = container($context);
                if ($container->has($class)) {
                    $instance = $container->get($class);
                    if ($instance instanceof JobInterface) {
                        return $instance;
                    }
                }
            } catch (\Throwable) {
                // Fall through to direct instantiation.
            }
        }

        return new $class();
    }
}
