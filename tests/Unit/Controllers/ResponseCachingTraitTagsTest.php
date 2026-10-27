<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Cache\Drivers\ArrayCacheDriver;
use Glueful\Cache\Drivers\FileCacheDriver;
use Glueful\Container\Container;
use Glueful\Controllers\Traits\ResponseCachingTrait;
use Glueful\Services\FileFinder;
use Glueful\Storage\PathGuard;
use Glueful\Storage\StorageManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * cacheResponse() builds a tag list (caller tags + `user:<uuid>`); those tags must be stored
 * against the cached entry so invalidateCache()/invalidateTags() actually evict it — on the default
 * file store as well as the in-memory one.
 */
final class ResponseCachingTraitTagsTest extends TestCase
{
    /** @var CacheStore<mixed> */
    private CacheStore $cache;

    private ?string $directory = null;

    protected function tearDown(): void
    {
        if ($this->directory !== null) {
            exec('rm -rf ' . escapeshellarg($this->directory));
        }
    }

    /** @return array<string, array{string}> */
    public static function drivers(): array
    {
        return ['array' => ['array'], 'file' => ['file']];
    }

    private function controller(string $driver): TaggedCachingControllerStub
    {
        if ($driver === 'file') {
            $this->directory = \sys_get_temp_dir() . '/glueful-rc-tags-' . uniqid('', true);
            $storage = new StorageManager(
                ['default' => 'cache', 'disks' => ['cache' => ['driver' => 'local', 'root' => $this->directory]]],
                new PathGuard()
            );
            $this->cache = new FileCacheDriver($this->directory, $storage, new FileFinder());
        } else {
            $this->cache = new ArrayCacheDriver();
        }

        $context = new ApplicationContext(basePath: \sys_get_temp_dir(), environment: 'testing');
        $cache = $this->cache;
        $context->setContainer(new Container([CacheStore::class => fn() => $cache]));

        return new TaggedCachingControllerStub($context, Request::create('/api/posts'));
    }

    #[DataProvider('drivers')]
    public function testTagInvalidationEvictsTheCachedResponse(string $driver): void
    {
        $controller = $this->controller($driver);
        $calls = 0;
        $producer = function () use (&$calls): string {
            $calls++;
            return 'payload-' . $calls;
        };

        $this->assertSame('payload-1', $controller->callCacheResponse('posts', $producer, ['posts']));
        $this->assertSame('payload-1', $controller->callCacheResponse('posts', $producer, ['posts']));

        $controller->callInvalidateCache(['posts']);

        $this->assertSame(
            'payload-2',
            $controller->callCacheResponse('posts', $producer, ['posts']),
            'Invalidating the tag must evict the entry cacheResponse() stored.'
        );
    }

    #[DataProvider('drivers')]
    public function testUntaggedInvalidationLeavesOtherEntriesAlone(string $driver): void
    {
        $controller = $this->controller($driver);
        $controller->callCacheResponse('a', fn() => 'A', ['tag-a']);

        $controller->callInvalidateCache(['tag-b']);

        $this->assertSame('A', $controller->callCacheResponse('a', fn() => 'recomputed', ['tag-a']));
    }
}

final class TaggedCachingControllerStub
{
    use ResponseCachingTrait;

    /** @phpstan-ignore-next-line trait reads $currentUser */
    protected ?object $currentUser = null;

    public function __construct(
        private readonly ApplicationContext $context,
        protected Request $request,
    ) {
    }

    public function getContext(): ApplicationContext
    {
        return $this->context;
    }

    /** @param array<string> $tags */
    public function callCacheResponse(string $key, callable $callback, array $tags): mixed
    {
        return $this->cacheResponse($key, $callback, 3600, $tags);
    }

    /** @param array<string> $tags */
    public function callInvalidateCache(array $tags): void
    {
        $this->invalidateCache($tags);
    }
}
