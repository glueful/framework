<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Events;

use Glueful\Events\EventDispatcher;
use Glueful\Events\Listeners\ActivityLoggingSubscriber;
use Glueful\Events\Tracing\EventTracerInterface;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;

final class EventDispatcherTest extends TestCase
{
    /**
     * A listener that throws (e.g. an unresolvable container dependency) must not abort the
     * dispatch and starve the listeners after it — the exact failure that silently broke
     * auth-event delivery to downstream subscribers (audit, cache invalidation).
     */
    public function testAThrowingListenerDoesNotStarveSubsequentListeners(): void
    {
        $ran = [];
        $listeners = [
            static function (object $e): void {
                throw new \RuntimeException('listener boom');
            },
            function (object $e) use (&$ran): void {
                $ran[] = 'second';
            },
        ];

        $provider = new class ($listeners) implements ListenerProviderInterface {
            /** @param list<callable> $listeners */
            public function __construct(private array $listeners)
            {
            }

            public function getListenersForEvent(object $event): iterable
            {
                return $this->listeners;
            }
        };

        $dispatcher = new EventDispatcher($provider);
        $event = new \stdClass();

        // Must not throw despite the first listener throwing.
        $result = $dispatcher->dispatch($event);

        self::assertSame($event, $result);
        self::assertSame(['second'], $ran, 'The listener after the throwing one must still run.');
    }

    public function testActivityLoggingSubscriberIsConstructibleWithoutAnExplicitLogger(): void
    {
        // Was unresolvable: LogManager isn't container-registered, so a non-nullable param made
        // the whole subscriber fail to build. It now falls back to the singleton.
        $subscriber = new ActivityLoggingSubscriber();
        self::assertInstanceOf(ActivityLoggingSubscriber::class, $subscriber);
    }

    /**
     * dispatchOrFail() is the strict counterpart used by durable-delivery callers (e.g. a
     * payment-provider event store that needs to redeliver on failure): the FIRST throwing
     * listener's ORIGINAL exception must propagate out, and any listener registered after it
     * must not run.
     */
    public function testDispatchOrFailRethrowsTheOriginalExceptionAndStopsSubsequentListeners(): void
    {
        $ran = [];
        $original = new \RuntimeException('listener boom');
        $listeners = [
            static function (object $e) use ($original): void {
                throw $original;
            },
            function (object $e) use (&$ran): void {
                $ran[] = 'second';
            },
        ];

        $dispatcher = new EventDispatcher($this->providerFor($listeners));
        $event = new \stdClass();

        try {
            $dispatcher->dispatchOrFail($event);
            self::fail('Expected the listener exception to propagate.');
        } catch (\RuntimeException $caught) {
            self::assertSame($original, $caught, 'The ORIGINAL exception instance must propagate, not a wrapper.');
            self::assertSame('listener boom', $caught->getMessage());
        }

        self::assertSame([], $ran, 'A listener registered after the throwing one must not run.');
    }

    /**
     * The failure must still go through the same reporting path dispatch() uses
     * (reportListenerError() -> error_log()) before it is rethrown.
     */
    public function testDispatchOrFailStillReportsTheFailureBeforeRethrowing(): void
    {
        $listeners = [
            static function (object $e): void {
                throw new \RuntimeException('listener boom');
            },
        ];

        $dispatcher = new EventDispatcher($this->providerFor($listeners));
        $event = new \stdClass();

        $tmp = tempnam(sys_get_temp_dir(), 'glueful-events-');
        $prevLog = ini_set('error_log', $tmp);
        try {
            try {
                $dispatcher->dispatchOrFail($event);
                self::fail('Expected the listener exception to propagate.');
            } catch (\RuntimeException $e) {
                // expected — assertions happen below on the log file
            }

            $logged = (string) file_get_contents($tmp);
            self::assertStringContainsString('Event listener failed for stdClass', $logged);
            self::assertStringContainsString('listener boom', $logged);
        } finally {
            ini_set('error_log', $prevLog);
            @unlink($tmp);
        }
    }

