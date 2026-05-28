<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Database;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\Drivers\ArrayCacheDriver;
use Glueful\Database\Connection;
use Glueful\Database\QueryCacheService;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end coverage for the fluent QueryBuilder::cache(ttl, tags) API wired
 * through to QueryCacheService. A shared QueryCacheService (backed by an
 * in-memory ArrayCacheDriver) is injected into each builder's executor so that
 * identical queries hit the same cache.
 */
final class QueryCacheFluentTest extends TestCase
{
    private string $dbPath;
    private Connection $connection;
    private ApplicationContext $context;
    private ArrayCacheDriver $store;
    private QueryCacheService $cacheService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbPath = sys_get_temp_dir() . '/glueful-qcache-' . uniqid('', true) . '.sqlite';
        $this->connection = new Connection([
            'engine' => 'sqlite',
            'sqlite' => ['primary' => $this->dbPath],
            'pooling' => ['enabled' => false],
        ]);
        $this->connection->getPDO()->exec(
            'CREATE TABLE widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)'
        );
        $this->connection->getPDO()->exec("INSERT INTO widgets (name) VALUES ('a'), ('b')");

        $this->context = new ApplicationContext(sys_get_temp_dir() . '/glueful-qcache-ctx-' . uniqid('', true));
        $this->store = new ArrayCacheDriver();
        $this->cacheService = new QueryCacheService($this->store, $this->context);
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
        parent::tearDown();
    }

    /**
     * Build a fresh widgets query whose executor shares the test's cache service.
     *
     * @return \Glueful\Database\QueryBuilder
     */
    private function widgets()
    {
        $qb = $this->connection->table('widgets');
        $ref = new \ReflectionObject($qb);
        $prop = $ref->getProperty('queryExecutor');
        $prop->setAccessible(true);
        $prop->getValue($qb)->setCacheService($this->cacheService);
        return $qb;
    }

    private function insertWidget(string $name): void
    {
        $this->connection->getPDO()->exec("INSERT INTO widgets (name) VALUES ('{$name}')");
    }

    public function testCacheServesStaleResultThenUserTagInvalidates(): void
    {
        // 1. First cached read — 2 rows.
        $first = $this->widgets()->cache(3600, ['widgets'])->orderBy('id')->get();
        $this->assertCount(2, $first);

        // 2. Mutate the underlying table behind the cache's back.
        $this->insertWidget('c');

        // 3. Identical cached query is served from cache (stale) — proves it cached.
        $second = $this->widgets()->cache(3600, ['widgets'])->orderBy('id')->get();
        $this->assertCount(2, $second, 'expected the cached (stale) result on a cache hit');

        // 4. Invalidate via the *user-supplied* tag — proves cache(ttl, tags) stored it.
        $this->store->invalidateTags(['widgets']);

        $third = $this->widgets()->cache(3600, ['widgets'])->orderBy('id')->get();
        $this->assertCount(3, $third, 'expected a fresh read after invalidating the user tag');
    }

    public function testReadsAreNotCachedWithoutCacheCall(): void
    {
        // No ->cache() — even with a cache service present, reads must stay fresh.
        $first = $this->widgets()->orderBy('id')->get();
        $this->assertCount(2, $first);

        $this->insertWidget('c');

        $second = $this->widgets()->orderBy('id')->get();
        $this->assertCount(3, $second, 'reads without ->cache() must not be cached');
    }
}
