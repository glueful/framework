<?php

declare(strict_types=1);

namespace Glueful\Events;

use Glueful\Events\Attributes\AsListener;
use Psr\Container\ContainerInterface;

/**
 * Registers a listener class: its EventSubscriberInterface map and/or its #[AsListener]
 * attributes (on the class, naming a method or defaulting to __invoke, or on methods).
 */
final class SubscriberRegistrar
{
    public function __construct(
        private ListenerProvider $provider,
        private ?ContainerInterface $container = null
    ) {
    }

    public function add(string $subscriberClass): void
    {
        if (!class_exists($subscriberClass)) {
            throw new \InvalidArgumentException("Listener class does not exist: {$subscriberClass}");
        }

        $specs = [];
        if (is_subclass_of($subscriberClass, EventSubscriberInterface::class)) {
            foreach ($subscriberClass::getSubscribedEvents() as $event => $spec) {
                [$method, $priority] = is_array($spec) ? [$spec[0], $spec[1] ?? 0] : [$spec, 0];
                $specs[] = [$event, (string) $method, (int) $priority];
            }
        }
        $specs = array_merge($specs, $this->attributeSpecs($subscriberClass));

        if ($specs === []) {
            throw new \InvalidArgumentException(
                "{$subscriberClass} neither implements EventSubscriberInterface nor declares #[AsListener]."
            );
        }

        foreach ($specs as [$event, $method, $priority]) {
            $this->provider->addListener($event, $this->listenerFor($subscriberClass, $method), $priority);
        }
    }

    /**
     * @param class-string $subscriberClass
     * @return list<array{0:string,1:string,2:int}> [event, method, priority]
     */
    private function attributeSpecs(string $subscriberClass): array
    {
        $class = new \ReflectionClass($subscriberClass);
        $specs = [];

        foreach ($class->getAttributes(AsListener::class) as $attribute) {
            $listener = $attribute->newInstance();
            $method = $listener->method ?? '__invoke';
            if (!$class->hasMethod($method) || !$class->getMethod($method)->isPublic()) {
                throw new \InvalidArgumentException(
                    "#[AsListener] on {$subscriberClass} names no public method: {$method}"
                );
            }
            $specs[] = [$listener->event, $method, $listener->priority];
        }

        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(AsListener::class) as $attribute) {
                $listener = $attribute->newInstance();
                $specs[] = [$listener->event, $method->getName(), $listener->priority];
            }
        }

        return $specs;
    }

    private function listenerFor(string $subscriberClass, string $method): callable
    {
        if ($this->container !== null) {
            return new ContainerListener('@' . $subscriberClass . ':' . $method, $this->container);
        }

        // fallback: direct instantiation (useful in tests without a container)
        $candidate = [new $subscriberClass(), $method];
        if (!is_callable($candidate)) {
            throw new \InvalidArgumentException(
                "Subscriber {$subscriberClass} has no callable method: {$method}"
            );
        }

        return $candidate;
    }
}
