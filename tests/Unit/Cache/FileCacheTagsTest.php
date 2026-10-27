<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Cache;

use Glueful\Cache\Drivers\FileCacheDriver;
use Glueful\Services\FileFinder;
use Glueful\Storage\PathGuard;
use Glueful\Storage\StorageManager;
use PHPUnit\Framework\TestCase;

/**
 * The file driver is the default store, so tag invalidation must work on it exactly as it does on
 * the Array and Redis drivers: addTags() records the key in each tag's index, invalidateTags()
 * deletes every indexed key and then the index.
 */
final class FileCacheTagsTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/glueful-file-tags-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->directory));
    }

    private function driver(bool $sharded = false): FileCacheDriver
    {
        $driver = new FileCacheDriver($this->directory, self::storage($this->directory), new FileFinder(), 'cache');
        $driver->setShardDirectories($sharded);
        return $driver;
    }

    private static function storage(string $directory): StorageManager
    {
        return new StorageManager(
            [
                'default' => 'cache',
                'disks' => [
                    'cache' => ['driver' => 'local', 'root' => $directory, 'visibility' => 'private'],
                ],
            ],
            new PathGuard()
        );
    }

    public function testTheDriverAdvertisesTagSupport(): void
    {
        self::assertTrue($this->driver()->getCapabilities()['features']['tags']);
    }

    public function testInvalidatingATagRemovesOnlyTheKeysTaggedWithIt(): void
    {
        $cache = $this->driver();
        $cache->set('posts:1', 'one');
        $cache->set('posts:2', 'two');
        $cache->set('users:1', 'user');

        self::assertTrue($cache->addTags('posts:1', ['posts']));
        self::assertTrue($cache->addTags('posts:2', ['posts']));
        self::assertTrue($cache->addTags('users:1', ['users']));

        self::assertTrue($cache->invalidateTags(['posts']));

        self::assertFalse($cache->has('posts:1'));
        self::assertFalse($cache->has('posts:2'));
        self::assertSame('user', $cache->get('users:1'));
    }

    public function testAKeyInTwoTagsIsEvictedThroughEither(): void
    {
        $cache = $this->driver();
        $cache->set('post:1', 'p1');
        $cache->set('post:2', 'p2');
        $cache->addTags('post:1', ['posts', 'user:7']);
        $cache->addTags('post:2', ['posts', 'user:9']);

        $cache->invalidateTags(['user:7']);
        self::assertFalse($cache->has('post:1'));
        self::assertTrue($cache->has('post:2'));

        $cache->set('post:1', 'p1-again');
        $cache->addTags('post:1', ['posts', 'user:7']);
        $cache->invalidateTags(['posts']);
        self::assertFalse($cache->has('post:1'));
        self::assertFalse($cache->has('post:2'));
    }

    public function testSeveralTagsCanBeInvalidatedAtOnce(): void
    {
        $cache = $this->driver();
        $cache->set('a', 'A');
        $cache->set('b', 'B');
        $cache->set('c', 'C');
        $cache->addTags('a', ['ta']);
        $cache->addTags('b', ['tb']);
        $cache->addTags('c', ['tc']);

        self::assertTrue($cache->invalidateTags(['ta', 'tb']));

        self::assertFalse($cache->has('a'));
        self::assertFalse($cache->has('b'));
        self::assertTrue($cache->has('c'));
    }

    public function testInvalidatingAnUnknownTagIsANoOp(): void
    {
        $cache = $this->driver();
        $cache->set('kept', 'value');
        $cache->addTags('kept', ['known']);

        self::assertTrue($cache->invalidateTags(['never-used']));
        self::assertTrue($cache->invalidateTags([]));
        self::assertTrue($cache->addTags('kept', []));
        self::assertSame('value', $cache->get('kept'));
    }

    public function testTheIndexIsConsumedByInvalidation(): void
    {
        $cache = $this->driver();
        $cache->set('k', 'v1');
        $cache->addTags('k', ['t']);
        $cache->invalidateTags(['t']);

        // Re-stored without the tag: a second invalidation must not reach it.
        $cache->set('k', 'v2');
        $cache->invalidateTags(['t']);
        self::assertSame('v2', $cache->get('k'));
    }

    public function testDeletedAndExpiredKeysLeaveHarmlessIndexEntries(): void
    {
        $cache = $this->driver();
        $cache->set('deleted', 'x');
        $cache->set('expired', 'x', 0);
        $cache->set('live', 'x');
        $cache->addTags('deleted', ['t']);
        $cache->addTags('expired', ['t']);
        $cache->addTags('live', ['t']);
        $cache->delete('deleted');

        self::assertTrue($cache->invalidateTags(['t']));
        self::assertFalse($cache->has('live'));
    }

    public function testAnOverwrittenKeyStaysTagged(): void
    {
        $cache = $this->driver();
        $cache->set('k', 'old');
        $cache->addTags('k', ['t']);
        $cache->set('k', 'new');
        $cache->addTags('k', ['t']); // re-tagging the same pair is idempotent

        self::assertTrue($cache->invalidateTags(['t']));
        self::assertFalse($cache->has('k'));
    }

    public function testATagNamedLikeAKeyDoesNotCollideWithTheEntry(): void
    {
        $cache = $this->driver();
        $cache->set('same', 'entry');
        $cache->addTags('same', ['same']);
        $cache->addTags('other', ['same']);

        self::assertSame('entry', $cache->get('same'));
        self::assertSame(['same'], $cache->getKeys('*'), 'Tag indexes must not show up as cache keys.');

        $cache->invalidateTags(['same']);
        self::assertFalse($cache->has('same'));
    }

    public function testTagsWorkWithShardedDirectories(): void
    {
        $cache = $this->driver(true);
        $cache->set('k1', 'v');
        $cache->set('k2', 'v');
        $cache->addTags('k1', ['t']);

        $cache->invalidateTags(['t']);
        self::assertFalse($cache->has('k1'));
        self::assertTrue($cache->has('k2'));
    }

    public function testClearDropsTagIndexes(): void
    {
        $cache = $this->driver();
        $cache->set('k', 'v');
        $cache->addTags('k', ['t']);

        self::assertTrue($cache->clear());
        self::assertSame([], glob($this->directory . '/*') ?: []);

        $cache->set('k', 'after-clear');
        $cache->invalidateTags(['t']);
        self::assertSame('after-clear', $cache->get('k'));
    }

    public function testTheIndexIsSharedAcrossDriverInstances(): void
    {
        $this->driver()->set('k', 'v');
        $this->driver()->addTags('k', ['t']);

        $this->driver()->invalidateTags(['t']);
        self::assertFalse($this->driver()->has('k'));
    }

    public function testConcurrentWritersDoNotLoseIndexEntries(): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open is not available');
        }

        $workers = 6;
        $perWorker = 20;
        $autoload = dirname(__DIR__, 3) . '/vendor/autoload.php';
        $script = <<<'PHP'
            require $argv[1];
            $dir = $argv[2];
            $worker = $argv[3];
            $count = (int) $argv[4];
            $storage = new Glueful\Storage\StorageManager(
                ['default' => 'cache', 'disks' => ['cache' => ['driver' => 'local', 'root' => $dir]]],
                new Glueful\Storage\PathGuard()
            );
            $driver = new Glueful\Cache\Drivers\FileCacheDriver($dir, $storage, new Glueful\Services\FileFinder());
            for ($i = 0; $i < $count; $i++) {
                $key = "w{$worker}:{$i}";
                $driver->set($key, $i);
                if (!$driver->addTags($key, ['shared'])) {
                    exit(1);
                }
            }
            PHP;

        $this->driver(); // creates the directory
        $processes = [];
        for ($w = 0; $w < $workers; $w++) {
            $cmd = [PHP_BINARY, '-r', $script, $autoload, $this->directory, (string) $w, (string) $perWorker];
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($proc);
            $processes[] = [$proc, $pipes];
        }
        foreach ($processes as [$proc, $pipes]) {
            $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($proc), 'worker failed: ' . $out);
        }

        $cache = $this->driver();
        self::assertCount($workers * $perWorker, $cache->getKeys('w*'));
        self::assertTrue($cache->invalidateTags(['shared']));
        self::assertSame([], $cache->getKeys('w*'), 'Every concurrently tagged key must be in the index.');
    }
}
