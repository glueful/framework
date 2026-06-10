<?php

declare(strict_types=1);

namespace Glueful\Storage;

use Glueful\Storage\Contracts\StorageDriverFactoryInterface;
use Glueful\Storage\Contracts\StorageDriverRegistryInterface;
use Glueful\Storage\Drivers\LocalStorageDriverFactory;
use Glueful\Storage\Drivers\MemoryStorageDriverFactory;
use Glueful\Storage\Exceptions\UnsupportedStorageDriverException;
use Psr\Log\LoggerInterface;

final class StorageDriverRegistry implements StorageDriverRegistryInterface
{
    /** @var array<string, StorageDriverFactoryInterface> */
    private array $factories = [];

    public function __construct(private ?LoggerInterface $logger = null)
    {
    }

    public static function withBuiltIns(?LoggerInterface $logger = null): self
    {
        $registry = new self($logger);
        $registry->register('local', new LocalStorageDriverFactory());
        $registry->register('memory', new MemoryStorageDriverFactory());

        return $registry;
    }

    public function register(string $driver, StorageDriverFactoryInterface $factory): void
    {
        if (isset($this->factories[$driver]) && $this->logger !== null) {
            $this->logger->debug('Storage driver factory replaced', [
                'driver' => $driver,
                'previous' => $this->factories[$driver]::class,
                'replacement' => $factory::class,
            ]);
        }

        $this->factories[$driver] = $factory;
    }

    public function has(string $driver): bool
    {
        return isset($this->factories[$driver]);
    }

    public function get(string $driver): StorageDriverFactoryInterface
    {
        if (!isset($this->factories[$driver])) {
            throw UnsupportedStorageDriverException::forDriver($driver);
        }

        return $this->factories[$driver];
    }
}
