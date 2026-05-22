<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Cache;

use Glueful\Cache\Drivers\FileCacheDriver;
use Glueful\Cache\Drivers\MemcachedCacheDriver;
use Glueful\Services\FileFinder;
use Glueful\Storage\PathGuard;
use Glueful\Storage\StorageManager;
use PHPUnit\Framework\TestCase;

/**
 * Ensures the Memcached and File drivers consistently report tagging as
 * unsupported and return false from both addTags()/invalidateTags(), so
 * callers can rely on the capability flag to branch.
 */
final class UnsupportedTagsContractTest extends TestCase
{
    public function testMemcachedDriverReportsTagsUnsupported(): void
    {
        if (!extension_loaded('memcached')) {
            $this->markTestSkipped('memcached extension not available');
        }

        $driver = new MemcachedCacheDriver(new \Memcached());
        $capabilities = $driver->getCapabilities();

        self::assertFalse($capabilities['features']['tags']);
        self::assertFalse($driver->addTags('any-key', ['tag']));
        self::assertFalse($driver->invalidateTags(['tag']));
    }

    public function testFileDriverReportsTagsUnsupported(): void
    {
        $directory = sys_get_temp_dir() . '/glueful-tags-test-' . uniqid('', true);

        $storage = new StorageManager(
            [
                'default' => 'cache',
                'disks' => [
                    'cache' => [
                        'driver' => 'local',
                        'root' => $directory,
                        'visibility' => 'private',
                    ],
                ],
            ],
            new PathGuard()
        );

        $driver = new FileCacheDriver($directory, $storage, new FileFinder(), 'cache');

        try {
            $capabilities = $driver->getCapabilities();
            self::assertFalse($capabilities['features']['tags']);
            self::assertFalse($driver->addTags('any-key', ['tag']));
            self::assertFalse($driver->invalidateTags(['tag']));
        } finally {
            $this->cleanupDirectory($directory);
        }
    }

    private function cleanupDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $this->cleanupDirectory($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }
}
