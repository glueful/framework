<?php

declare(strict_types=1);

namespace Glueful\Storage\Drivers;

use Glueful\Storage\Contracts\StorageDriverFactoryInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;

final class MemoryStorageDriverFactory implements StorageDriverFactoryInterface
{
    public function driver(): string
    {
        return 'memory';
    }

    /**
     * @param array<string, mixed> $config
     */
    public function create(array $config): FilesystemOperator
    {
        return new Filesystem(new InMemoryFilesystemAdapter());
    }

    /**
     * @param array<string, mixed> $config
     */
    public function available(array $config): bool
    {
        return class_exists(InMemoryFilesystemAdapter::class);
    }

    /**
     * @param array<string, mixed> $config
     * @return array{supports_atomic_move: bool, cloud: bool}
     */
    public function features(array $config): array
    {
        return ['supports_atomic_move' => true, 'cloud' => false];
    }
}
