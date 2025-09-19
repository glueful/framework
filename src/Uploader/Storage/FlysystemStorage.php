<?php

declare(strict_types=1);

namespace Glueful\Uploader\Storage;

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
        // Adapter-specific support: S3 presigned URL via AWS SDK when configured
        $cfg = $this->urls->diskConfig($this->disk);
        if (
            ($cfg['driver'] ?? null) === 's3'
            && class_exists('Aws\\S3\\S3Client')
        ) {
            try {
                $clientCfg = ['version' => 'latest', 'region' => (string)($cfg['region'] ?? 'us-east-1')];
                if (isset($cfg['endpoint']) && $cfg['endpoint'] !== '') {
                    $clientCfg['endpoint'] = (string)$cfg['endpoint'];
                }
                if (isset($cfg['key'], $cfg['secret']) && $cfg['key'] !== '' && $cfg['secret'] !== '') {
                    $clientCfg['credentials'] = ['key' => (string)$cfg['key'], 'secret' => (string)$cfg['secret']];
                }
                if (isset($cfg['use_path_style_endpoint'])) {
                    $clientCfg['use_path_style_endpoint'] = (bool)$cfg['use_path_style_endpoint'];
                }

                $bucket = (string)($cfg['bucket'] ?? '');
                if ($bucket === '') {
                    return $this->getUrl($path);
                }

                $clientClass = 'Aws\\S3\\S3Client';
                /** @var object $client */
                $client = new $clientClass($clientCfg);
                $ttl = $expiry > 0 ? $expiry : (int)($cfg['signed_ttl'] ?? 3600);
                $command = $client->getCommand('GetObject', ['Bucket' => $bucket, 'Key' => $path]);
                $request = $client->createPresignedRequest($command, "+{$ttl} seconds");
                return (string) $request->getUri();
            } catch (\Throwable) {
                // Fall back to plain URL on any failure
                return $this->getUrl($path);
            }
        }

        return $this->getUrl($path);
    }
}
