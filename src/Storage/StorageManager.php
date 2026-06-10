<?php

declare(strict_types=1);

namespace Glueful\Storage;

use League\Flysystem\FilesystemOperator;
use League\Flysystem\FilesystemException;
use Glueful\Storage\Contracts\StorageDriverRegistryInterface;
use Glueful\Storage\Exceptions\UnsupportedStorageDriverException;
use Glueful\Storage\Exceptions\StorageException;

class StorageManager
{
    /** @var array<string, FilesystemOperator> */
    private array $disks = [];
    private PathGuard $pathGuard;
    /** @var array<string, mixed> */
    private array $config;
    private StorageDriverRegistryInterface $drivers;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        array $config,
        PathGuard $pathGuard,
        ?StorageDriverRegistryInterface $drivers = null
    ) {
        $this->config = $config;
        $this->pathGuard = $pathGuard;
        $this->drivers = $drivers ?? StorageDriverRegistry::withBuiltIns();
    }

    /**
     * Get a filesystem disk instance
     */
    public function disk(?string $name = null): FilesystemOperator
    {
        $name = $name ?? $this->config['default'];

        if (!isset($this->disks[$name])) {
            $this->disks[$name] = $this->createDisk($name);
        }

        return $this->disks[$name];
    }

    /**
     * Create a filesystem disk instance based on configuration
     */
    private function createDisk(string $name): FilesystemOperator
    {
        if (!isset($this->config['disks'][$name])) {
            throw new \InvalidArgumentException(
                "Disk '{$name}' is not configured"
            );
        }
        $config = $this->config['disks'][$name];

        $driver = (string) ($config['driver'] ?? '');
        if (!$this->drivers->has($driver)) {
            throw UnsupportedStorageDriverException::forDriver($driver);
        }

        return $this->drivers->get($driver)->create($config);
    }

    /**
     * Check if a disk is configured and available
     */
    public function diskExists(string $name): bool
    {
        // Check if disk is configured
        if (!isset($this->config['disks'][$name])) {
            return false;
        }

        $config = $this->config['disks'][$name];
        $driver = (string) ($config['driver'] ?? '');

        if (!$this->drivers->has($driver)) {
            return false;
        }

        return $this->drivers->get($driver)->available($config);
    }

    public function drivers(): StorageDriverRegistryInterface
    {
        return $this->drivers;
    }

    /**
     * Generate a temporary file path for atomic operations
     */
    private function generateTempPath(string $path): string
    {
        $info = pathinfo($path);
        $dir = $info['dirname'] !== '.' ? $info['dirname'] . '/' : '';
        $filename = $info['filename'] ?? 'temp';
        $extension = isset($info['extension']) ? '.' . $info['extension'] : '';

        return $dir . $filename . '_' . uniqid() . '.tmp' . $extension;
    }

    /**
     * JSON helpers for common operations
     */
    public function putJson(string $path, mixed $data, ?string $disk = null): void
    {
        $path = $this->pathGuard->validate($path);
        $content = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        try {
            $this->disk($disk)->write($path, $content);
        } catch (FilesystemException $e) {
            throw StorageException::fromFlysystem($e, $path);
        }
    }

    public function getJson(string $path, ?string $disk = null): mixed
    {
        $path = $this->pathGuard->validate($path);
        try {
            $content = $this->disk($disk)->read($path);
        } catch (FilesystemException $e) {
            throw StorageException::fromFlysystem($e, $path);
        }
        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Stream a file using the driver factory's atomic-move capability metadata.
     *
     * Drivers default to temp-write + move for crash safety. Provider packs can
     * opt out with features()['supports_atomic_move'] = false when object-store
     * copy/move semantics make that pattern unsafe.
     *
     * @param resource $stream
     */
    public function putStream(string $path, $stream, ?string $disk = null): void
    {
        $path = $this->pathGuard->validate($path);

        try {
            try {
                if (!$this->supportsAtomicMove($disk)) {
                    $this->disk($disk)->writeStream($path, $stream);
                    return;
                }

                $temp = $this->generateTempPath($path);
                $this->disk($disk)->writeStream($temp, $stream);
                $this->disk($disk)->move($temp, $path);
            } catch (FilesystemException $e) {
                throw StorageException::fromFlysystem($e, $path);
            } finally {
                if (isset($temp)) {
                    try {
                        $this->disk($disk)->delete($temp);
                    } catch (\Throwable) {
                        // Ignore - temp file may have been moved
                    }
                }
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function supportsAtomicMove(?string $disk = null): bool
    {
        $name = $disk ?? (string) ($this->config['default'] ?? '');
        /** @var array<string, mixed> $config */
        $config = (array) ($this->config['disks'][$name] ?? []);
        $driver = (string) ($config['driver'] ?? '');

        if (!$this->drivers->has($driver)) {
            return true;
        }

        return ($this->drivers->get($driver)->features($config)['supports_atomic_move'] ?? true) === true;
    }

    /**
     * Basic listing for cloud disks
     * For advanced discovery, use FileFinder on local disks
     */
    /**
     * @return iterable<\League\Flysystem\StorageAttributes>
     */
    public function listContents(string $path, bool $recursive = false, ?string $disk = null): iterable
    {
        $path = $this->pathGuard->validate($path);
        try {
            return $this->disk($disk)->listContents($path, $recursive);
        } catch (FilesystemException $e) {
            throw StorageException::fromFlysystem($e, $path);
        }
    }
}
