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
        $this->setPrivate($queue, 'redis', new InMemoryRedisForQueue());
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

final class InMemoryRedisForQueue extends \Redis
{
    /** @var array<string, array<string, mixed>> */
    public array $hashes = [];
    /** @var array<string, list<string>> */
    public array $lists = [];
    /** @var array<string, array<string, int|float>> */
    public array $sets = [];

    public function multi($value = \Redis::MULTI): \Redis|bool
    {
        return true;
    }

    public function exec(): \Redis|array|false
    {
        return [];
    }

    public function hMset($key, $fields): \Redis|false
    {
        $this->hashes[(string) $key] = array_merge($this->hashes[(string) $key] ?? [], (array) $fields);
        return $this;
    }

    public function hGetAll($key): \Redis|array|false
    {
        return $this->hashes[(string) $key] ?? [];
    }

    public function hDel($key, ...$fields): \Redis|int|false
    {
        $count = 0;
        foreach ($fields as $field) {
            if (isset($this->hashes[(string) $key][(string) $field])) {
                unset($this->hashes[(string) $key][(string) $field]);
                $count++;
            }
        }
        return $count;
    }

    public function expire($key, $timeout, $mode = null): \Redis|bool
    {
        return true;
    }

    public function sAdd($key, $value, ...$other_values): \Redis|int|false
    {
        return 1;
    }

    public function rPush($key, ...$elements): \Redis|int|false
    {
        foreach ($elements as $element) {
            $this->lists[(string) $key][] = (string) $element;
        }
        return count($this->lists[(string) $key]);
    }

    public function lPush($key, ...$elements): \Redis|int|false
    {
        foreach ($elements as $element) {
            array_unshift($this->lists[(string) $key], (string) $element);
        }
        return count($this->lists[(string) $key]);
    }

    public function lPop($key, $count = 0): \Redis|array|string|false
    {
        return array_shift($this->lists[(string) $key]);
    }

    public function zAdd($key, $score_or_options, ...$more_scores_and_mems): \Redis|int|float|false
    {
        $member = (string) ($more_scores_and_mems[0] ?? '');
        $this->sets[(string) $key][$member] = (int) $score_or_options;
        return 1;
    }

    public function zRem($key, $member, ...$other_members): \Redis|int|false
    {
        unset($this->sets[(string) $key][(string) $member]);
        return 1;
    }

    public function zRangeByScore($key, $start, $end, array $options = []): \Redis|array|false
    {
        $max = (int) $end;
        $members = [];
        foreach ($this->sets[(string) $key] ?? [] as $member => $score) {
            if ($score <= $max) {
                $members[] = $member;
            }
        }
        return $members;
    }
}
