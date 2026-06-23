<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Cache;

use Glueful\Cache\Drivers\FileCacheDriver;
use Glueful\Services\FileFinder;
use Glueful\Storage\PathGuard;
use Glueful\Storage\StorageManager;
use PHPUnit\Framework\TestCase;

/**
 * Regression: the framework namespaces every cache key with a colon (session:, provider:,
 * user_permissions:, …). The Redis driver allows colons; the File and Memcached drivers used to
 * ban them, which made SessionCacheManager throw "contains invalid characters" on login whenever
 * the file cache backend was active. The file driver md5-hashes the key into a filename, so a
 * colon is filesystem-safe — it must round-trip like any other key.
 */
final class ColonKeyValidationTest extends TestCase
{
    public function testFileDriverAcceptsColonNamespacedKeys(): void
    {
        $directory = sys_get_temp_dir() . '/glueful-colon-key-test-' . uniqid('', true);

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
            // Mirrors the key SessionCacheManager builds: 'session:' . token.
            self::assertTrue($driver->set('session:xcuuu0kAK8t3', ['user' => 'u1'], 3600));
            self::assertSame(['user' => 'u1'], $driver->get('session:xcuuu0kAK8t3'));
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
