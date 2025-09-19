<?php

declare(strict_types=1);

namespace Glueful\Events;

use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

final class Event
{
    private static ?EventDispatcherInterface $dispatcher = null;
    private static ?ListenerProvider $provider = null;
    private static ?ContainerInterface $container = null;

    /**
     * Bootstrap the facade with core services (tests or custom bootstraps can call this).
     */
    public static function bootstrap(
        EventDispatcherInterface $dispatcher,
        ListenerProvider $provider,
        ?ContainerInterface $container = null
    ): void {
        self::$dispatcher = $dispatcher;
        self::$provider = $provider;
        self::$container = $container;
    }

    public static function dispatch(object $event): object
    {
        self::ensureBootstrapped();
        return self::$dispatcher->dispatch($event);
    }

    /**
     * Register a listener.
     * $listener can be:
     *  - callable
     *  - string '@serviceId' or '@serviceId:method' (lazy, via container)
     */
    public static function listen(string $eventClass, callable|string $listener, int $priority = 0): void
    {
        self::ensureBootstrapped();
        if (is_string($listener) && str_starts_with($listener, '@')) {
            if (self::$container === null) {
                throw new \LogicException("Event::listen called with service reference but no container is set.");
            }
            $lazy = new ContainerListener($listener, self::$container);
            self::$provider->addListener($eventClass, $lazy, $priority);
            return;
        }
        self::$provider->addListener($eventClass, $listener, $priority);
    }

    /**
     * Subscribe a subscriber class with static getSubscribedEvents map.
     */
    public static function subscribe(string $subscriberClass): void
    {
        self::ensureBootstrapped();
        $registrar = new SubscriberRegistrar(self::$provider, self::$container);
        $registrar->add($subscriberClass);
    }

    /**
     * @return array<int, callable> materialized listeners for the given class
     */
    public static function getListeners(string $eventClass): array
    {
        self::ensureBootstrapped();
        return self::$provider->getListenersForType($eventClass);
    }

    public static function hasListeners(string $eventClass): bool
    {
        return count(self::getListeners($eventClass)) > 0;
    }

    private static function ensureBootstrapped(): void
    {
        if (self::$dispatcher === null || self::$provider === null) {
            throw new \LogicException(
                'Event facade not bootstrapped. Call Event::bootstrap(...) during application boot.'
            );
        }
    }
}
