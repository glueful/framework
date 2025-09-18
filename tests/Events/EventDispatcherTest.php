<?php

declare(strict_types=1);

namespace Glueful\Tests\Events;

use Glueful\Events\Event;
use Glueful\Events\EventDispatcher;
use Glueful\Events\ListenerProvider;
use Glueful\Events\Contracts\BaseEvent;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class EventDispatcherTest extends TestCase
{
    public function test_ordering_and_inheritance_and_stop_propagation(): void
    {
        $provider = new ListenerProvider();
        $dispatcher = new EventDispatcher($provider);
        Event::bootstrap($dispatcher, $provider);

        $log = [];

        $parent = new class extends BaseEvent {};
        $childClass = get_class(new class($parent) extends class extends BaseEvent {} {});

        Event::listen($childClass, function($e) use (&$log) { $log[] = 'a'; }, 100);
        Event::listen($childClass, function($e) use (&$log) { $log[] = 'b'; }, 100);
        Event::listen($parent::class, function($e) use (&$log) { $log[] = 'c'; });

        $event = new $childClass();
        Event::dispatch($event);

        $this->assertSame(['a','b','c'], $log);

        // stop propagation
        $log = [];
        Event::listen($childClass, function($e) { $e->stopPropagation(); }, 200);
        Event::listen($childClass, function($e) use (&$log) { $log[] = 'should-not-run'; }, -100);

        Event::dispatch(new $childClass());
        $this->assertSame(['a','b','c'], array_slice($log, 0, 3)); // previous entries untouched, new one stopped before appending
    }

    public function test_lazy_container_listener(): void
    {
        $provider = new ListenerProvider();
        $dispatcher = new EventDispatcher($provider);

        $container = new class implements ContainerInterface {
            public function get(string $id)
            {
                if ($id === 'svc') {
                    return new class {
                        public array $log = [];
                        public function handle($e) { $this->log[] = 'handled'; }
                    };
                }
                throw new class extends \Exception implements \Psr\Container\NotFoundExceptionInterface {};
            }
            public function has(string $id): bool { return $id === 'svc'; }
        };

        Event::bootstrap($dispatcher, $provider, $container);

        $eventClass = get_class(new class extends BaseEvent {});
        Event::listen($eventClass, '@svc:handle');

        $e = new $eventClass();
        Event::dispatch($e);

        $this->assertTrue(Event::hasListeners($eventClass));
        $this->assertNotEmpty(Event::getListeners($eventClass));
    }
}
