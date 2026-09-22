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

    protected function setUp(): void
    {
        self::$pushed = [];
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
                return 'job-1';
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
}
