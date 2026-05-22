<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Cache;

use Glueful\Cache\Drivers\RedisCacheDriver;
use PHPUnit\Framework\TestCase;
use Redis;

/**
 * Integration tests for tag-based invalidation on the Redis driver.
 *
 * Skipped automatically when the phpredis extension is missing or no Redis
 * server is reachable on REDIS_HOST/REDIS_PORT. All test keys are written
 * under the `gf_tag_test:` prefix and torn down after each test.
 */
final class RedisCacheDriverTagsTest extends TestCase
{
    private const KEY_PREFIX = 'gf_tag_test:';
    private const TAG_INTERNAL_PREFIX = '_gf_tag:';

    private Redis $redis;
    /** @var RedisCacheDriver<mixed> */
    private RedisCacheDriver $driver;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('phpredis extension not available');
        }

        $host = (string) (getenv('REDIS_HOST') ?: '127.0.0.1');
        $port = (int) (getenv('REDIS_PORT') ?: 6379);

        $redis = new Redis();
        try {
            $connected = @$redis->connect($host, $port, 0.5);
        } catch (\RedisException) {
            $connected = false;
        }

        if ($connected !== true) {
            $this->markTestSkipped("Redis not reachable at {$host}:{$port}");
        }

        $this->redis = $redis;
        $this->driver = new RedisCacheDriver($redis);

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->cleanup();
            $this->redis->close();
        }
    }

    public function testCapabilitiesReportTagsSupported(): void
    {
        $capabilities = $this->driver->getCapabilities();

        self::assertSame('redis', $capabilities['driver']);
        self::assertTrue($capabilities['features']['tags']);
    }

    public function testAddTagsReturnsTrueForNonEmptyTags(): void
    {
        $this->driver->set(self::KEY_PREFIX . 'a', 'value-a', 60);

        self::assertTrue($this->driver->addTags(self::KEY_PREFIX . 'a', ['posts', 'feed']));
    }

    public function testAddTagsIsNoOpForEmptyTags(): void
    {
        self::assertTrue($this->driver->addTags(self::KEY_PREFIX . 'a', []));
    }

    public function testInvalidateTagsRemovesAllKeysAssociatedWithTag(): void
    {
        $this->driver->set(self::KEY_PREFIX . 'a', 'value-a', 60);
        $this->driver->set(self::KEY_PREFIX . 'b', 'value-b', 60);
        $this->driver->set(self::KEY_PREFIX . 'c', 'value-c', 60);

        $this->driver->addTags(self::KEY_PREFIX . 'a', ['posts']);
        $this->driver->addTags(self::KEY_PREFIX . 'b', ['posts']);
        $this->driver->addTags(self::KEY_PREFIX . 'c', ['unrelated']);

        self::assertTrue($this->driver->invalidateTags(['posts']));

        self::assertNull($this->driver->get(self::KEY_PREFIX . 'a'));
        self::assertNull($this->driver->get(self::KEY_PREFIX . 'b'));
        self::assertSame('value-c', $this->driver->get(self::KEY_PREFIX . 'c'));
    }

    public function testInvalidateTagsClearsTagSetItself(): void
    {
        $this->driver->set(self::KEY_PREFIX . 'a', 'value-a', 60);
        $this->driver->addTags(self::KEY_PREFIX . 'a', ['posts']);

        self::assertSame(1, $this->redis->exists(self::TAG_INTERNAL_PREFIX . 'posts'));

        $this->driver->invalidateTags(['posts']);

        self::assertSame(0, $this->redis->exists(self::TAG_INTERNAL_PREFIX . 'posts'));
    }

    public function testAddTagsIsIdempotent(): void
    {
        $this->driver->set(self::KEY_PREFIX . 'a', 'value-a', 60);

        $this->driver->addTags(self::KEY_PREFIX . 'a', ['posts']);
        $this->driver->addTags(self::KEY_PREFIX . 'a', ['posts']);
        $this->driver->addTags(self::KEY_PREFIX . 'a', ['posts']);

        self::assertSame(1, (int) $this->redis->sCard(self::TAG_INTERNAL_PREFIX . 'posts'));
    }

    public function testInvalidateTagsHandlesUnknownTagsWithoutError(): void
    {
        self::assertTrue($this->driver->invalidateTags(['never-used-tag']));
    }

    public function testInvalidateTagsAcrossMultipleTagsDeduplicatesKeys(): void
    {
        $this->driver->set(self::KEY_PREFIX . 'shared', 'value', 60);
        $this->driver->addTags(self::KEY_PREFIX . 'shared', ['posts', 'feed']);

        $this->driver->invalidateTags(['posts', 'feed']);

        self::assertNull($this->driver->get(self::KEY_PREFIX . 'shared'));
    }

    public function testInvalidateTagsIsNoOpForEmptyArray(): void
    {
        $this->driver->set(self::KEY_PREFIX . 'a', 'value-a', 60);
        $this->driver->addTags(self::KEY_PREFIX . 'a', ['posts']);

        self::assertTrue($this->driver->invalidateTags([]));
        self::assertSame('value-a', $this->driver->get(self::KEY_PREFIX . 'a'));
    }

    private function cleanup(): void
    {
        foreach ($this->redis->keys(self::KEY_PREFIX . '*') as $key) {
            $this->redis->del($key);
        }
        foreach ($this->redis->keys(self::TAG_INTERNAL_PREFIX . '*') as $key) {
            $this->redis->del($key);
        }
    }
}
