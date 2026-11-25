<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Storage\Fixtures;

use Glueful\Storage\Contracts\NativeSignedUrlProviderInterface;
use Glueful\Storage\Contracts\StorageDriverFactoryInterface;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;

/**
 * An in-memory disk that signs URLs the way a cloud driver (S3, GCS, Azure) does: it stands in for
 * any object store whose factory implements NativeSignedUrlProviderInterface. A disk configured
 * with `'fail' => true` throws while signing.
 */
final class SigningMemoryDriverFactory implements StorageDriverFactoryInterface, NativeSignedUrlProviderInterface
{
    public function driver(): string
    {
        return 'signing-memory';
    }

    /** @param array<string, mixed> $config */
    public function create(array $config): FilesystemOperator
    {
        return new Filesystem(new InMemoryFilesystemAdapter());
    }

    /** @param array<string, mixed> $config */
    public function available(array $config): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $config
     * @return array{supports_atomic_move: bool, supports_native_signed_urls: bool, cloud: bool}
     */
    public function features(array $config): array
    {
        return ['supports_atomic_move' => false, 'supports_native_signed_urls' => true, 'cloud' => true];
    }

    /** @param array<string, mixed> $diskConfig */
    public function temporaryUrl(string $path, int $ttl, array $diskConfig): ?string
    {
        if (($diskConfig['fail'] ?? false) === true) {
            throw new \RuntimeException('signing refused');
        }

        return 'https://bucket.example/' . $path . '?expires=' . $ttl;
    }
}
