<?php

namespace Glueful\Uploader\Storage;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Glueful\Uploader\UploadException;

class S3Storage implements StorageInterface
{
    private S3Client $client;
    private string $bucket;
    private ?string $acl;
    private bool $signedUrls;
    private int $signedTtl;

    public function __construct()
    {
        $config = [
            'version' => 'latest',
            'region'  => config('services.storage.s3.region'),
            'credentials' => [
                'key'    => config('services.storage.s3.key'),
                'secret' => config('services.storage.s3.secret'),
            ]
        ];

        $endpoint = config('services.storage.s3.endpoint');
        if ($endpoint !== null && $endpoint !== false && $endpoint !== '') {
            $config['endpoint'] = $endpoint;
            $config['use_path_style_endpoint'] = true;
        }

        $this->bucket = (string) config('services.storage.s3.bucket');
        $this->acl = config('services.storage.s3.acl', 'private');
        $this->signedUrls = (bool) config('services.storage.s3.signed_urls', true);
        $this->signedTtl = (int) config('services.storage.s3.signed_ttl', 3600);

        $this->client = new S3Client($config);
    }

    public function store(string $sourcePath, string $destinationPath): string
    {
        try {
            $params = [
                'Bucket' => $this->bucket,
                'Key'    => $destinationPath,
                'SourceFile' => $sourcePath,
            ];
            // Apply ACL if configured (default: private)
            $acl = is_string($this->acl) ? strtolower($this->acl) : '';
            if ($acl !== '') {
                $params['ACL'] = $acl;
            }

            $this->client->putObject($params);
            return $destinationPath;
        } catch (AwsException $e) {
            throw new UploadException('S3 upload failed: ' . $e->getMessage());
        }
    }

    public function getUrl(string $path): string
    {
        // Prefer signed URLs when enabled or when ACL is not public
        $acl = is_string($this->acl) ? strtolower($this->acl) : '';
        $isPublic = in_array($acl, ['public-read', 'public'], true);
        if ($this->signedUrls || !$isPublic) {
            return $this->getSignedUrl($path, $this->signedTtl);
        }
        return $this->client->getObjectUrl($this->bucket, $path);
    }

    public function exists(string $path): bool
    {
        return $this->client->doesObjectExistV2($this->bucket, $path);
    }

    public function delete(string $path): bool
    {
        try {
            $this->client->deleteObject([
                'Bucket' => $this->bucket,
                'Key'    => $path,
            ]);
            return true;
        } catch (AwsException $e) {
            return false;
        }
    }

    public function getSignedUrl(string $path, int $expiry = 3600): string
    {
        $ttl = $expiry > 0 ? $expiry : $this->signedTtl;
        $command = $this->client->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key'    => $path,
        ]);

        $request = $this->client->createPresignedRequest($command, "+{$ttl} seconds");
        return (string) $request->getUri();
    }
}
