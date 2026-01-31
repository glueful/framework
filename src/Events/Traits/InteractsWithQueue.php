<?php

declare(strict_types=1);

namespace Glueful\Events\Traits;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Glueful\Events\QueueContextHolder;

/**
 * InteractsWithQueue Trait (migrated)
 *
 * Adds queue interaction helpers for events, using EventService
 * when available via ApplicationContext.
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
            $context = QueueContextHolder::getContext();
            if ($context === null || !$context->hasContainer()) {
                return $event;
            }

            $container = $context->getContainer();
            if ($container->has('queue')) {
                $queueManager = $container->get('queue');
                if (method_exists($queueManager, 'push')) {
                    // Serializable trait's toArray() is optional; fall back to public props
                    $payload = method_exists($event, 'toArray') ? $event->toArray() : get_object_vars($event);
                    $queueManager->push(static::class, $payload, $event->queue, $event->delay);
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
            $context = QueueContextHolder::getContext();
            if ($context !== null && $context->hasContainer()) {
                $container = $context->getContainer();
                if ($container->has('db')) {
                    $db = $container->get('db');
                    if (method_exists($db, 'afterCommit')) {
                        $db->afterCommit(function () use ($event) {
                            self::resolveEventService()?->dispatch($event);
                        });
                        return $event;
                    }
                }
            }
        } catch (\Throwable) {
            // Fall through to immediate dispatch
        }

        self::resolveEventService()?->dispatch($event);
        return $event;
    }

    private static function resolveEventService(): ?EventService
    {
        $context = QueueContextHolder::getContext();
        if ($context === null || !$context->hasContainer()) {
            return null;
        }

        try {
            $service = $context->getContainer()->get(EventService::class);
            return $service instanceof EventService ? $service : null;
        } catch (\Throwable) {
            return null;
        }
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
