<?php

declare(strict_types=1);

namespace Glueful\Events;

use Glueful\Container\Autowire\ReflectionResolver;
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

        // Resolve every listener before registering any, so a failure leaves nothing half-subscribed.
        $instance = null;
        $listeners = [];
        foreach ($specs as [$event, $method, $priority]) {
            $listeners[] = [$event, $this->listenerFor($subscriberClass, $method, $instance), $priority];
        }
        foreach ($listeners as [$event, $listener, $priority]) {
            $this->provider->addListener($event, $listener, $priority);
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

    private function listenerFor(string $subscriberClass, string $method, ?object &$instance): callable
    {
        // Registered services stay lazy: resolved through the container on first dispatch.
        if ($this->container !== null && $this->container->has($subscriberClass)) {
            return new ContainerListener('@' . $subscriberClass . ':' . $method, $this->container);
        }

        // Unregistered class: construct it now (autowired from the container when there is one),
        // so an unresolvable listener fails here instead of being silently skipped at dispatch.
        $instance ??= $this->construct($subscriberClass);
        $candidate = [$instance, $method];
        if (!is_callable($candidate)) {
            throw new \InvalidArgumentException(
                "Subscriber {$subscriberClass} has no callable method: {$method}"
            );
        }

        return $candidate;
    }

    private function construct(string $subscriberClass): object
    {
        try {
            if ($this->container !== null) {
                return ReflectionResolver::shared()->resolve($subscriberClass, $this->container);
            }

            return new $subscriberClass();
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(
                "Listener {$subscriberClass} is not a registered service and could not be constructed: "
                . $e->getMessage(),
                0,
                $e
            );
        }
    }
}
