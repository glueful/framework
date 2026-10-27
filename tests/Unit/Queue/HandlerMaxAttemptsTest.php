<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Queue;

use Glueful\Queue\Contracts\QueueDriverInterface;
use Glueful\Queue\Drivers\RedisQueue;
use Glueful\Queue\Job;
use Glueful\Queue\Jobs\DatabaseJob;
use Glueful\Queue\QueuePayloadSigner;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Jobs are pushed by class name, so the payload's maxAttempts (default 3) was written without
 * asking the handler: a handler's getMaxAttempts() override never reached the worker. The queued
 * job now asks the handler unless a maxAttempts was pushed with the data.
 */
final class HandlerMaxAttemptsTest extends TestCase
{
    private function redisQueue(): RedisQueue
    {
        $queue = new RedisQueue();
        foreach (['redis' => new \Glueful\Tests\Support\InMemoryRedis(), 'retryAfter' => 90, 'jobExpiration' => 3600] as $property => $value) {
            $ref = new ReflectionProperty($queue, $property);
            $ref->setValue($queue, $value);
        }

        return $queue;
    }

    public function testRedisJobUsesTheHandlersMaxAttempts(): void
    {
        $queue = $this->redisQueue();
        $queue->push(SevenAttemptsJob::class, ['key' => 'value']);

        self::assertSame(7, $queue->pop()?->getMaxAttempts());
    }

    public function testMaxAttemptsPushedWithTheDataStillWins(): void
    {
        $queue = $this->redisQueue();
        $queue->push(SevenAttemptsJob::class, ['maxAttempts' => 2]);

        self::assertSame(2, $queue->pop()?->getMaxAttempts());
    }

    public function testHandlerWithoutAnOverrideKeepsTheDefault(): void
    {
        $queue = $this->redisQueue();
        $queue->push(DefaultAttemptsJob::class, []);

        self::assertSame(3, $queue->pop()?->getMaxAttempts());
    }

    public function testDatabaseJobUsesTheHandlersMaxAttempts(): void
    {
        $job = new DatabaseJob(
            $this->createMock(QueueDriverInterface::class),
            [
                'uuid' => 'job-1',
                'attempts' => 1,
                // Signed as the driver does, so the test holds whatever signing state the env has.
                'payload' => json_encode((new QueuePayloadSigner())->sign([
                    'job' => SevenAttemptsJob::class,
                    'data' => [],
                    'maxAttempts' => 3,
                ])),
            ],
            'default'
        );

        self::assertSame(7, $job->getMaxAttempts());
        self::assertTrue($job->shouldRetry());
    }
}

final class SevenAttemptsJob extends Job
{
    public function handle(): void
    {
    }

    public function getMaxAttempts(): int
    {
        return 7;
    }
}

final class DefaultAttemptsJob extends Job
{
    public function handle(): void
    {
    }
}
