<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Storage;

use Glueful\Storage\PathGuard;
use Glueful\Storage\StorageDriverRegistry;
use Glueful\Storage\StorageManager;
use Glueful\Tests\Unit\Storage\Fixtures\SigningMemoryDriverFactory;
use PHPUnit\Framework\TestCase;

/**
 * A temporary URL is the disk's own: a driver that signs URLs (S3 and its presets, GCS, Azure)
 * gives one, and a disk that cannot (local, memory) gives none, so the caller serves the file.
 */
final class StorageManagerTemporaryUrlTest extends TestCase
{
    private function manager(): StorageManager
    {
        $registry = StorageDriverRegistry::withBuiltIns();
        $registry->register('signing-memory', new SigningMemoryDriverFactory());

        return new StorageManager(['default' => 'cloud', 'disks' => [
            'cloud' => ['driver' => 'signing-memory'],
            'refusing' => ['driver' => 'signing-memory', 'fail' => true],
            'mem' => ['driver' => 'memory'],
        ]], new PathGuard(), $registry);
    }

    public function testADiskThatSignsUrlsGivesOne(): void
    {
        self::assertSame(
            'https://bucket.example/a/b.webp?expires=300',
            $this->manager()->temporaryUrl('a/b.webp', 300, 'cloud'),
        );
        self::assertSame(
            'https://bucket.example/a/b.webp?expires=60',
            $this->manager()->temporaryUrl('a/b.webp', 60),
            'the default disk',
        );
    }

    public function testADiskThatCannotSignGivesNone(): void
    {
        self::assertNull($this->manager()->temporaryUrl('a/b.webp', 300, 'mem'));
    }

    public function testAFailedSignatureOrAnUnknownDiskGivesNone(): void
    {
        self::assertNull($this->manager()->temporaryUrl('a/b.webp', 300, 'refusing'));
        self::assertNull($this->manager()->temporaryUrl('a/b.webp', 300, 'nowhere'));
    }
}