    /**
     * Regression guard: the exact same event + throwing-then-clean listener pair must still be
     * fault-isolated under plain dispatch() — dispatchOrFail() must not have changed dispatch().
     */
    public function testDispatchStillSwallowsAndContinuesForTheSameListenersThatDispatchOrFailRejects(): void
    {
        $ranDispatch = [];
        $listenersForDispatch = [
            static function (object $e): void {
                throw new \RuntimeException('listener boom');
            },
            function (object $e) use (&$ranDispatch): void {
                $ranDispatch[] = 'second';
            },
        ];

        $dispatcher = new EventDispatcher($this->providerFor($listenersForDispatch));
        $event = new \stdClass();

        $result = $dispatcher->dispatch($event);

        self::assertSame($event, $result, 'dispatch() must still return the event, not throw.');
        self::assertSame(['second'], $ranDispatch, 'dispatch() must still fault-isolate and continue.');
    }

    public function testDispatchOrFailReturnsTheEventWhenAllListenersSucceed(): void
    {
        $ran = [];
        $listeners = [
            function (object $e) use (&$ran): void {
                $ran[] = 'first';
            },
            function (object $e) use (&$ran): void {
                $ran[] = 'second';
            },
        ];

        $dispatcher = new EventDispatcher($this->providerFor($listeners));
        $event = new \stdClass();

        $result = $dispatcher->dispatchOrFail($event);

        self::assertSame($event, $result);
        self::assertSame(['first', 'second'], $ran);
    }

    public function testDispatchOrFailHonorsStoppablePropagationShortCircuit(): void
    {
        $ran = [];
        $event = new class implements StoppableEventInterface {
            private bool $stopped = false;

            public function stop(): void
            {
                $this->stopped = true;
            }

            public function isPropagationStopped(): bool
            {
                return $this->stopped;
            }
        };

        $listeners = [
            function (object $e) use (&$ran): void {
                $ran[] = 'first';
                $e->stop();
            },
            function (object $e) use (&$ran): void {
                $ran[] = 'second'; // must not run: propagation was stopped by the first listener
            },
        ];

        $dispatcher = new EventDispatcher($this->providerFor($listeners));

        $result = $dispatcher->dispatchOrFail($event);

        self::assertSame($event, $result);
        self::assertSame(['first'], $ran, 'A listener must not run once propagation has been stopped.');
    }

    public function testDispatchOrFailInvokesTracerHooksLikeDispatch(): void
    {
        $tracer = new class implements EventTracerInterface {
            /** @var list<string> */
            public array $calls = [];
            public ?\Throwable $reportedError = null;

            public function startEvent(string $eventClass, int $listenerCount): void
            {
                $this->calls[] = "start:{$eventClass}:{$listenerCount}";
            }

            public function listenerDone(string $eventClass, callable $listener, int $durationNs): void
            {
                $this->calls[] = 'done';
            }

            public function listenerError(string $eventClass, callable $listener, \Throwable $e): void
            {
                $this->calls[] = 'error';
                $this->reportedError = $e;
            }

            public function endEvent(string $eventClass): void
            {
                $this->calls[] = 'end';
            }
        };

        $original = new \RuntimeException('listener boom');
        $listeners = [
            static function (object $e) use ($original): void {
                throw $original;
            },
            function (object $e): void {
                self::fail('Must not run: dispatchOrFail should have stopped after the failure.');
            },
        ];

        $dispatcher = new EventDispatcher($this->providerFor($listeners), $tracer);
        $event = new \stdClass();

        try {
            $dispatcher->dispatchOrFail($event);
            self::fail('Expected the listener exception to propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame($original, $e);
        }

        self::assertSame($original, $tracer->reportedError);
        self::assertSame(['start:stdClass:2', 'error', 'done', 'end'], $tracer->calls);
    }

    /**
     * @param list<callable> $listeners
     */
    private function providerFor(array $listeners): ListenerProviderInterface
    {
        return new class ($listeners) implements ListenerProviderInterface {
            /** @param list<callable> $listeners */
            public function __construct(private array $listeners)
            {
            }

            public function getListenersForEvent(object $event): iterable
            {
                return $this->listeners;
            }
        };
    }
}
