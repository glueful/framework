<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Uploader;

use Glueful\Storage\PathGuard;
use Glueful\Storage\StorageDriverRegistry;
use Glueful\Storage\StorageManager;
use Glueful\Tests\Unit\Storage\Fixtures\SigningMemoryDriverFactory;
use Glueful\Uploader\BlobVariantStore;
use Glueful\Uploader\BlobVersion;
use PHPUnit\Framework\TestCase;

/**
 * Resized copies of an image are files beside it, kept until the image changes: the app cache held
 * them base64-encoded for seven days, so every copy was rendered again each week and cost a second
 * on the request that found it gone.
 */
final class BlobVariantStoreTest extends TestCase
{
    private const WEBP_640 = ['width' => 640, 'height' => null, 'quality' => null, 'format' => 'webp', 'fit' => null];

    private StorageManager $storage;

    protected function setUp(): void
    {
        $registry = StorageDriverRegistry::withBuiltIns();
        $registry->register('signing-memory', new SigningMemoryDriverFactory());
        $this->storage = new StorageManager(
            ['default' => 'uploads', 'disks' => [
                'uploads' => ['driver' => 'memory'],
                'bucket' => ['driver' => 'memory'],
                'cache' => ['driver' => 'memory'],
                'cloud' => ['driver' => 'signing-memory'],
            ]],
            new PathGuard(),
            $registry,
        );
    }

    /** @param array<string, mixed> $options */
    private function store(array $options = []): BlobVariantStore
    {
        return new BlobVariantStore(
            $this->storage,
            'uploads',
            $options['variant_disk'] ?? null,
            '_variants',
            $options['max'] ?? 32,
            $options['redirect_ttl'] ?? 0,
        );
    }

    /** @return array<string, mixed> */
    private function blob(string $disk = 'uploads', string $updatedAt = '2026-11-01 10:00:00', int $size = 1000): array
    {
        return [
            'uuid' => 'blob00000001', 'storage_type' => $disk, 'mime_type' => 'image/png',
            'updated_at' => $updatedAt, 'size' => $size,
        ];
    }

    public function testACopyIsReadBackAfterItIsStored(): void
    {
        $store = $this->store();
        self::assertNull($store->get($this->blob(), self::WEBP_640, 'image/webp'));

        self::assertTrue($store->put($this->blob(), self::WEBP_640, 'image/webp', 'WEBP-BYTES'));

        self::assertSame('WEBP-BYTES', $store->get($this->blob(), self::WEBP_640, 'image/webp'));
        self::assertNull($store->get($this->blob(), ['width' => 320] + self::WEBP_640, 'image/webp'));
    }

    public function testCopiesLiveBesideTheImageUnderItsVersion(): void
    {
        $this->store()->put($this->blob(), self::WEBP_640, 'image/webp', 'X');

        $version = BlobVersion::of($this->blob());
        $files = $this->files('uploads');
        self::assertCount(1, $files);
        self::assertMatchesRegularExpression(
            '~^_variants/blob00000001/' . $version . '/[a-f0-9]{16}\.webp$~',
            $files[0],
        );
    }

    public function testAnImageOnABucketKeepsItsCopiesThere(): void
    {
        $this->store()->put($this->blob('bucket'), self::WEBP_640, 'image/webp', 'X');

        self::assertSame([], $this->files('uploads'));
        self::assertCount(1, $this->files('bucket'));
    }

    public function testAVariantDiskHoldsEveryCopyWhereverTheImageIs(): void
    {
        $store = $this->store(['variant_disk' => 'cache']);
        $store->put($this->blob('bucket'), self::WEBP_640, 'image/webp', 'X');

        self::assertSame([], $this->files('bucket'));
        self::assertCount(1, $this->files('cache'));
        self::assertSame('X', $store->get($this->blob('bucket'), self::WEBP_640, 'image/webp'));
    }

    public function testAChangedImageMissesAndItsOlderCopiesAreRemoved(): void
    {
        $store = $this->store();
        $store->put($this->blob(), self::WEBP_640, 'image/webp', 'OLD');
        $store->put($this->blob(), ['width' => 320] + self::WEBP_640, 'image/webp', 'OLD');

        $changed = $this->blob(updatedAt: '2026-11-20 09:00:00', size: 800);
        self::assertNull($store->get($changed, self::WEBP_640, 'image/webp'));

        $store->put($changed, self::WEBP_640, 'image/webp', 'NEW');

        self::assertSame('NEW', $store->get($changed, self::WEBP_640, 'image/webp'));
        $files = $this->files('uploads');
        self::assertCount(1, $files, 'the old version\'s two copies are gone');
        self::assertStringContainsString('/' . BlobVersion::of($changed) . '/', $files[0]);
    }

    public function testAVersionHoldsAtMostTheConfiguredNumberOfCopies(): void
    {
        $store = $this->store(['max' => 2]);
        self::assertTrue($store->put($this->blob(), ['width' => 100] + self::WEBP_640, 'image/webp', 'A'));
        self::assertTrue($store->put($this->blob(), ['width' => 200] + self::WEBP_640, 'image/webp', 'B'));

        self::assertFalse($store->put($this->blob(), ['width' => 300] + self::WEBP_640, 'image/webp', 'C'));

        self::assertCount(2, $this->files('uploads'));
        self::assertNull($store->get($this->blob(), ['width' => 300] + self::WEBP_640, 'image/webp'));
        // Writing a copy that is already stored is not a new one.
        self::assertTrue($store->put($this->blob(), ['width' => 100] + self::WEBP_640, 'image/webp', 'A'));
    }

