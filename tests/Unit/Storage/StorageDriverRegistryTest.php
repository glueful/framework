<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Storage;

use Glueful\Storage\Contracts\StorageDriverFactoryInterface;
use Glueful\Storage\Contracts\StorageDriverRegistryInterface;
use Glueful\Storage\Drivers\LocalStorageDriverFactory;
use Glueful\Storage\Drivers\MemoryStorageDriverFactory;
use Glueful\Storage\Exceptions\UnsupportedStorageDriverException;
use Glueful\Storage\StorageDriverRegistry;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;

final class StorageDriverRegistryTest extends TestCase
{
    public function testImplementsContract(): void
    {
        $this->assertInstanceOf(StorageDriverRegistryInterface::class, new StorageDriverRegistry());
    }

    public function testWithBuiltInsRegistersLocalAndMemoryOnly(): void
    {
        $registry = StorageDriverRegistry::withBuiltIns();

        $this->assertTrue($registry->has('local'));
        $this->assertTrue($registry->has('memory'));
        $this->assertFalse($registry->has('s3'));
        $this->assertInstanceOf(LocalStorageDriverFactory::class, $registry->get('local'));
        $this->assertInstanceOf(MemoryStorageDriverFactory::class, $registry->get('memory'));
    }

    public function testGetUnknownDriverThrows(): void
    {
        $registry = StorageDriverRegistry::withBuiltIns();

        $this->expectException(UnsupportedStorageDriverException::class);
        $registry->get('s3');
    }

    public function testRegisterOverwritesSameDriverLastWins(): void
    {
        $registry = StorageDriverRegistry::withBuiltIns();
        $fake = $this->fakeFactory('memory');

        $registry->register('memory', $fake);

        $this->assertSame($fake, $registry->get('memory'));
    }

    public function testRegisterNewDriverIsResolvable(): void
    {
        $registry = new StorageDriverRegistry();
        $fake = $this->fakeFactory('fake');
        $registry->register('fake', $fake);

        $this->assertTrue($registry->has('fake'));
        $this->assertSame($fake, $registry->get('fake'));
    }

    private function fakeFactory(string $driver): StorageDriverFactoryInterface
    {
        return new class ($driver) implements StorageDriverFactoryInterface {
            public function __construct(private string $name)
            {
            }

            public function driver(): string
            {
                return $this->name;
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
                return ['supports_atomic_move' => false, 'cloud' => true];
            }
        };
    }
}
