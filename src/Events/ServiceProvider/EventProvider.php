<?php

declare(strict_types=1);

namespace Glueful\Events\ServiceProvider;

use Glueful\Container\Definition\{DefinitionInterface, FactoryDefinition, AliasDefinition};
use Glueful\Container\Providers\BaseServiceProvider;

final class EventProvider extends BaseServiceProvider
{
    /**
     * @return array<string, DefinitionInterface|callable|mixed>
     */
    public function defs(): array
    {
        $defs = [];

        // Glueful Events: PSR-14 implementation
        $defs[\Glueful\Events\ListenerProvider::class] =
            $this->autowire(\Glueful\Events\ListenerProvider::class);

        // Tracing default: NullEventTracer
        $defs[\Glueful\Events\Tracing\NullEventTracer::class] =
            $this->autowire(\Glueful\Events\Tracing\NullEventTracer::class);
        $defs[\Glueful\Events\Tracing\EventTracerInterface::class] = new AliasDefinition(
            \Glueful\Events\Tracing\EventTracerInterface::class,
            \Glueful\Events\Tracing\NullEventTracer::class
        );

        // EventDispatcher bound to provider + tracer and exposed via PSR interface
        $defs[\Glueful\Events\EventDispatcher::class] = new FactoryDefinition(
            \Glueful\Events\EventDispatcher::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Events\EventDispatcher(
                $c->get(\Glueful\Events\ListenerProvider::class),
                $c->get(\Glueful\Events\Tracing\EventTracerInterface::class)
            )
        );
        $defs[\Psr\EventDispatcher\EventDispatcherInterface::class] = new AliasDefinition(
            \Psr\EventDispatcher\EventDispatcherInterface::class,
            \Glueful\Events\EventDispatcher::class
        );

        // Subscriber registrar to add subscribers lazily via container
        $defs[\Glueful\Events\SubscriberRegistrar::class] = new FactoryDefinition(
            \Glueful\Events\SubscriberRegistrar::class,
            fn(\Psr\Container\ContainerInterface $c) => new \Glueful\Events\SubscriberRegistrar(
                $c->get(\Glueful\Events\ListenerProvider::class),
                $c
            )
        );

        // Core listeners available for registration
        $defs[\Glueful\Events\Listeners\CacheInvalidationListener::class] =
            $this->autowire(\Glueful\Events\Listeners\CacheInvalidationListener::class);
        $defs[\Glueful\Events\Listeners\SecurityMonitoringListener::class] =
            $this->autowire(\Glueful\Events\Listeners\SecurityMonitoringListener::class);
        $defs[\Glueful\Events\Listeners\PerformanceMonitoringListener::class] =
            $this->autowire(\Glueful\Events\Listeners\PerformanceMonitoringListener::class);

        return $defs;
    }
}
