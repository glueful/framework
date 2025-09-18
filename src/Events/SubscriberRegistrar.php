<?php

declare(strict_types=1);

namespace Glueful\Events;

use Psr\Container\ContainerInterface;

final class SubscriberRegistrar
{
    public function __construct(
        private ListenerProvider $provider,
        private ?ContainerInterface $container = null
    ) {
    }

    public function add(string $subscriberClass): void
    {
        $map = $subscriberClass::getSubscribedEvents();
        foreach ($map as $event => $spec) {
            [$method, $priority] = is_array($spec) ? [$spec[0], $spec[1] ?? 0] : [$spec, 0];

            if ($this->container !== null) {
                $lazyRef = '@' . $subscriberClass . ':' . $method;
                $listener = new ContainerListener($lazyRef, $this->container);
            } else {
                // fallback: direct instantiation (useful in tests without a container)
                $listener = [new $subscriberClass(), $method];
            }
            $this->provider->addListener($event, $listener, $priority);
        }
    }
}
