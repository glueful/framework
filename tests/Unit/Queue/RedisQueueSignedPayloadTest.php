<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Queue;

use Glueful\Queue\Contracts\JobInterface;
use Glueful\Queue\Drivers\RedisQueue;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class RedisQueueSignedPayloadTest extends TestCase
{
    private string|false $previousAppKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousAppKey = getenv('APP_KEY');
        $_ENV['APP_KEY'] = 'redis-signed-payload-test-key';
        putenv('APP_KEY=redis-signed-payload-test-key');
    }

    protected function tearDown(): void
    {
        if ($this->previousAppKey === false) {
            unset($_ENV['APP_KEY']);
            putenv('APP_KEY');
        } else {
            $_ENV['APP_KEY'] = $this->previousAppKey;
            putenv('APP_KEY=' . $this->previousAppKey);
        }
        parent::tearDown();
    }

    public function testReleasedRedisJobKeepsValidSignedPayloadForNextDelivery(): void
    {
        $queue = new RedisQueue();
        $this->setPrivate($queue, 'redis', new \Glueful\Tests\Support\InMemoryRedis());
        $this->setPrivate($queue, 'retryAfter', 90);
        $this->setPrivate($queue, 'jobExpiration', 3600);

        $queue->push(RedisSignedPayloadJob::class, ['maxAttempts' => 3]);

        $first = $queue->pop();
        $this->assertInstanceOf(JobInterface::class, $first);
        $this->assertSame(3, $first->getMaxAttempts());

        $first->release();

        $second = $queue->pop();
        $this->assertInstanceOf(JobInterface::class, $second);
        $this->assertSame(3, $second->getMaxAttempts());
        $this->assertSame(2, $second->getAttempts());
    }

    private function setPrivate(object $object, string $property, mixed $value): void
    {
        $ref = new ReflectionProperty($object, $property);
        $ref->setAccessible(true);
        $ref->setValue($object, $value);
    }
}

final class RedisSignedPayloadJob implements JobInterface
{
    public function fire(): void {}
    public function release(int $delay = 0): void {}
    public function delete(): void {}
    public function failed(\Exception $exception): void {}
    public function getUuid(): string { return 'fixture'; }
    public function getQueue(): ?string { return 'default'; }
    public function getAttempts(): int { return 0; }
    public function getMaxAttempts(): int { return 3; }
    public function getPayload(): array { return []; }
    public function getRawData(): array { return []; }
    public function getReservedAt(): ?int { return null; }
    public function getAvailableAt(): int { return 0; }
    public function getCreatedAt(): int { return 0; }
    public function getDriver(): ?\Glueful\Queue\Contracts\QueueDriverInterface { return null; }
    public function setDriver(\Glueful\Queue\Contracts\QueueDriverInterface $driver): void {}
    public function getTimeout(): int { return 60; }
    public function getBatchUuid(): ?string { return null; }
    public function shouldRetry(): bool { return true; }
    public function getPriority(): int { return 0; }
    public function setAttempts(int $attempts): void {}
    public function getDescription(): string { return 'redis signed payload fixture'; }
    public function handle(array $data = []): void {}
}
