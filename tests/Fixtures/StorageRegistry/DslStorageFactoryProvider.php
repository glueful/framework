<?php

declare(strict_types=1);

namespace Glueful\Tests\Fixtures\StorageRegistry;

use Glueful\Storage\Contracts\StorageDriverFactoryInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;

final class DslStorageFactoryProvider
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function services(): array
    {
        return [
            RecordingStorageDriverFactory::class => [
                'class' => RecordingStorageDriverFactory::class,
                'tags' => [
                    ['name' => 'storage.driver_factory', 'priority' => 25],
                ],
            ],
        ];
    }
}

final class RecordingStorageDriverFactory implements StorageDriverFactoryInterface
{
    public function driver(): string
    {
        return 'recording';
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
}
