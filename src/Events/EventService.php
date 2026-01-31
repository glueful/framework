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
