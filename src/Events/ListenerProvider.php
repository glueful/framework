<?php

declare(strict_types=1);

namespace Glueful\Events;

use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * ListenerProvider with stable priority order and de-dup across inheritance paths.
 */
final class ListenerProvider implements ListenerProviderInterface
{
    private InheritanceResolver $resolver;

    /** @var array<string, array<int, array<int, callable>>> [eventType][priority][seq] = listener */
    private array $listeners = [];

    /** @var array<string, array<int, callable>> Cache of resolved listeners per concrete class */
    private array $cache = [];

    private int $seq = 0;

    public function __construct(?InheritanceResolver $resolver = null)
    {
        $this->resolver = $resolver ?? new InheritanceResolver();
    }

    /**
     * Register a listener for an event type (class/interface) with priority.
     */
    public function addListener(string $eventType, callable $listener, int $priority = 0): void
    {
        $this->listeners[$eventType][$priority][++$this->seq] = $listener;
        // Simplicity: clear all caches on registration (boot-time registrations expected)
        $this->cache = [];
    }

    /**
     * PSR-14: return iterables of listeners for the given event object.
     * We return a materialized array for safe counting and re-iteration.
     * @param object $event
     * @return iterable<callable>
     */
    public function getListenersForEvent(object $event): iterable
    {
        $class = $event::class;
        return $this->getListenersForType($class);
    }

    /**
     * Resolve listeners for a class name (used by debug tooling / facade helpers).
     * @return array<int, callable>
     */
    public function getListenersForType(string $class): array
    {
        if (!isset($this->cache[$class])) {
            $this->cache[$class] = $this->resolve($class);
        }
        return $this->cache[$class];
    }

    /**
     * @return array<int, callable>
     */
    private function resolve(string $class): array
    {
        $types = $this->resolver->getEventTypes($class);
        $bucket = []; // rows: ['priority'=>int,'seq'=>int,'listener'=>callable,'id'=>string]

        foreach ($types as $t) {
            if (!isset($this->listeners[$t])) {
                continue;
            }
            foreach ($this->listeners[$t] as $prio => $bySeq) {
                foreach ($bySeq as $seq => $listener) {
                    $bucket[] = [
                        'priority' => (int)$prio,
                        'seq' => (int)$seq,
                        'listener' => $listener,
                        'id' => self::idOf($listener),
                    ];
                }
            }
        }

        // Sort: priority DESC, sequence ASC (stable for same priority)
        usort($bucket, static function (array $a, array $b): int {
            $cmp = $b['priority'] <=> $a['priority'];
            return $cmp !== 0 ? $cmp : ($a['seq'] <=> $b['seq']);
        });

        // De-dup by identity
        $seen = [];
        $out = [];
        foreach ($bucket as $row) {
            if (isset($seen[$row['id']])) {
                continue;
            }
            $seen[$row['id']] = true;
            $out[] = $row['listener'];
        }

        return $out;
    }

    private static function idOf(callable $c): string
    {
        if (is_array($c)) {
            [$objOrClass, $method] = $c;
            if (is_object($objOrClass)) {
                return spl_object_hash($objOrClass) . '::' . $method;
            }
            return $objOrClass . '::' . $method;
        }
        if ($c instanceof \Closure) {
            return spl_object_hash($c);
        }
        if (is_object($c) && method_exists($c, '__invoke')) {
            return spl_object_hash($c);
        }
        // Fallback for functions or other callables
        if (is_string($c)) {
            return $c;
        }
        throw new \InvalidArgumentException('Unable to generate ID for callable');
    }
}
