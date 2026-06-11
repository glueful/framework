<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Storage;

use Glueful\Storage\Contracts\NativeSignedUrlProviderInterface;
use Glueful\Storage\Contracts\StorageDriverFactoryInterface;
use Glueful\Storage\StorageDriverRegistry;
use Glueful\Storage\StorageManager;
use Glueful\Storage\Support\UrlGenerator;
use Glueful\Uploader\Storage\FlysystemStorage;
use Glueful\Uploader\UploadException;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;

final class FlysystemStorageTest extends TestCase
{
    private StorageManager $storage;
    private UrlGenerator $urls;
    private string $disk = 'memory';

    protected function setUp(): void
    {
        $config = [
            'default' => $this->disk,
            'disks' => [
                'memory' => ['driver' => 'memory'],
            ],
        ];

        $this->storage = new StorageManager($config, new \Glueful\Storage\PathGuard());
        $this->urls = new UrlGenerator($config, new \Glueful\Storage\PathGuard());
    }

    public function testStoreExistsAndDelete(): void
    {
        $fs = new FlysystemStorage($this->storage, $this->urls, $this->disk);

        $tmp = tmpfile();
        fwrite($tmp, 'hello-world');
        $meta = stream_get_meta_data($tmp);
        $path = $meta['uri'];

        $dest = 'uploads/test.txt';
        $stored = $fs->store($path, $dest);
        $this->assertSame($dest, $stored);

        $this->assertTrue($fs->exists($dest));
        $this->assertNotSame('', $fs->getUrl($dest));

        $this->assertTrue($fs->delete($dest));
        $this->assertFalse($fs->exists($dest));
    }

    public function testExistsAndDeleteRejectUnsafePaths(): void
    {
        $fs = new FlysystemStorage($this->storage, $this->urls, $this->disk);

        // An absolute / traversal path must not be probed or deleted with a raw path.
        self::assertFalse($fs->exists('/etc/passwd'), 'exists() must reject an unsafe path');
        self::assertFalse($fs->delete('/etc/passwd'), 'delete() must reject an unsafe path');

        // A valid path round-trips normally.
        $fs->storeContent('data', 'uploads/keep.txt');
        self::assertTrue($fs->exists('uploads/keep.txt'));
        self::assertTrue($fs->delete('uploads/keep.txt'));
        self::assertFalse($fs->exists('uploads/keep.txt'));
    }

    public function testStoreContentWritesValidPath(): void
    {
        $fs = new FlysystemStorage($this->storage, $this->urls, $this->disk);

        $stored = $fs->storeContent('hello-content', 'uploads/note.txt');

        $this->assertSame('uploads/note.txt', $stored);
        $this->assertTrue($fs->exists('uploads/note.txt'));
    }

    /**
     * storeContent() must route through PathGuard (like store()), so an absolute /
     * traversal / null-byte destination is rejected before any write -- it must not
     * write directly to the disk with an unvalidated, user-influenced path. An absolute
     * path is the case Flysystem's own normalizer does NOT reject (it relativizes it),
     * so it is the real cloud-driver hole the PathGuard invariant closes.
     */
    public function testStoreContentRejectsUnsafePath(): void
    {
        $fs = new FlysystemStorage($this->storage, $this->urls, $this->disk);

        $threw = false;
        try {
            $fs->storeContent('payload', '/etc/evil');
        } catch (UploadException) {
            $threw = true;
        }

        $this->assertTrue($threw, 'storeContent must reject an absolute destination path');
        $this->assertFalse($fs->exists('/etc/evil'), 'no content may be written for a rejected path');
    }

    public function testSignedUrlUsesNativeProviderCapability(): void
    {
        $factory = new class implements StorageDriverFactoryInterface, NativeSignedUrlProviderInterface {
            public function driver(): string
            {
                return 'native';
            }

            public function create(array $config): FilesystemOperator
            {
                return new Filesystem(new InMemoryFilesystemAdapter());
            }

            public function available(array $config): bool
            {
                return true;
            }

            public function features(array $config): array
            {
                return ['supports_native_signed_urls' => true];
            }

            public function temporaryUrl(string $path, int $ttl, array $diskConfig): ?string
            {
                return 'https://native.example/' . $path . '?ttl=' . $ttl;
            }
        };

        $fs = $this->storageForFactory($factory);

        $this->assertSame(
            'https://native.example/private/file.txt?ttl=120',
            $fs->getSignedUrl('private/file.txt', 120)
        );
    }

    public function testSignedUrlFallsBackWhenNativeProviderReturnsNull(): void
    {
        $factory = new class implements StorageDriverFactoryInterface, NativeSignedUrlProviderInterface {
            public function driver(): string
            {
                return 'native';
            }

            public function create(array $config): FilesystemOperator
            {
                return new Filesystem(new InMemoryFilesystemAdapter());
            }

            public function available(array $config): bool
            {
                return true;
            }

            public function features(array $config): array
            {
                return ['supports_native_signed_urls' => true];
            }

            public function temporaryUrl(string $path, int $ttl, array $diskConfig): ?string
            {
                return null;
            }
        };

        $fs = $this->storageForFactory($factory);

        $this->assertSame('https://cdn.example/private/file.txt', $fs->getSignedUrl('private/file.txt', 120));
    }

    public function testSignedUrlFallsBackWhenNativeProviderThrows(): void
    {
        $factory = new class implements StorageDriverFactoryInterface, NativeSignedUrlProviderInterface {
            public function driver(): string
            {
                return 'native';
            }

            public function create(array $config): FilesystemOperator
            {
                return new Filesystem(new InMemoryFilesystemAdapter());
            }

            public function available(array $config): bool
            {
                return true;
            }

            public function features(array $config): array
            {
                return ['supports_native_signed_urls' => true];
            }

            public function temporaryUrl(string $path, int $ttl, array $diskConfig): ?string
            {
                throw new \RuntimeException('provider unavailable');
            }
        };

        $fs = $this->storageForFactory($factory);

        $this->assertSame('https://cdn.example/private/file.txt', $fs->getSignedUrl('private/file.txt', 120));
    }

    private function storageForFactory(StorageDriverFactoryInterface $factory): FlysystemStorage
    {
        $config = [
            'default' => 'native',
            'disks' => [
                'native' => [
                    'driver' => 'native',
                    'base_url' => 'https://cdn.example',
                ],
            ],
        ];
        $registry = new StorageDriverRegistry();
        $registry->register('native', $factory);

        return new FlysystemStorage(
            new StorageManager($config, new \Glueful\Storage\PathGuard(), $registry),
            new UrlGenerator($config, new \Glueful\Storage\PathGuard()),
            'native'
        );
    }
}
