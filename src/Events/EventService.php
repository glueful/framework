<?php

declare(strict_types=1);

namespace Glueful\Events;

use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Instance-based event service (replaces static Event facade).
 */
final class EventService
{
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ListenerProvider $provider,
        private readonly ?ContainerInterface $container = null,
    ) {
    }

    public function dispatch(object $event): object
    {
        return $this->dispatcher->dispatch($event);
    }

    /**
     * Strict dispatch: rethrows the ORIGINAL exception from the first listener that fails
     * (after it has been reported through the same logging path {@see dispatch()} uses), which
     * stops dispatching — listeners registered after the failing one do not run.
     *
     * This is for callers that need a listener failure to be observable and re-driveable rather
     * than fault-isolated — e.g. a durable event store (payment-provider webhooks/chargebacks)
     * that redelivers on error. Delivery is therefore **at-least-once**: a caller may catch the
     * exception and re-dispatch the same event, so every listener wired to a strictly-dispatched
     * event MUST be idempotent.
     *
     * {@see dispatch()} is unaffected and keeps its fault-isolated, always-continues behavior.
     *
     * @throws \Throwable the original exception thrown by the first failing listener
     */
    public function dispatchOrFail(object $event): object
    {
        if (!$this->dispatcher instanceof EventDispatcher) {
            throw new \LogicException(
                'EventService::dispatchOrFail() requires the underlying dispatcher to be an instance of '
                . EventDispatcher::class . ', got ' . get_debug_type($this->dispatcher) . '.'
            );
        }

        return $this->dispatcher->dispatchOrFail($event);
    }

    /**
     * Register a listener.
     * $listener can be:
     *  - callable
     *  - string '@serviceId' or '@serviceId:method' (lazy, via container)
     */
    public function addListener(string $eventClass, callable|string $listener, int $priority = 0): void
    {
        if (is_string($listener) && str_starts_with($listener, '@')) {
            if ($this->container === null) {
                throw new \LogicException('EventService listener references require a container.');
            }
            $lazy = new ContainerListener($listener, $this->container);
            $this->provider->addListener($eventClass, $lazy, $priority);
            return;
        }
        $this->provider->addListener($eventClass, $listener, $priority);
    }

    public function subscribe(string $subscriberClass): void
    {
        $registrar = new SubscriberRegistrar($this->provider, $this->container);
        $registrar->add($subscriberClass);
    }

    /**
     * @return array<int, callable>
     */
    public function getListeners(string $eventClass): array
    {
        return $this->provider->getListenersForType($eventClass);
    }

    public function hasListeners(string $eventClass): bool
    {
        return count($this->getListeners($eventClass)) > 0;
    }
}
