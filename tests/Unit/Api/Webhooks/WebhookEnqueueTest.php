<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Api\Webhooks;

use Glueful\Api\Webhooks\Jobs\DeliverWebhookJob;
use Glueful\Api\Webhooks\WebhookDelivery;
use Glueful\Api\Webhooks\WebhookDispatcher;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Queue\QueueManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * QueueManager::push() takes a job CLASS NAME and its data. Both webhook enqueue paths handed it
 * a DeliverWebhookJob object, a TypeError under strict_types: every delivery row stayed `pending`
 * for ever and Retry answered 500. Nothing had covered an enqueue.
 */
final class WebhookEnqueueTest extends TestCase
{
    /** @var list<array{job: string, data: array<string, mixed>, queue: ?string}> */
    public static array $pushed = [];

    /** @var list<array{job: string, queue: ?string, connection: ?string, delay: int}> */
    public static array $calls = [];

    protected function setUp(): void
    {
        self::$pushed = [];
        self::$calls = [];
    }

    private function context(): ApplicationContext
    {
        $queue = new class extends QueueManager {
            public function __construct()
            {
            }

            public function push(
                string $job,
                array $data = [],
                ?string $queue = null,
                ?string $connection = null
            ): string {
                WebhookEnqueueTest::$pushed[] = ['job' => $job, 'data' => $data, 'queue' => $queue];
                WebhookEnqueueTest::$calls[] = [
                    'job' => $job, 'queue' => $queue, 'connection' => $connection, 'delay' => 0,
                ];
                return 'job-1';
            }

            public function later(
                int $delay,
                string $job,
                array $data = [],
                ?string $queue = null,
                ?string $connection = null
            ): string {
                WebhookEnqueueTest::$calls[] = [
                    'job' => $job, 'queue' => $queue, 'connection' => $connection, 'delay' => $delay,
                ];
                return 'job-2';
            }
        };
        $context = ApplicationContext::forTesting(sys_get_temp_dir());
        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn(string $id): bool => $id === QueueManager::class);
        $container->method('get')->willReturnCallback(
            static fn(string $id): object => $id === QueueManager::class
                ? $queue
                : throw new \RuntimeException("unexpected get($id)")
        );
        $context->setContainer($container);

        return $context;
    }

    public function testTheJobIsQueuedByClassNameWithTheDeliveryIdOnTheGivenQueue(): void
    {
        $id = DeliverWebhookJob::enqueue($this->context(), 7, 'hooks');

        self::assertSame('job-1', $id);
        self::assertSame(
            [['job' => DeliverWebhookJob::class, 'data' => ['delivery_id' => 7], 'queue' => 'hooks']],
            self::$pushed
        );
    }

    public function testTheDispatcherQueuesEachDeliveryWithoutATypeError(): void
    {
        $dispatcher = (new \ReflectionClass(WebhookDispatcher::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(WebhookDispatcher::class, 'context'))->setValue($dispatcher, $this->context());
        $delivery = new WebhookDelivery();
        $delivery->id = 12;

        (new \ReflectionMethod(WebhookDispatcher::class, 'queueDelivery'))
            ->invoke($dispatcher, $delivery, ['queue' => 'webhooks']);

        self::assertSame(
            [['job' => DeliverWebhookJob::class, 'data' => ['delivery_id' => 12], 'queue' => 'webhooks']],
            self::$pushed
        );
    }

    public function testEnqueueUsesTheGivenQueueConnectionAndDelay(): void
    {
        DeliverWebhookJob::enqueue($this->context(), 7, 'hooks', 'redis');
        DeliverWebhookJob::enqueue($this->context(), 8, 'hooks', null, 90);

        self::assertSame([
            ['job' => DeliverWebhookJob::class, 'queue' => 'hooks', 'connection' => 'redis', 'delay' => 0],
            ['job' => DeliverWebhookJob::class, 'queue' => 'hooks', 'connection' => null, 'delay' => 90],
        ], self::$calls);
    }

    public function testTheDispatcherHonoursTheConfiguredConnectionAndPerDispatchOptions(): void
    {
        $dispatcher = (new \ReflectionClass(WebhookDispatcher::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(WebhookDispatcher::class, 'context'))->setValue($dispatcher, $this->context());
        $delivery = new WebhookDelivery();
        $delivery->id = 12;
        $queue = new \ReflectionMethod(WebhookDispatcher::class, 'queueDelivery');

        $queue->invoke($dispatcher, $delivery, ['queue' => 'webhooks', 'connection' => 'redis'], []);
        $queue->invoke(
            $dispatcher,
            $delivery,
            ['queue' => 'webhooks', 'connection' => 'redis'],
            ['queue' => 'urgent', 'connection' => 'database', 'delay' => 30]
        );

        self::assertSame([
            ['job' => DeliverWebhookJob::class, 'queue' => 'webhooks', 'connection' => 'redis', 'delay' => 0],
            ['job' => DeliverWebhookJob::class, 'queue' => 'urgent', 'connection' => 'database', 'delay' => 30],
        ], self::$calls);
    }
}
