<?php

declare(strict_types=1);

namespace Glueful\Events\Traits;

use Glueful\Events\Event;

/**
 * InteractsWithQueue Trait
 *
 * Adds queue interaction functionality to events.
 * Allows events to be processed asynchronously via the queue system.
 */
trait InteractsWithQueue
{
    /**
     * The queue connection to use
     */
    public ?string $connection = null;

    /**
     * The queue to dispatch the event to
     */
    public ?string $queue = null;

    /**
     * The delay before processing the event
     */
    public ?int $delay = null;

    /**
     * Set the queue connection
     */
    public function onConnection(string $connection): static
    {
        $this->connection = $connection;
        return $this;
    }

    /**
     * Set the queue name
     */
    public function onQueue(string $queue): static
    {
        $this->queue = $queue;
        return $this;
    }

    /**
     * Set the delay in seconds
     */
    public function delay(int $seconds): static
    {
        $this->delay = $seconds;
        return $this;
    }

    /**
     * Dispatch the event to the queue
     */
    public static function dispatchToQueue(...$args): static
    {
        $event = new static(...$args);

        // Queue the event for async processing
        // This integrates with Glueful's queue system via container
        try {
            if (function_exists('container')) {
                $container = container();
                if ($container && $container->has('queue')) {
                    $queueManager = $container->get('queue');
                    if (method_exists($queueManager, 'push')) {
                        $queueManager->push(static::class, $event->toArray(), $event->queue, $event->delay);
                    }
                }
            }
        } catch (\Throwable) {
            // Silently fail if queue system is not available
        }

        return $event;
    }

    /**
     * Dispatch the event after the current database transaction commits
     */
    public static function dispatchAfterCommit(...$args): static
    {
        $event = new static(...$args);

        // This integrates with Glueful's database transaction system via container
        try {
            if (function_exists('container')) {
                $container = container();
                if ($container && $container->has('db')) {
                    $db = $container->get('db');
                    if (method_exists($db, 'afterCommit')) {
                        $db->afterCommit(function () use ($event) {
                            Event::dispatch($event);
                        });
                        return $event;
                    }
                }
            }
        } catch (\Throwable) {
            // Continue to fallback if database system is not available
        }

        // Fallback to immediate dispatch
        Event::dispatch($event);

        return $event;
    }

    /**
     * Get queue configuration for this event
     */
    public function getQueueConfig(): array
    {
        return [
            'connection' => $this->connection,
            'queue' => $this->queue,
            'delay' => $this->delay
        ];
    }
}
