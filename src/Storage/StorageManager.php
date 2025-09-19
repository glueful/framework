<?php

declare(strict_types=1);

namespace Glueful\Storage;

use League\Flysystem\FilesystemOperator;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnixVisibility\PortableVisibilityConverter;
use League\Flysystem\FilesystemException;
use Glueful\Storage\Exceptions\StorageException;

class StorageManager
{
    /** @var array<string, FilesystemOperator> */
    private array $disks = [];
    private PathGuard $pathGuard;
    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config, PathGuard $pathGuard)
    {
        $this->config = $config;
        $this->pathGuard = $pathGuard;
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

        return match ($config['driver']) {
            'local' => new Filesystem(new LocalFilesystemAdapter(
                $config['root'],
                PortableVisibilityConverter::fromArray([
                    'file' => [
                        'public' => 0644,
                        'private' => 0600,
                    ],
                    'dir' => [
                        'public' => 0755,
                        'private' => 0700,
                    ],
                ], (string)($config['visibility'] ?? 'private'))
            )),
            'memory' => new Filesystem(new \League\Flysystem\InMemory\InMemoryFilesystemAdapter()),
            's3' => $this->createS3Filesystem($config),
            'azure' => $this->createAzureFilesystem($config),
            'gcs' => $this->createGcsFilesystem($config),
            default => throw new \InvalidArgumentException("Unsupported disk driver: {$config['driver']}")
        };
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

        // Report drivers available based on installed adapters
        $driver = $this->config['disks'][$name]['driver'];

        return match ($driver) {
            'local', 'memory' => true,
            's3' => class_exists('League\\Flysystem\\AwsS3V3\\AwsS3V3Adapter')
                && class_exists('Aws\\S3\\S3Client'),
            'azure' => class_exists('League\\Flysystem\\AzureBlobStorage\\AzureBlobStorageAdapter')
                && (
                    class_exists('MicrosoftAzure\\Storage\\Blob\\BlobRestProxy')
                    || class_exists('Azure\\Storage\\Blob\\BlobRestProxy')
                ),
            'gcs' => class_exists('League\\Flysystem\\GoogleCloudStorage\\GoogleCloudStorageAdapter')
                && class_exists('Google\\Cloud\\Storage\\StorageClient'),
            default => false,
        };
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
     * Atomic file write for large files
     * @param resource $stream
     */
    public function putStream(string $path, $stream, ?string $disk = null): void
    {
        $path = $this->pathGuard->validate($path);
        $temp = $this->generateTempPath($path);

        try {
            try {
                $this->disk($disk)->writeStream($temp, $stream);
                $this->disk($disk)->move($temp, $path);
            } catch (FilesystemException $e) {
                throw StorageException::fromFlysystem($e, $path);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            // Clean up temp file if it still exists
            try {
                $this->disk($disk)->delete($temp);
            } catch (\Exception) {
                // Ignore - temp file may have been moved
            }
        }
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

    /**
     * @param array<string,mixed> $config
     */
    private function createS3Filesystem(array $config): FilesystemOperator
    {
        if (
            !class_exists('League\\Flysystem\\AwsS3V3\\AwsS3V3Adapter')
            || !class_exists('Aws\\S3\\S3Client')
        ) {
            throw new \InvalidArgumentException(
                'S3 adapter not installed. Run: composer require ' .
                'league/flysystem-aws-s3-v3'
            );
        }

        foreach (['bucket', 'region'] as $key) {
            if (!isset($config[$key]) || $config[$key] === '') {
                throw new \InvalidArgumentException("Missing required S3 config: '{$key}'");
            }
        }

        $clientConfig = ['version' => 'latest', 'region' => (string)$config['region']];
        if (isset($config['endpoint']) && $config['endpoint'] !== '') {
            $clientConfig['endpoint'] = (string)$config['endpoint'];
        }
        if (
            isset($config['key'], $config['secret'])
            && $config['key'] !== ''
            && $config['secret'] !== ''
        ) {
            $clientConfig['credentials'] = [
                'key' => (string)$config['key'],
                'secret' => (string)$config['secret'],
            ];
        }
        if (isset($config['use_path_style_endpoint'])) {
            $clientConfig['use_path_style_endpoint'] = (bool)$config['use_path_style_endpoint'];
        }

        $clientClass = 'Aws\\S3\\S3Client';
        $adapterClass = 'League\\Flysystem\\AwsS3V3\\AwsS3V3Adapter';
        $client = new $clientClass($clientConfig);
        $adapter = new $adapterClass($client, (string)$config['bucket'], (string)($config['prefix'] ?? ''));
        assert($adapter instanceof \League\Flysystem\FilesystemAdapter);

        return new Filesystem($adapter);
    }

    /**
     * @param array<string,mixed> $config
     */
    private function createAzureFilesystem(array $config): FilesystemOperator
    {
        if (!class_exists('League\\Flysystem\\AzureBlobStorage\\AzureBlobStorageAdapter')) {
            throw new \InvalidArgumentException(
                'Azure adapter not installed. Run: composer require ' .
                'league/flysystem-azure-blob-storage'
            );
        }

        foreach (['container'] as $key) {
            if (!isset($config[$key]) || $config[$key] === '') {
                throw new \InvalidArgumentException("Missing required Azure config: '{$key}'");
            }
        }

        // Prefer connection string if provided
        if (
            isset($config['connection_string'])
            && $config['connection_string'] !== ''
            && class_exists('MicrosoftAzure\\Storage\\Blob\\BlobRestProxy')
        ) {
            $proxyClass = 'MicrosoftAzure\\Storage\\Blob\\BlobRestProxy';
            $client = $proxyClass::createBlobService((string)$config['connection_string']);
            $adapterClass = 'League\\Flysystem\\AzureBlobStorage\\AzureBlobStorageAdapter';
            $adapter = new $adapterClass($client, (string)$config['container'], (string)($config['prefix'] ?? ''));
            assert($adapter instanceof \League\Flysystem\FilesystemAdapter);
            return new Filesystem($adapter);
        }

        // Fallback: require user to supply a prebuilt adapter
        if (isset($config['adapter']) && $config['adapter'] instanceof \League\Flysystem\FilesystemAdapter) {
            return new Filesystem($config['adapter']);
        }

        throw new \InvalidArgumentException(
            "Unable to create Azure filesystem. Provide 'connection_string' or a prebuilt 'adapter'."
        );
    }

    /**
     * @param array<string,mixed> $config
     */
    private function createGcsFilesystem(array $config): FilesystemOperator
    {
        if (
            !class_exists('League\\Flysystem\\GoogleCloudStorage\\GoogleCloudStorageAdapter')
            || !class_exists('Google\\Cloud\\Storage\\StorageClient')
        ) {
            throw new \InvalidArgumentException(
                'GCS adapter not installed. Run: composer require ' .
                'league/flysystem-google-cloud-storage'
            );
        }

        foreach (['bucket'] as $key) {
            if (!isset($config[$key]) || $config[$key] === '') {
                throw new \InvalidArgumentException("Missing required GCS config: '{$key}'");
            }
        }

        $clientConfig = [];
        if (isset($config['key_file']) && $config['key_file'] !== '') {
            $clientConfig['keyFilePath'] = (string)$config['key_file'];
        }
        if (isset($config['project_id']) && $config['project_id'] !== '') {
            $clientConfig['projectId'] = (string)$config['project_id'];
        }

        $clientClass = 'Google\\Cloud\\Storage\\StorageClient';
        $client = new $clientClass($clientConfig);

        // Try common constructor signatures across adapter versions
        $prefix = (string)($config['prefix'] ?? '');
        $adapter = null;
        try {
            // Signature: (StorageClient $client, string $bucket, string $prefix = '')
            $adapterClass = 'League\\Flysystem\\GoogleCloudStorage\\GoogleCloudStorageAdapter';
            $adapter = new $adapterClass($client, (string)$config['bucket'], $prefix);
        } catch (\Throwable) {
            // Signature: (Bucket $bucket, string $prefix = '')
            $bucket = $client->bucket((string)$config['bucket']);
            $adapterClass = 'League\\Flysystem\\GoogleCloudStorage\\GoogleCloudStorageAdapter';
            $adapter = new $adapterClass($bucket, $prefix);
        }
        assert($adapter instanceof \League\Flysystem\FilesystemAdapter);

        return new Filesystem($adapter);
    }
}
