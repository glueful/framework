<?php

declare(strict_types=1);

namespace Glueful\Events;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;
use Glueful\Events\Tracing\EventTracerInterface;
use Glueful\Events\Tracing\NullEventTracer;

final class EventDispatcher implements EventDispatcherInterface
{
    public function __construct(
        private ListenerProviderInterface $provider,
        private ?EventTracerInterface $tracer = null
    ) {
        $this->tracer ??= new NullEventTracer();
    }

    public function dispatch(object $event): object
    {
        $listeners = $this->provider->getListenersForEvent($event);

        // Only materialize when tracing to keep hot path lean
        $materialized = null;
        if (!$this->tracer instanceof NullEventTracer) {
            $materialized = is_array($listeners) ? $listeners : iterator_to_array($listeners, false);
            $this->tracer->startEvent($event::class, count($materialized));
        }

        try {
            foreach ($materialized ?? $listeners as $listener) {
                if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                    break;
                }

                // Fault isolation: a listener that throws (a resolution failure or a runtime
                // error) must NOT abort the dispatch and starve the listeners after it. Catch,
                // report, and continue — one broken/misconfigured listener can't take the rest
                // of the chain (e.g. an audit/cache listener) down with it.
                if ($this->tracer instanceof NullEventTracer) {
                    try {
                        $listener($event);
                    } catch (\Throwable $e) {
                        $this->reportListenerError($event, $e);
                    }
                } else {
                    $start = hrtime(true);
                    try {
                        $listener($event);
                    } catch (\Throwable $e) {
                        $this->tracer->listenerError($event::class, $listener, $e);
                        $this->reportListenerError($event, $e);
                    } finally {
                        $this->tracer->listenerDone($event::class, $listener, hrtime(true) - $start);
                    }
                }
            }
        } finally {
            if (!$this->tracer instanceof NullEventTracer) {
                $this->tracer->endEvent($event::class);
            }
        }

        return $event;
    }

    private function reportListenerError(object $event, \Throwable $e): void
    {
        error_log(sprintf(
            'Event listener failed for %s: %s in %s:%d',
            $event::class,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }
}
