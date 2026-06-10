<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Container\Providers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Container\Container;
use Glueful\Container\Definition\TaggedIteratorDefinition;
use Glueful\Container\Definition\ValueDefinition;
use Glueful\Container\Providers\StorageProvider;
use Glueful\Container\Providers\TagCollector;
use Glueful\Storage\Contracts\StorageDriverFactoryInterface;
use Glueful\Storage\Contracts\StorageDriverRegistryInterface;
use Glueful\Storage\StorageManager;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;

final class StorageProviderRegistryTest extends TestCase
{
    public function testStorageProviderBindsRegistryWithCoreBuiltIns(): void
    {
        $container = $this->container();

        $registry = $container->get(StorageDriverRegistryInterface::class);

        $this->assertInstanceOf(StorageDriverRegistryInterface::class, $registry);
        $this->assertTrue($registry->has('local'));
        $this->assertTrue($registry->has('memory'));
        $this->assertFalse($registry->has('s3'));
    }

    public function testTaggedFactoryCanOverrideCoreBuiltIn(): void
    {
        $factory = $this->factory('memory');
        $container = $this->container([
            'storage.driver_factory' => new ValueDefinition('storage.driver_factory', [$factory]),
        ]);

        $registry = $container->get(StorageDriverRegistryInterface::class);

        $this->assertSame($factory, $registry->get('memory'));
    }

    public function testHigherPriorityTaggedFactoryWinsForSameDriver(): void
    {
        $low = $this->factory('memory');
        $high = $this->factory('memory');
        $container = $this->container([
            'low.factory' => new ValueDefinition('low.factory', $low),
            'high.factory' => new ValueDefinition('high.factory', $high),
            'storage.driver_factory' => new TaggedIteratorDefinition('storage.driver_factory', [
                ['service' => 'low.factory', 'priority' => 0],
                ['service' => 'high.factory', 'priority' => 100],
            ]),
        ]);

        $registry = $container->get(StorageDriverRegistryInterface::class);

        $this->assertSame($high, $registry->get('memory'));
    }

    public function testStorageManagerReceivesProviderRegistry(): void
    {
        $factory = $this->factory('fake');
        $container = $this->container([
            'storage.driver_factory' => new ValueDefinition('storage.driver_factory', [$factory]),
        ]);

        $manager = $container->get(StorageManager::class);

        $this->assertInstanceOf(StorageManager::class, $manager);
        $this->assertSame($factory, $manager->drivers()->get('fake'));
    }

    /**
     * @param array<string, mixed> $extraDefs
     */
    private function container(array $extraDefs = []): Container
    {
        $context = ApplicationContext::forTesting(dirname(__DIR__, 4));
        $provider = new StorageProvider(new TagCollector(), $context);

        return new Container(array_replace($provider->defs(), $extraDefs));
    }

    private function factory(string $driver): StorageDriverFactoryInterface
    {
        return new class ($driver) implements StorageDriverFactoryInterface {
            public function __construct(private string $driverName)
            {
            }

            public function driver(): string
            {
                return $this->driverName;
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
                return ['supports_atomic_move' => true, 'cloud' => false];
            }
        };
    }
}
