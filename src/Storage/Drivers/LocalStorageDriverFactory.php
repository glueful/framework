<?php

declare(strict_types=1);

namespace Glueful\Storage\Drivers;

use Glueful\Storage\Contracts\StorageDriverFactoryInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;

final class LocalStorageDriverFactory implements StorageDriverFactoryInterface
{
    public function driver(): string
    {
        return 'local';
    }

    /**
     * @param array<string, mixed> $config
     */
    public function create(array $config): FilesystemOperator
    {
        if (!isset($config['root']) || $config['root'] === '') {
            throw new \InvalidArgumentException("Missing required local config: 'root'");
        }

        return new Filesystem(new LocalFilesystemAdapter(
            (string) $config['root'],
            PortableVisibilityConverter::fromArray([
                'file' => [
                    'public' => 0644,
                    'private' => 0600,
                ],
                'dir' => [
                    'public' => 0755,
                    'private' => 0700,
                ],
            ], (string) ($config['visibility'] ?? 'private'))
        ));
    }

    /**
     * @param array<string, mixed> $config
     */
    public function available(array $config): bool
    {
        return true;
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