    public function testTheSameSettingsInAnyOrderNameOneCopy(): void
    {
        $store = $this->store();
        $store->put($this->blob(), self::WEBP_640, 'image/webp', 'X');

        self::assertSame('X', $store->get($this->blob(), array_reverse(self::WEBP_640, true), 'image/webp'));
        self::assertSame('X', $store->get($this->blob(), ['format' => 'webp', 'width' => 640], 'image/webp'));
    }

    public function testForgetRemovesEveryCopyOfAnImage(): void
    {
        $store = $this->store();
        $store->put($this->blob(), self::WEBP_640, 'image/webp', 'X');
        $other = ['uuid' => 'blob00000002'] + $this->blob();
        $store->put($other, self::WEBP_640, 'image/webp', 'Y');

        $store->forget($this->blob());

        self::assertNull($store->get($this->blob(), self::WEBP_640, 'image/webp'));
        self::assertSame('Y', $store->get($other, self::WEBP_640, 'image/webp'));
    }

    public function testPruneRemovesCopiesNothingCanReach(): void
    {
        $store = $this->store();
        $current = $this->blob();
        $gone = ['uuid' => 'blob00000002'] + $this->blob();
        $changed = ['uuid' => 'blob00000003'] + $this->blob();
        foreach ([$current, $gone, $changed] as $blob) {
            $store->put($blob, self::WEBP_640, 'image/webp', 'X');
        }
        $newVersion = BlobVersion::of(['updated_at' => '2026-12-01 00:00:00', 'size' => 5]);
        $versions = [
            'blob00000001' => BlobVersion::of($current),
            'blob00000003' => $newVersion,
        ];

        $result = $store->prune('uploads', fn (string $uuid): ?string => $versions[$uuid] ?? null);

        self::assertSame(['images' => 3, 'removed' => 2], $result);
        self::assertSame('X', $store->get($current, self::WEBP_640, 'image/webp'));
        self::assertNull($store->get($gone, self::WEBP_640, 'image/webp'));
        self::assertNull($store->get($changed, self::WEBP_640, 'image/webp'));
    }

    public function testPruneAllRemovesEveryCopy(): void
    {
        $store = $this->store();
        $store->put($this->blob(), self::WEBP_640, 'image/webp', 'X');

        $result = $store->prune('uploads', fn (): string => BlobVersion::of($this->blob()), all: true);

        self::assertSame(['images' => 1, 'removed' => 1], $result);
        self::assertSame([], $this->files('uploads'));
    }

    public function testADiskThatFailsIsAMissAndAWriteThatFailsIsReported(): void
    {
        $store = new BlobVariantStore(
            new StorageManager(['default' => 'gone', 'disks' => []], new PathGuard()),
            'gone',
            null,
            '_variants',
            32,
        );

        self::assertNull($store->get($this->blob('gone'), self::WEBP_640, 'image/webp'));
        self::assertFalse($store->put($this->blob('gone'), self::WEBP_640, 'image/webp', 'X'));
    }

    public function testAStoredCopyOnADiskThatSignsUrlsGetsASignedUrl(): void
    {
        $store = $this->store(['redirect_ttl' => 300]);
        $store->put($this->blob('cloud'), self::WEBP_640, 'image/webp', 'X');

        $url = $store->temporaryUrl($this->blob('cloud'), self::WEBP_640, 'image/webp');

        self::assertNotNull($url);
        self::assertMatchesRegularExpression(
            '~^https://bucket\.example/_variants/blob00000001/' . BlobVersion::of($this->blob('cloud'))
                . '/[a-f0-9]{16}\.webp\?expires=300$~',
            $url,
        );
    }

    public function testNoSignedUrlWithoutAStoredCopyOrWithRedirectsOff(): void
    {
        $store = $this->store(['redirect_ttl' => 300]);
        self::assertNull($store->temporaryUrl($this->blob('cloud'), self::WEBP_640, 'image/webp'));

        $off = $this->store();
        $off->put($this->blob('cloud'), self::WEBP_640, 'image/webp', 'X');
        self::assertNull($off->temporaryUrl($this->blob('cloud'), self::WEBP_640, 'image/webp'));
    }

    public function testNoSignedUrlWhereTheDiskCannotSign(): void
    {
        $store = $this->store(['redirect_ttl' => 300]);
        $store->put($this->blob(), self::WEBP_640, 'image/webp', 'X');

        self::assertNull($store->temporaryUrl($this->blob(), self::WEBP_640, 'image/webp'));
    }

    public function testAVariantDiskThatSignsUrlsSignsCopiesOfImagesStoredAnywhere(): void
    {
        $store = $this->store(['variant_disk' => 'cloud', 'redirect_ttl' => 300]);
        $store->put($this->blob(), self::WEBP_640, 'image/webp', 'X');

        self::assertStringStartsWith(
            'https://bucket.example/_variants/',
            (string) $store->temporaryUrl($this->blob(), self::WEBP_640, 'image/webp'),
        );
    }

    /** @return list<string> every file path on the disk */
    private function files(string $disk): array
    {
        $paths = [];
        foreach ($this->storage->disk($disk)->listContents('', true) as $item) {
            if ($item->isFile()) {
                $paths[] = $item->path();
            }
        }
        sort($paths);

        return $paths;
    }
}
