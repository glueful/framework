<?php

declare(strict_types=1);

namespace Glueful\Storage\Contracts;

use League\Flysystem\FilesystemOperator;

/**
 * Constructs a Flysystem disk for a single storage driver.
 */
interface StorageDriverFactoryInterface
{
    public function driver(): string;

    /**
     * @param array<string, mixed> $config
     */
    public function create(array $config): FilesystemOperator;

    /**
     * @param array<string, mixed> $config
     */
    public function available(array $config): bool;

    /**
     * @param array<string, mixed> $config
     * @return array{
     *   supports_atomic_move?: bool,
     *   supports_native_signed_urls?: bool,
     *   cloud?: bool
     * }
     */
    public function features(array $config): array;
}
