<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Events;

use Glueful\Events\EventDispatcher;
use Glueful\Events\EventService;
use Glueful\Events\ListenerProvider;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

final class EventServiceTest extends TestCase
{
    /**
     * dispatchOrFail() must delegate to the concrete EventDispatcher's strict path: the first
     * listener's ORIGINAL exception propagates and a later listener never runs.
     */
    public function testDispatchOrFailDelegatesToTheConcreteEventDispatcherAndRethrowsListenerFailures(): void
    {
        $provider = new ListenerProvider();
        $dispatcher = new EventDispatcher($provider);
        $service = new EventService($dispatcher, $provider);

        $original = new \RuntimeException('listener boom');
        $ranAfter = false;
        $provider->addListener(\stdClass::class, static function () use ($original): void {
            throw $original;
        });
        $provider->addListener(\stdClass::class, function () use (&$ranAfter): void {
            $ranAfter = true;
        });

        $event = new \stdClass();

        try {
            $service->dispatchOrFail($event);
            self::fail('Expected the listener exception to propagate.');
        } catch (\RuntimeException $caught) {
            self::assertSame($original, $caught);
        }

        self::assertFalse($ranAfter, 'A listener registered after the throwing one must not run.');
    }

    public function testDispatchOrFailReturnsTheEventWhenAllListenersSucceed(): void
    {
        $provider = new ListenerProvider();
        $dispatcher = new EventDispatcher($provider);
        $service = new EventService($dispatcher, $provider);

        $event = new \stdClass();

        $result = $service->dispatchOrFail($event);

        self::assertSame($event, $result);
    }

    /**
     * EventService's constructor keeps the PSR EventDispatcherInterface type (not downgraded to
     * the concrete class). dispatchOrFail() must fail closed — cleanly and without invoking the
     * underlying dispatcher at all — when that interface isn't backed by the concrete
     * EventDispatcher that actually implements the strict path.
     */
    public function testDispatchOrFailRefusesAnUnderlyingDispatcherThatCannotDispatchStrictly(): void
    {
        $provider = new ListenerProvider();
        $fakeDispatcher = new class implements EventDispatcherInterface {
            public bool $called = false;

            public function dispatch(object $event): object
            {
                $this->called = true;
                return $event;
            }
        };

        $service = new EventService($fakeDispatcher, $provider);

        $this->expectException(\LogicException::class);

        try {
            $service->dispatchOrFail(new \stdClass());
        } finally {
            self::assertFalse($fakeDispatcher->called, 'The fake dispatcher must not be invoked at all.');
        }
    }

    /**
     * Regression guard: dispatch() must remain the fault-isolated, always-continues path,
     * unaffected by the addition of dispatchOrFail().
     */
    public function testDispatchStillDelegatesNormallyAndIsUnaffectedByDispatchOrFail(): void
    {
        $provider = new ListenerProvider();
        $dispatcher = new EventDispatcher($provider);
        $service = new EventService($dispatcher, $provider);

        $ran = [];
        $provider->addListener(\stdClass::class, static function () use (&$ran): void {
            throw new \RuntimeException('listener boom');
        });
        $provider->addListener(\stdClass::class, function () use (&$ran): void {
            $ran[] = 'second';
        });

        $event = new \stdClass();
        $result = $service->dispatch($event);

        self::assertSame($event, $result);
        self::assertSame(['second'], $ran);
    }
}
