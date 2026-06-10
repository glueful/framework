<?php

declare(strict_types=1);

namespace Glueful\Uploader\Storage;

use Glueful\Storage\Contracts\NativeSignedUrlProviderInterface;
use Glueful\Storage\StorageManager;
use Glueful\Storage\Support\UrlGenerator;
use Glueful\Uploader\UploadException;
use League\Flysystem\FilesystemException;

class FlysystemStorage implements StorageInterface
{
    public function __construct(
        private readonly StorageManager $storage,
        private readonly UrlGenerator $urls,
        private readonly string $disk
    ) {
    }

    public function store(string $sourcePath, string $destinationPath): string
    {
        $stream = fopen($sourcePath, 'r');
        if ($stream === false) {
            throw new UploadException('Failed to open uploaded file');
        }

        try {
            $this->storage->putStream($destinationPath, $stream, $this->disk);
        } catch (\Throwable $e) {
            throw new UploadException('Storage write failed: ' . $e->getMessage(), 0, $e);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $destinationPath;
    }

    public function storeContent(string $content, string $destinationPath): string
    {
        try {
            $this->storage->disk($this->disk)->write($destinationPath, $content);
        } catch (\Throwable $e) {
            if ($e instanceof FilesystemException) {
                throw new UploadException('Storage write failed: ' . $e->getMessage(), 0, $e);
            }
            throw new UploadException('Storage write failed', 0, $e);
        }

        return $destinationPath;
    }

    public function getUrl(string $path): string
    {
        return $this->urls->url($path, $this->disk);
    }

    public function exists(string $path): bool
    {
        try {
            return $this->storage->disk($this->disk)->fileExists($path);
        } catch (\Throwable) {
            return false;
        }
    }

    public function delete(string $path): bool
    {
        try {
            $this->storage->disk($this->disk)->delete($path);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function getSignedUrl(string $path, int $expiry = 3600): string
    {
        $cfg = $this->urls->diskConfig($this->disk);
        $driver = (string) ($cfg['driver'] ?? '');

        if ($driver !== '' && $this->storage->drivers()->has($driver)) {
            $factory = $this->storage->drivers()->get($driver);
            if ($factory instanceof NativeSignedUrlProviderInterface) {
                $ttl = $expiry > 0 ? $expiry : (int) ($cfg['signed_ttl'] ?? 3600);

                try {
                    $url = $factory->temporaryUrl($path, $ttl, $cfg);
                    if ($url !== null && $url !== '') {
                        return $url;
                    }
                } catch (\Throwable) {
                    // Fall through to the public/app URL.
                }
            }
        }

        return $this->getUrl($path);
    }
}
