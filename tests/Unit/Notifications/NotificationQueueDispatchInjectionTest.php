<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Notifications;

use Glueful\Notifications\Contracts\NotificationQueueDispatcherInterface;
use Glueful\Notifications\Services\NotificationDispatcher;
use Glueful\Notifications\Services\NotificationService;
use Glueful\Notifications\Stores\NullNotificationStore;
use Glueful\Queue\Jobs\DispatchNotificationChannels;
use PHPUnit\Framework\TestCase;

/**
 * Phase 3: async dispatch delegates to an injected queue dispatcher (no inline QueueManager),
 * while the fallback path is preserved when none is injected.
 */
final class NotificationQueueDispatchInjectionTest extends TestCase
{
    /**
     * @param array<int, string> $channels
     */
    private function queueAsyncDispatch(
        NotificationService $service,
        ?string $uuid,
        array $channels
    ): mixed {
        $method = new \ReflectionMethod($service, 'queueAsyncDispatch');
        $method->setAccessible(true);

        return $method->invoke($service, $uuid, $channels, ['priority' => 'high']);
    }

    private function service(?NotificationQueueDispatcherInterface $queueDispatcher): NotificationService
    {
        return new NotificationService(
            $this->createMock(NotificationDispatcher::class),
            new NullNotificationStore(),
            null,
            null,
            [],
            null,
            $queueDispatcher
        );
    }

    public function testInjectedDispatcherIsUsedForAsyncDispatch(): void
    {
        $spy = new class implements NotificationQueueDispatcherInterface {
            /** @var array<int, array<string, mixed>> */
            public array $calls = [];

            public function dispatch(string $job, array $payload, ?string $queue = null): ?string
            {
                $this->calls[] = ['job' => $job, 'payload' => $payload, 'queue' => $queue];

                return 'job-123';
            }
        };

        $result = $this->queueAsyncDispatch($this->service($spy), 'u_1', ['email', 'sms']);

        self::assertSame('job-123', $result);
        self::assertCount(1, $spy->calls);
        self::assertSame(DispatchNotificationChannels::class, $spy->calls[0]['job']);
        self::assertSame('u_1', $spy->calls[0]['payload']['notification_uuid']);
        self::assertSame(['email', 'sms'], $spy->calls[0]['payload']['channels']);
        self::assertSame('notifications', $spy->calls[0]['queue']); // default async_queue option
    }

    public function testEmptyChannelsShortCircuitsWithoutTouchingTheDispatcher(): void
    {
        $spy = new class implements NotificationQueueDispatcherInterface {
            public bool $called = false;

            public function dispatch(string $job, array $payload, ?string $queue = null): ?string
            {
                $this->called = true;

                return 'should-not-happen';
            }
        };

        $result = $this->queueAsyncDispatch($this->service($spy), 'u_1', []);

        self::assertNull($result);
        self::assertFalse($spy->called);
    }
}
