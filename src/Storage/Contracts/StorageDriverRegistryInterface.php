<?php

declare(strict_types=1);

namespace Glueful\Storage\Contracts;

interface StorageDriverRegistryInterface
{
    public function register(string $driver, StorageDriverFactoryInterface $factory): void;

    public function has(string $driver): bool;

    public function get(string $driver): StorageDriverFactoryInterface;
}
