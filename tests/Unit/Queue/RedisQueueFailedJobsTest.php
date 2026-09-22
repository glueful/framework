<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Queue;

use Glueful\Queue\Contracts\FailedJobStore;
use Glueful\Queue\Drivers\RedisQueue;
use Glueful\Tests\Support\InMemoryRedis;
use PHPUnit\Framework\TestCase;

/**
 * The failed-job commands worked only on the database connection; a Redis app's failures sit in
 * `queue:{name}:failed` lists with no way to list, retry or remove them. The Redis driver now
 * implements the same FailedJobStore contract.
 */
final class RedisQueueFailedJobsTest extends TestCase
{
    private ?string $previousKey = null;

    protected function setUp(): void
    {
        $this->previousKey = $_ENV['APP_KEY'] ?? null;
        $_ENV['APP_KEY'] = 'redis-failed-jobs-test-key';
    }

    protected function tearDown(): void
    {
        if ($this->previousKey === null) {
            unset($_ENV['APP_KEY']);
        } else {
            $_ENV['APP_KEY'] = $this->previousKey;
        }
    }

    private function queue(InMemoryRedis $redis): RedisQueue
    {
        $queue = new RedisQueue();
        foreach (['redis' => $redis, 'retryAfter' => 90, 'jobExpiration' => 3600] as $prop => $value) {
            (new \ReflectionProperty($queue, $prop))->setValue($queue, $value);
        }

        return $queue;
    }

    private function failOne(RedisQueue $queue, int $n, string $name): void
    {
        $queue->push(RedisFailedTestJob::class, ['n' => $n], $name);
        $job = $queue->pop($name);
        self::assertNotNull($job);
        $queue->fail($job, new \RuntimeException("boom {$n}"));
    }

    public function testTheRedisDriverListsRetriesForgetsAndFlushesFailures(): void
    {
        $redis = new InMemoryRedis();
        $queue = $this->queue($redis);
        self::assertInstanceOf(FailedJobStore::class, $queue);

        $this->failOne($queue, 1, 'mail');
        $this->failOne($queue, 2, 'default');
        $this->failOne($queue, 3, 'default');

        $failed = $queue->failedJobs();
        self::assertCount(3, $failed);
        self::assertSame(RedisFailedTestJob::class, $failed[0]['job']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $failed[0]['failed_at']);
        self::assertCount(1, $queue->failedJobs('mail'));

        $mail = $queue->failedJobs('mail')[0]['uuid'];
        self::assertNotNull($queue->retryFailed($mail));
        self::assertSame([], $queue->failedJobs('mail'));
        $again = $queue->pop('mail');
        self::assertNotNull($again);
        self::assertSame(1, $again->getPayload()['data']['n'] ?? null);

        $one = $queue->failedJobs('default')[0]['uuid'];
        self::assertTrue($queue->forgetFailed($one));
        self::assertFalse($queue->forgetFailed($one));
        self::assertSame(1, $queue->flushFailed());
        self::assertSame([], $queue->failedJobs());
    }

    public function testRetryRefusesAnAlteredPayload(): void
    {
        $redis = new InMemoryRedis();
        $queue = $this->queue($redis);
        $this->failOne($queue, 1, 'default');
        $entry = json_decode($redis->lists['queue:default:failed'][0], true);
        $payload = json_decode($entry['payload'], true);
        $payload['data']['n'] = 999;
        $entry['payload'] = json_encode($payload);
        $redis->lists['queue:default:failed'][0] = json_encode($entry);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('signature');
        $queue->retryFailed($entry['uuid']);
    }
}

final class RedisFailedTestJob extends \Glueful\Queue\Job
{
    public function handle(): void
    {
    }
}
