<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Events;

use Glueful\Events\Attributes\AsListener;
use Glueful\Events\EventDispatcher;
use Glueful\Events\EventService;
use Glueful\Events\EventSubscriberInterface;
use Glueful\Events\ListenerProvider;
use PHPUnit\Framework\TestCase;

/**
 * #[AsListener] shipped with the PSR-14 refactor but nothing read it, so annotated listeners
 * silently never ran. EventService::subscribe() now registers them.
 */
final class AsListenerSubscriptionTest extends TestCase
{
    protected function setUp(): void
    {
        ListenerCalls::$calls = [];
    }

    private function service(): EventService
    {
        $provider = new ListenerProvider();

        return new EventService(new EventDispatcher($provider), $provider);
    }

    public function testMethodLevelAttributesRegisterWithTheirPriority(): void
    {
        $events = $this->service();
        $events->subscribe(MethodListenerFixture::class);

        $events->dispatch(new OrderPlacedFixture());

        self::assertSame(['MethodListenerFixture::first', 'MethodListenerFixture::second'], ListenerCalls::$calls);
    }

    public function testClassLevelAttributeDefaultsToInvoke(): void
    {
        $events = $this->service();
        $events->subscribe(InvokableListenerFixture::class);

        $events->dispatch(new OrderPlacedFixture());

        self::assertSame(['InvokableListenerFixture::__invoke'], ListenerCalls::$calls);
    }

    public function testClassLevelAttributeCanNameItsMethod(): void
    {
        $events = $this->service();
        $events->subscribe(NamedMethodListenerFixture::class);

        $events->dispatch(new OrderPlacedFixture());
        $events->dispatch(new OrderShippedFixture());

        self::assertSame(
            ['NamedMethodListenerFixture::onPlaced', 'NamedMethodListenerFixture::onShipped'],
            ListenerCalls::$calls
        );
    }

    public function testSubscriberMapAndAttributesOnOneClassBothRegister(): void
    {
        $events = $this->service();
        $events->subscribe(HybridListenerFixture::class);

        $events->dispatch(new OrderPlacedFixture());
        $events->dispatch(new OrderShippedFixture());

        self::assertSame(['HybridListenerFixture::fromMap', 'HybridListenerFixture::fromAttribute'], ListenerCalls::$calls);
    }

    public function testAClassWithNoListenersIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->subscribe(NotAListenerFixture::class);
    }

    public function testAnAttributeNamingAMissingMethodIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service()->subscribe(MissingMethodListenerFixture::class);
    }
}

final class ListenerCalls
{
    /** @var list<string> */
    public static array $calls = [];
}

final class OrderPlacedFixture
{
}

final class OrderShippedFixture
{
}

final class MethodListenerFixture
{
    #[AsListener(OrderPlacedFixture::class, priority: 0)]
    public function second(OrderPlacedFixture $event): void
    {
        ListenerCalls::$calls[] = 'MethodListenerFixture::second';
    }

    #[AsListener(OrderPlacedFixture::class, priority: 10)]
    public function first(OrderPlacedFixture $event): void
    {
        ListenerCalls::$calls[] = 'MethodListenerFixture::first';
    }
}

#[AsListener(OrderPlacedFixture::class)]
final class InvokableListenerFixture
{
    public function __invoke(OrderPlacedFixture $event): void
    {
        ListenerCalls::$calls[] = 'InvokableListenerFixture::__invoke';
    }
}

#[AsListener(OrderPlacedFixture::class, method: 'onPlaced')]
#[AsListener(OrderShippedFixture::class, method: 'onShipped')]
final class NamedMethodListenerFixture
{
    public function onPlaced(OrderPlacedFixture $event): void
    {
        ListenerCalls::$calls[] = 'NamedMethodListenerFixture::onPlaced';
    }

    public function onShipped(OrderShippedFixture $event): void
    {
        ListenerCalls::$calls[] = 'NamedMethodListenerFixture::onShipped';
    }
}

final class HybridListenerFixture implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [OrderPlacedFixture::class => 'fromMap'];
    }

    public function fromMap(OrderPlacedFixture $event): void
    {
        ListenerCalls::$calls[] = 'HybridListenerFixture::fromMap';
    }

    #[AsListener(OrderShippedFixture::class)]
    public function fromAttribute(OrderShippedFixture $event): void
    {
        ListenerCalls::$calls[] = 'HybridListenerFixture::fromAttribute';
    }
}

final class NotAListenerFixture
{
}

#[AsListener(OrderPlacedFixture::class, method: 'doesNotExist')]
final class MissingMethodListenerFixture
{
}
