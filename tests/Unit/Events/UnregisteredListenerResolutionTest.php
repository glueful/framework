<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Events;

use Glueful\Container\Container;
use Glueful\Container\Definition\FactoryDefinition;
use Glueful\Events\Attributes\AsListener;
use Glueful\Events\EventDispatcher;
use Glueful\Events\EventService;
use Glueful\Events\ListenerProvider;
use PHPUnit\Framework\TestCase;

/**
 * With a container, listener classes that were not registered as services used to be wired as
 * lazy '@Class:method' refs that failed at dispatch — the failure was logged and skipped, so the
 * listener silently never ran. They are now constructed via the container's autowire resolver,
 * and a class that can be neither resolved nor constructed is rejected at subscribe() time.
 */
final class UnregisteredListenerResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        UnregisteredListenerCalls::$calls = [];
        UnregisteredListenerCalls::$constructed = 0;
    }

    private function service(Container $container): EventService
    {
        $provider = new ListenerProvider();

        return new EventService(new EventDispatcher($provider), $provider, $container);
    }

    public function testUnregisteredNoArgListenerRunsWithAContainer(): void
    {
        $events = $this->service(new Container());
        $events->subscribe(PlainUnregisteredListenerFixture::class);

        $events->dispatch(new UnregisteredEventFixture());

        self::assertSame(['PlainUnregisteredListenerFixture'], UnregisteredListenerCalls::$calls);
    }

    public function testUnregisteredListenerIsAutowiredFromContainerDependencies(): void
    {
        $container = new Container([
            ListenerDependencyFixture::class => new FactoryDefinition(
                ListenerDependencyFixture::class,
                fn() => new ListenerDependencyFixture('wired')
            ),
        ]);
        $events = $this->service($container);
        $events->subscribe(AutowiredUnregisteredListenerFixture::class);

        $events->dispatch(new UnregisteredEventFixture());

        self::assertSame(['wired'], UnregisteredListenerCalls::$calls);
    }

    public function testUnresolvableListenerFailsLoudlyAtSubscribe(): void
    {
        $events = $this->service(new Container());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(UnresolvableListenerFixture::class);

        $events->subscribe(UnresolvableListenerFixture::class);
    }

    public function testRegisteredListenerStaysLazy(): void
    {
        $container = new Container([
            CountingListenerFixture::class => new FactoryDefinition(
                CountingListenerFixture::class,
                fn() => new CountingListenerFixture()
            ),
        ]);
        $events = $this->service($container);
        $events->subscribe(CountingListenerFixture::class);

        self::assertSame(0, UnregisteredListenerCalls::$constructed);

        $events->dispatch(new UnregisteredEventFixture());

        self::assertSame(1, UnregisteredListenerCalls::$constructed);
        self::assertSame(['CountingListenerFixture'], UnregisteredListenerCalls::$calls);
    }
}

final class UnregisteredListenerCalls
{
    /** @var list<string> */
    public static array $calls = [];
    public static int $constructed = 0;
}

final class UnregisteredEventFixture
{
}

final class ListenerDependencyFixture
{
    public function __construct(public readonly string $label)
    {
    }
}

final class PlainUnregisteredListenerFixture
{
    #[AsListener(UnregisteredEventFixture::class)]
    public function handle(UnregisteredEventFixture $event): void
    {
        UnregisteredListenerCalls::$calls[] = 'PlainUnregisteredListenerFixture';
    }
}

final class AutowiredUnregisteredListenerFixture
{
    public function __construct(private ListenerDependencyFixture $dependency)
    {
    }

    #[AsListener(UnregisteredEventFixture::class)]
    public function handle(UnregisteredEventFixture $event): void
    {
        UnregisteredListenerCalls::$calls[] = $this->dependency->label;
    }
}

final class UnresolvableListenerFixture
{
    public function __construct(private string $requiredScalar)
    {
    }

    #[AsListener(UnregisteredEventFixture::class)]
    public function handle(UnregisteredEventFixture $event): void
    {
        UnregisteredListenerCalls::$calls[] = $this->requiredScalar;
    }
}

final class CountingListenerFixture
{
    public function __construct()
    {
        UnregisteredListenerCalls::$constructed++;
    }

    #[AsListener(UnregisteredEventFixture::class)]
    public function handle(UnregisteredEventFixture $event): void
    {
        UnregisteredListenerCalls::$calls[] = 'CountingListenerFixture';
    }
}
