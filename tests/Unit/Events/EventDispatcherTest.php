<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Events;

use Glueful\Events\EventDispatcher;
use Glueful\Events\Listeners\ActivityLoggingSubscriber;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\ListenerProviderInterface;

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
}
