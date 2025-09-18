<?php

declare(strict_types=1);

namespace Glueful\Events\Traits;

use Glueful\Events\Event;

/**
 * InteractsWithQueue Trait (migrated)
 *
 * Adds queue interaction helpers for events, compatible with the
 * PSR-14-based Event facade. Uses container() if available.
 */
trait InteractsWithQueue
{
    public ?string $connection = null;
    public ?string $queue = null;
    public ?int $delay = null;

    public function onConnection(string $connection): static
    {
        $this->connection = $connection;
        return $this;
    }

    public function onQueue(string $queue): static
    {
        $this->queue = $queue;
        return $this;
    }

    public function delay(int $seconds): static
    {
        $this->delay = $seconds;
        return $this;
    }

    public static function dispatchToQueue(...$args): static
    {
        $event = new static(...$args);

        try {
            if (function_exists('container')) {
                $container = container();
                if ($container && $container->has('queue')) {
                    $queueManager = $container->get('queue');
                    if (method_exists($queueManager, 'push')) {
                        // Serializable trait's toArray() is optional; fall back to public props
                        $payload = method_exists($event, 'toArray') ? $event->toArray() : get_object_vars($event);
                        $queueManager->push(static::class, $payload, $event->queue, $event->delay);
                    }
                }
            }
        } catch (\Throwable) {
            // Silently ignore when queue system is unavailable
        }

        return $event;
    }

    public static function dispatchAfterCommit(...$args): static
    {
        $event = new static(...$args);

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
            // Fall through to immediate dispatch
        }

        Event::dispatch($event);
        return $event;
    }

    public function getQueueConfig(): array
    {
        return [
            'connection' => $this->connection,
            'queue' => $this->queue,
            'delay' => $this->delay,
        ];
    }
}

