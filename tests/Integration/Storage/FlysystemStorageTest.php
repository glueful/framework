<?php

declare(strict_types=1);

namespace Glueful\Tests\Integration\Storage;

use Glueful\Storage\Contracts\NativeSignedUrlProviderInterface;
use Glueful\Storage\Contracts\StorageDriverFactoryInterface;
use Glueful\Storage\StorageDriverRegistry;
use Glueful\Storage\StorageManager;
use Glueful\Storage\Support\UrlGenerator;
use Glueful\Uploader\Storage\FlysystemStorage;
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
