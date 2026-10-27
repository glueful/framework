<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Lock;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Container\Providers\LockProvider;
use Glueful\Container\Providers\TagCollector;
use Glueful\Database\Connection;
use Glueful\Lock\LockManager;
use Glueful\Lock\LockManagerInterface;
use Glueful\Lock\Store\FileLockStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\Exception\LockConflictedException;

/**
 * The `redis` and `database` lock stores must work on a stock install: nothing registers
 * `\Redis::class` or `DatabaseInterface::class`, so LockProvider has to build the Redis client
 * the way the cache does and take the database store from the framework's Connection.
 * The `connection`, `ttl` and `retry` keys of config/lock.php must be honoured.
 */
final class LockProviderStoresTest extends TestCase
{
    /**
     * @param array<string, mixed> $lockConfig
     * @param array<string, mixed> $extraDefs
     */
    private function manager(array $lockConfig, array $extraDefs = [], ?ApplicationContext $context = null): LockManager
    {
        $context ??= ApplicationContext::forTesting(dirname(__DIR__, 3));
        $context->overrideConfig('lock', $lockConfig);
        $provider = new LockProvider(new TagCollector(), $context);
        $container = new Container(array_merge($provider->defs(), $extraDefs));

        $manager = $container->get(LockManagerInterface::class);
        $this->assertInstanceOf(LockManager::class, $manager);
        return $manager;
    }

    private function memoryConnection(): Connection
    {
        return new Connection(['engine' => 'sqlite', 'sqlite' => ['primary' => ':memory:']]);
    }

    public function testDatabaseStoreUsesTheFrameworkConnection(): void
    {
        $manager = $this->manager(
            ['default' => 'database', 'stores' => ['database' => ['driver' => 'database']]],
            ['database' => fn() => $this->memoryConnection()]
        );

        $this->assertTrue($manager->acquireLock('db-resource', 30.0));
        $this->assertTrue($manager->releaseLock('db-resource'));
    }

    public function testDatabaseStoreHonoursNamedConnection(): void
    {
        $context = ApplicationContext::forTesting(dirname(__DIR__, 3));
        // The default engine is mysql (unreachable here); the lock store names sqlite.
        $context->overrideConfig('database', [
            'engine' => 'mysql',
            'sqlite' => ['primary' => ':memory:'],
        ]);

        $manager = $this->manager(
            ['default' => 'database', 'stores' => ['database' => ['connection' => 'sqlite']]],
            [],
            $context
        );

        $this->assertTrue($manager->acquireLock('named-db-resource', 30.0));
    }

    public function testRedisStoreBuildsItsOwnConnection(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('phpredis not installed');
        }
        $probe = new \Redis();
        try {
            if (!@$probe->connect('127.0.0.1', 6379, 0.3)) {
                $this->markTestSkipped('No Redis server on 127.0.0.1:6379');
            }
        } catch (\RedisException) {
            $this->markTestSkipped('No Redis server on 127.0.0.1:6379');
        }

        $context = ApplicationContext::forTesting(dirname(__DIR__, 3));
        $context->overrideConfig('cache', ['stores' => ['locks_redis' => [
            'host' => '127.0.0.1',
            'port' => 6379,
        ]]]);

        $manager = $this->manager(
            ['default' => 'redis', 'stores' => ['redis' => ['connection' => 'locks_redis']]],
            [],
            $context
        );

        $resource = 'redis-resource-' . bin2hex(random_bytes(4));
        $this->assertTrue($manager->acquireLock($resource, 30.0));
        $this->assertTrue($manager->releaseLock($resource));
    }

    public function testConfiguredTtlIsTheDefaultLockLifetime(): void
    {
        // The database store is expiring (the file store's flock never reports a lifetime).
        $manager = $this->manager(
            ['default' => 'database', 'stores' => ['database' => []], 'ttl' => 42],
            ['database' => fn() => $this->memoryConnection()]
        );

        $lock = $manager->createLock('ttl-resource');
        $this->assertTrue($lock->acquire());
        $remaining = $lock->getRemainingLifetime();
        $lock->release();

        $this->assertNotNull($remaining, 'A lock created without a TTL must still expire (config lock.ttl).');
        $this->assertGreaterThan(40.0, $remaining);
        $this->assertLessThanOrEqual(42.0, $remaining);
    }

    public function testRetryMaxWaitBoundsWaitAndExecute(): void
    {
        $dir = sys_get_temp_dir() . '/glueful-lock-test-' . getmypid();
        $holder = new LockManager(new FileLockStore($dir));
        $this->assertTrue($holder->acquireLock('busy', 30.0));

        $manager = $this->manager([
            'default' => 'file',
            'stores' => ['file' => ['path' => $dir]],
            'retry' => ['delay' => 10, 'max_wait' => 0.2],
        ]);

        $start = microtime(true);
        try {
            $manager->waitAndExecute('busy', fn() => 'ran');
            $this->fail('Expected the held lock to time out.');
        } catch (LockConflictedException) {
            $elapsed = microtime(true) - $start;
        } finally {
            $holder->releaseLock('busy');
        }

        $this->assertLessThan(2.0, $elapsed, 'lock.retry.max_wait must replace the hard-coded 10s wait.');
    }
}
