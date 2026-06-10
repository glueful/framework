<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Controllers;

use Glueful\Controllers\UploadController;
use Glueful\Storage\Contracts\NativeSignedUrlProviderInterface;
use Glueful\Storage\Contracts\StorageDriverFactoryInterface;
use Glueful\Storage\PathGuard;
use Glueful\Storage\StorageDriverRegistry;
use Glueful\Storage\Support\UrlGenerator;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\TestCase;

final class UploadControllerNativeUrlTest extends TestCase
{
    public function testDisabledByDefaultReturnsNull(): void
    {
        $this->assertNull(UploadController::nativeUrlFor(
            policy: ['disks' => [], 'max_private_ttl' => 900],
            disk: 'media',
            visibility: 'public',
            signer: fn(int $ttl): ?string => "https://bucket/x?ttl={$ttl}",
            defaultTtl: 300
        ));
    }

    public function testPublicBlobReturnsNativeWhenEnabled(): void
    {
        $url = UploadController::nativeUrlFor(
            policy: ['disks' => ['media' => ['enabled' => true, 'public' => true]], 'max_private_ttl' => 900],
            disk: 'media',
            visibility: 'public',
            signer: fn(int $ttl): ?string => "https://bucket/x?ttl={$ttl}",
            defaultTtl: 300
        );

        $this->assertSame('https://bucket/x?ttl=300', $url);
    }

    public function testPrivateBlobRequiresExplicitOptInAndBoundsTtl(): void
    {
        $this->assertNull(UploadController::nativeUrlFor(
            policy: [
                'disks' => ['media' => ['enabled' => true, 'public' => true, 'private' => false]],
                'max_private_ttl' => 900,
            ],
            disk: 'media',
            visibility: 'private',
            signer: fn(int $ttl): ?string => "https://bucket/x?ttl={$ttl}",
            defaultTtl: 100000
        ));

        $url = UploadController::nativeUrlFor(
            policy: [
                'disks' => ['media' => ['enabled' => true, 'private' => true, 'private_ttl' => 100000]],
                'max_private_ttl' => 900,
            ],
            disk: 'media',
            visibility: 'private',
            signer: fn(int $ttl): ?string => "https://bucket/x?ttl={$ttl}",
            defaultTtl: 300
        );

        $this->assertSame('https://bucket/x?ttl=900', $url);
    }

    public function testSignerReturningNullFallsBackToNull(): void
    {
        $this->assertNull(UploadController::nativeUrlFor(
            policy: ['disks' => ['media' => ['enabled' => true, 'public' => true]], 'max_private_ttl' => 900],
            disk: 'media',
            visibility: 'public',
            signer: fn(int $ttl): ?string => null,
            defaultTtl: 300
        ));
    }

    public function testNativeSignerReceivesRawStoredPathNotPrefixedUrl(): void
    {
        /** @var \ArrayObject<int, string> $recorded */
        $recorded = new \ArrayObject([]);
        $registry = StorageDriverRegistry::withBuiltIns();
        $registry->register('recording', $this->recordingFactory($recorded));

        $diskConfig = [
            'driver' => 'recording',
            'base_url' => 'https://cdn.example.com',
        ];
        $urls = new UrlGenerator([
            'default' => 'media',
            'disks' => ['media' => $diskConfig],
        ], new PathGuard());

        $blob = ['url' => 'docs/report.pdf', 'visibility' => 'public', 'storage_type' => 'media'];
        $rawPath = (string) ($blob['url'] ?? '');
        $blob['url'] = $urls->url($rawPath, 'media');

        $this->assertSame('https://cdn.example.com/docs/report.pdf', $blob['url']);

        $native = UploadController::nativeUrlViaRegistry(
            registry: $registry,
            policy: ['disks' => ['media' => ['enabled' => true, 'public' => true]], 'max_private_ttl' => 900],
            disk: 'media',
            diskConfig: $diskConfig,
            visibility: 'public',
            rawPath: $rawPath,
            defaultTtl: 300
        );

        $this->assertSame('https://signed.example/docs/report.pdf?ttl=300', $native);
        $this->assertCount(1, $recorded);
        $this->assertSame('docs/report.pdf', $recorded[0]);
        $this->assertStringNotContainsString('https://cdn.example.com', (string) $recorded[0]);
    }

    public function testNativeUrlViaRegistryReturnsNullForNonSigningFactory(): void
    {
        $this->assertNull(UploadController::nativeUrlViaRegistry(
            registry: StorageDriverRegistry::withBuiltIns(),
            policy: ['disks' => ['media' => ['enabled' => true, 'public' => true]], 'max_private_ttl' => 900],
            disk: 'media',
            diskConfig: ['driver' => 'local', 'root' => sys_get_temp_dir()],
            visibility: 'public',
            rawPath: 'docs/report.pdf',
            defaultTtl: 300
        ));
    }

    public function testNativeUrlViaRegistryReturnsNullWhenSignerThrows(): void
    {
        $registry = StorageDriverRegistry::withBuiltIns();
        $registry->register('throwing', new class implements StorageDriverFactoryInterface, NativeSignedUrlProviderInterface {
            public function driver(): string
            {
                return 'throwing';
            }

            public function create(array $config): FilesystemOperator
            {
                return new Filesystem(new InMemoryFilesystemAdapter());
            }

            public function available(array $config): bool
            {
                return true;
            }

            public function features(array $config): array
            {
                return ['supports_native_signed_urls' => true];
            }

            public function temporaryUrl(string $path, int $ttl, array $diskConfig): ?string
            {
                throw new \RuntimeException('provider unavailable');
            }
        });

        $this->assertNull(UploadController::nativeUrlViaRegistry(
            registry: $registry,
            policy: ['disks' => ['media' => ['enabled' => true, 'public' => true]], 'max_private_ttl' => 900],
            disk: 'media',
            diskConfig: ['driver' => 'throwing'],
            visibility: 'public',
            rawPath: 'docs/report.pdf',
            defaultTtl: 300
        ));
    }

    /**
     * @param \ArrayObject<int, string> $recorded
     */
    private function recordingFactory(\ArrayObject $recorded): StorageDriverFactoryInterface
    {
        return new class ($recorded) implements StorageDriverFactoryInterface, NativeSignedUrlProviderInterface {
            /** @param \ArrayObject<int, string> $recorded */
            public function __construct(private \ArrayObject $recorded)
            {
            }

            public function driver(): string
            {
                return 'recording';
            }

            public function create(array $config): FilesystemOperator
            {
                return new Filesystem(new InMemoryFilesystemAdapter());
            }

            public function available(array $config): bool
            {
                return true;
            }

            public function features(array $config): array
            {
                return ['supports_native_signed_urls' => true];
            }

            public function temporaryUrl(string $path, int $ttl, array $diskConfig): ?string
            {
                $this->recorded->append($path);

                return "https://signed.example/{$path}?ttl={$ttl}";
            }
        };
    }
}
