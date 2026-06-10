<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Storage;

use Glueful\Storage\Contracts\StorageDriverFactoryInterface;
use Glueful\Storage\Exceptions\UnsupportedStorageDriverException;
use Glueful\Storage\PathGuard;
use Glueful\Storage\StorageDriverRegistry;
use Glueful\Storage\StorageManager;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;

final class StorageManagerRegistryTest extends TestCase
{
    public function testDefaultConstructionUsesBuiltIns(): void
    {
        $config = ['default' => 'mem', 'disks' => ['mem' => ['driver' => 'memory']]];
        $manager = new StorageManager($config, new PathGuard());

        $this->assertTrue($manager->diskExists('mem'));
        $this->assertTrue($manager->drivers()->has('local'));
        $this->assertTrue($manager->drivers()->has('memory'));
        $this->assertInstanceOf(FilesystemOperator::class, $manager->disk('mem'));
    }

    public function testUnknownConfiguredDriverThrowsHelpfulException(): void
    {
        $manager = new StorageManager(
            ['default' => 's3', 'disks' => ['s3' => ['driver' => 's3']]],
            new PathGuard()
        );

        $this->expectException(UnsupportedStorageDriverException::class);
        $this->expectExceptionMessageMatches('/composer require glueful\/storage-s3/');
        $manager->disk('s3');
    }

    public function testDiskExistsDelegatesToFactoryAvailable(): void
    {
        $registry = StorageDriverRegistry::withBuiltIns();
        $registry->register('flaky', $this->factory('flaky', available: false));

        $config = [
            'default' => 'mem',
            'disks' => [
                'mem' => ['driver' => 'memory'],
                'gone' => ['driver' => 'flaky'],
                'unconfigured-driver' => ['driver' => 'nope'],
            ],
        ];
        $manager = new StorageManager($config, new PathGuard(), $registry);

        $this->assertTrue($manager->diskExists('mem'));
        $this->assertFalse($manager->diskExists('gone'));
        $this->assertFalse($manager->diskExists('nope-disk'));
        $this->assertFalse($manager->diskExists('unconfigured-driver'));
    }

    public function testExtensionFactoryResolvesADisk(): void
    {
        $registry = StorageDriverRegistry::withBuiltIns();
        $registry->register('fake', $this->factory('fake'));

        $manager = new StorageManager(
            ['default' => 'f', 'disks' => ['f' => ['driver' => 'fake']]],
            new PathGuard(),
            $registry
        );

        $this->assertInstanceOf(FilesystemOperator::class, $manager->disk('f'));
    }

    public function testPutStreamUsesDirectWriteWhenAtomicMoveUnsupported(): void
    {
        $adapter = new NonAtomicRecordingAdapter();
        $registry = StorageDriverRegistry::withBuiltIns();
        $registry->register('cloudish', $this->factory('cloudish', adapter: $adapter, atomicMove: false));
        $manager = new StorageManager(
            ['default' => 'c', 'disks' => ['c' => ['driver' => 'cloudish']]],
            new PathGuard(),
            $registry
        );

        $stream = fopen('php://temp', 'r+');
        $this->assertIsResource($stream);
        fwrite($stream, 'cloud-bytes');
        rewind($stream);

        $manager->putStream('x/y.txt', $stream, 'c');

        $this->assertSame(['x/y.txt'], $adapter->writes);
        $this->assertFalse($adapter->moveCalled);
        $this->assertSame('cloud-bytes', $adapter->contents['x/y.txt']);
    }

    public function testPutStreamDefaultsToAtomicMoveWhenFeatureIsMissing(): void
    {
        $adapter = new NonAtomicRecordingAdapter(throwOnMove: false);
        $registry = StorageDriverRegistry::withBuiltIns();
        $registry->register('default-atomic', $this->factory('default-atomic', adapter: $adapter, atomicMove: null));
        $manager = new StorageManager(
            ['default' => 'a', 'disks' => ['a' => ['driver' => 'default-atomic']]],
            new PathGuard(),
            $registry
        );

        $stream = fopen('php://temp', 'r+');
        $this->assertIsResource($stream);
        fwrite($stream, 'atomic-by-default');
        rewind($stream);

        $manager->putStream('x/y.txt', $stream, 'a');

        $this->assertTrue($adapter->moveCalled);
        $this->assertCount(1, $adapter->writes);
        $this->assertStringStartsWith('x/y_', $adapter->writes[0]);
        $this->assertStringContainsString('.tmp', $adapter->writes[0]);
        $this->assertSame('atomic-by-default', $adapter->contents['x/y.txt']);
    }

    private function factory(
        string $driver,
        bool $available = true,
        ?FilesystemAdapter $adapter = null,
        ?bool $atomicMove = true
    ): StorageDriverFactoryInterface {
        return new class ($driver, $available, $adapter, $atomicMove) implements StorageDriverFactoryInterface {
            public function __construct(
                private string $name,
                private bool $isAvailable,
                private ?FilesystemAdapter $adapter,
                private ?bool $atomicMove
            ) {
            }

            public function driver(): string
            {
                return $this->name;
            }

            public function create(array $config): FilesystemOperator
            {
                return new Filesystem($this->adapter ?? new InMemoryFilesystemAdapter());
            }

            public function available(array $config): bool
            {
                return $this->isAvailable;
            }

            public function features(array $config): array
            {
                if ($this->atomicMove === null) {
                    return [];
                }

                return ['supports_atomic_move' => $this->atomicMove];
            }
        };
    }
}

final class NonAtomicRecordingAdapter implements FilesystemAdapter
{
    /** @var array<int, string> */
    public array $writes = [];

    /** @var array<string, string> */
    public array $contents = [];

    public bool $moveCalled = false;

    public function __construct(private bool $throwOnMove = true)
    {
    }

    public function fileExists(string $path): bool
    {
        return isset($this->contents[$path]);
    }

    public function directoryExists(string $path): bool
    {
        return false;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->writes[] = $path;
        $this->contents[$path] = $contents;
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->writes[] = $path;
        $this->contents[$path] = stream_get_contents($contents) ?: '';
    }

    public function read(string $path): string
    {
        return $this->contents[$path] ?? '';
    }

    public function readStream(string $path)
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $this->read($path));
        rewind($stream);

        return $stream;
    }

    public function delete(string $path): void
    {
        unset($this->contents[$path]);
    }

    public function deleteDirectory(string $path): void
    {
    }

    public function createDirectory(string $path, Config $config): void
    {
    }

    public function setVisibility(string $path, string $visibility): void
    {
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, 'private');
    }

    public function mimeType(string $path): FileAttributes
    {
        return new FileAttributes($path, null, null, null, 'text/plain');
    }

    public function lastModified(string $path): FileAttributes
    {
        return new FileAttributes($path, null, null, time());
    }

    public function fileSize(string $path): FileAttributes
    {
        return new FileAttributes($path, strlen($this->contents[$path] ?? ''));
    }

    public function listContents(string $path, bool $deep): iterable
    {
        yield new DirectoryAttributes($path);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->moveCalled = true;
        if ($this->throwOnMove) {
            throw new \RuntimeException('move must not be called for non-atomic driver');
        }

        $this->contents[$destination] = $this->contents[$source] ?? '';
        unset($this->contents[$source]);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->contents[$destination] = $this->contents[$source] ?? '';
    }
}
