<?php

declare(strict_types=1);

namespace Glueful\Uploader;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Storage\StorageManager;
use Psr\Log\LoggerInterface;

use function config;

/**
 * Resized copies of an image (variants), stored as files and kept until the image changes.
 *
 * A copy lives at `<prefix>/<uuid>/<version>/<settings>.<ext>` on the image's own disk (local, S3,
 * R2…) or on `uploads.image_processing.variant_disk` when one is set. `<version>` is the blob's
 * BlobVersion, so an image replaced in place misses and is rendered again; storing the first copy of
 * a new version removes the older versions' folders. `<settings>` names the resize settings, in any
 * order. At most `max_stored_variants` copies are kept per version, so arbitrary sizes asked of
 * the route cannot fill the disk: past that a copy is served without being stored.
 *
 * Storage trouble never fails a request: a read that fails is a miss, a write that fails is
 * reported (false) and logged, and the caller serves what it rendered.
 */
final class BlobVariantStore
{
    public function __construct(
        private readonly StorageManager $storage,
        private readonly string $defaultDisk,
        private readonly ?string $variantDisk = null,
        private readonly string $prefix = '_variants',
        private readonly int $maxPerVersion = 32,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public static function fromContext(ApplicationContext $context): self
    {
        $container = $context->getContainer();
        $logger = $container->has(LoggerInterface::class) ? $container->get(LoggerInterface::class) : null;
        $variantDisk = config($context, 'uploads.image_processing.variant_disk');
        $prefix = trim((string) config($context, 'uploads.image_processing.variant_prefix', '_variants'), '/');

        return new self(
            $container->get(StorageManager::class),
            (string) config($context, 'uploads.disk', 'uploads'),
            is_string($variantDisk) && $variantDisk !== '' ? $variantDisk : null,
            $prefix !== '' ? $prefix : '_variants',
            (int) config($context, 'uploads.image_processing.max_stored_variants', 32),
            $logger instanceof LoggerInterface ? $logger : null,
        );
    }

    /**
     * The resize settings in one shape and order, every key present: how the route reads a request
     * and how a pre-generated copy must be named to be found by it.
     *
     * @param array<string, mixed> $resize
     * @return array{width: int|null, height: int|null, quality: int|null, format: string|null, fit: string|null}
     */
    public static function normalize(array $resize): array
    {
        return [
            'width' => self::positiveInt($resize['width'] ?? null),
            'height' => self::positiveInt($resize['height'] ?? null),
            'quality' => self::positiveInt($resize['quality'] ?? null),
            'format' => self::nonEmptyString($resize['format'] ?? null),
            'fit' => self::nonEmptyString($resize['fit'] ?? null),
        ];
    }

    private static function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The stored copy's bytes, or null when there is none or it cannot be read.
     *
     * @param array<string, mixed> $blob
     * @param array<string, mixed> $resize
     */
    public function get(array $blob, array $resize, string $mime): ?string
    {
        $path = $this->path($blob, $resize, $mime);
        if ($path === null) {
            return null;
        }
        try {
            $disk = $this->storage->disk($this->diskFor($blob));
            return $disk->fileExists($path) ? $disk->read($path) : null;
        } catch (\Throwable $e) {
            $this->logger?->warning('Stored image variant could not be read; rendering it again', [
                'blob_uuid' => (string) ($blob['uuid'] ?? ''),
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Whether a copy is stored, without reading it.
     *
     * @param array<string, mixed> $blob
     * @param array<string, mixed> $resize
     */
    public function has(array $blob, array $resize, string $mime): bool
    {
        $path = $this->path($blob, $resize, $mime);
        if ($path === null) {
            return false;
        }
        try {
            return $this->storage->disk($this->diskFor($blob))->fileExists($path);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Store a copy. False when it was not stored: the version already holds the most copies it may,
     * or the disk refused the write.
     *
     * @param array<string, mixed> $blob
     * @param array<string, mixed> $resize
     */
    public function put(array $blob, array $resize, string $mime, string $data): bool
    {
        $path = $this->path($blob, $resize, $mime);
        if ($path === null) {
            return false;
        }
        $diskName = $this->diskFor($blob);
        try {
            $disk = $this->storage->disk($diskName);
            $versionDir = dirname($path);
            $stored = 0;
            foreach ($disk->listContents($versionDir, false) as $item) {
                if ($item->isFile()) {
                    if ($item->path() === $path) {
                        return true;
                    }
                    $stored++;
                }
            }
            if ($this->maxPerVersion > 0 && $stored >= $this->maxPerVersion) {
                return false;
            }
            if ($stored === 0) {
                $this->removeOtherVersions($disk, dirname($versionDir), $versionDir);
            }
            $stream = fopen('php://temp', 'w+b');
            if ($stream === false) {
                return false;
            }
            fwrite($stream, $data);
            rewind($stream);
            // Written under a temporary name and moved into place, so a request never reads half.
            $this->storage->putStream($path, $stream, $diskName);

            return true;
        } catch (\Throwable $e) {
            $this->logger?->warning('Image variant not stored', [
                'blob_uuid' => (string) ($blob['uuid'] ?? ''),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Remove every stored copy of an image, all versions.
     *
     * @param array<string, mixed> $blob
     */
    public function forget(array $blob): void
    {
        $uuid = (string) ($blob['uuid'] ?? '');
        if (!self::safeSegment($uuid)) {
            return;
        }
        $this->storage->disk($this->diskFor($blob))->deleteDirectory($this->prefix . '/' . $uuid);
    }

    /**
     * Remove stored copies that are no longer reachable from one disk: an image the callback
     * no longer knows (null), or a version other than the one it names. With `$all`, every copy.
     *
     * @param callable(string): (string|null) $currentVersion the image's current version, or null
     * @return array{images: int, removed: int}
     */
    public function prune(string $diskName, callable $currentVersion, bool $all = false): array
    {
        $disk = $this->storage->disk($diskName);
        $images = 0;
        $removed = 0;
        foreach ($disk->listContents($this->prefix, false) as $image) {
            if (!$image->isDir()) {
                continue;
            }
            $images++;
            $uuid = basename($image->path());
            $version = $all ? null : $currentVersion($uuid);
            if ($version === null) {
                $disk->deleteDirectory($image->path());
                $removed++;
                continue;
            }
            $removed += $this->removeOtherVersions($disk, $image->path(), $image->path() . '/' . $version);
        }

        return ['images' => $images, 'removed' => $removed];
    }

    /** The one disk every copy is stored on, or null when each is beside its image. */
    public function variantDisk(): ?string
    {
        return $this->variantDisk;
    }

    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * @param array<string, mixed> $blob
     */
    private function diskFor(array $blob): string
    {
        if ($this->variantDisk !== null) {
            return $this->variantDisk;
        }
        $disk = (string) ($blob['storage_type'] ?? '');

        return $disk !== '' ? $disk : $this->defaultDisk;
    }

    /**
     * @param array<string, mixed> $blob
     * @param array<string, mixed> $resize
     */
    private function path(array $blob, array $resize, string $mime): ?string
    {
        $uuid = (string) ($blob['uuid'] ?? '');
        $ext = self::extension($mime);
        if (!self::safeSegment($uuid) || $ext === null) {
            return null;
        }
        $settings = substr(sha1((string) json_encode(self::normalize($resize))), 0, 16);

        return $this->prefix . '/' . $uuid . '/' . BlobVersion::of($blob) . '/' . $settings . '.' . $ext;
    }

    private function removeOtherVersions(
        \League\Flysystem\FilesystemOperator $disk,
        string $imageDir,
        string $keep,
    ): int {
        $removed = 0;
        foreach ($disk->listContents($imageDir, false) as $item) {
            if ($item->path() !== $keep) {
                $item->isDir() ? $disk->deleteDirectory($item->path()) : $disk->delete($item->path());
                $removed++;
            }
        }

        return $removed;
    }

    private static function extension(string $mime): ?string
    {
        return match (strtolower(trim(explode(';', $mime, 2)[0]))) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            default => null,
        };
    }

    /** A uuid as a path segment: no separators, no dots, nothing a path could be walked out of. */
    private static function safeSegment(string $value): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $value) === 1;
    }
}
