<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Cache;

use Glueful\Cache\Drivers\RedisCacheDriver;
use Glueful\Security\SecureSerializer;
use PHPUnit\Framework\TestCase;

/**
 * The Redis driver and a value over the serializer's size limit (the file driver already behaves so):
 * it is not stored — set() answers false — and an entry that cannot be read back is a miss, never an
 * exception. It used to be written, then refused on every read: an image variant over 1MB answered
 * 500 on each request after the first.
 *
 * @requires extension redis
 */
final class RedisOversizeValueTest extends TestCase
{
    private function driver(InMemoryRedis $redis): RedisCacheDriver
    {
        return new RedisCacheDriver($redis);
    }

    public function testAValueTooLargeToReadBackIsNotStored(): void
    {
        $redis = new InMemoryRedis();
        $cache = $this->driver($redis);
        $big = ['data' => str_repeat('a', SecureSerializer::MAX_SIZE), 'mime' => 'image/png'];

        self::assertFalse($cache->set('variant', $big, 600));
        self::assertFalse($cache->set('variant', $big));
        self::assertSame([], $redis->store, 'nothing written');
        self::assertNull($cache->get('variant'));
    }

    public function testAnEntryThatCannotBeReadIsAMiss(): void
    {
        $redis = new InMemoryRedis();
        // Written by a release that did not check the size on the way in.
        $redis->store['variant'] = 'json:' . json_encode(['data' => str_repeat('a', SecureSerializer::MAX_SIZE)]);
        $cache = $this->driver($redis);

        self::assertSame('fallback', $cache->get('variant', 'fallback'));
    }

    public function testOrdinaryValuesStillRoundTrip(): void
    {
        $cache = $this->driver(new InMemoryRedis());
        self::assertTrue($cache->set('small', ['data' => 'abc'], 600));
        self::assertSame(['data' => 'abc'], $cache->get('small'));
    }
}

/** A \Redis that keeps values in memory: no server. */
class InMemoryRedis extends \Redis
{
    /** @var array<string, string> */
    public array $store = [];

    public function __construct()
    {
    }

    public function get(string $key): mixed
    {
        return $this->store[$key] ?? false;
    }

    public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool
    {
        $this->store[$key] = (string) $value;
        return true;
    }

    public function setex(string $key, int $expire, mixed $value): \Redis|bool
    {
        $this->store[$key] = (string) $value;
        return true;
    }
}
