<?php

declare(strict_types=1);

namespace Glueful\Tests\Unit\Uploader\Contracts;

use Glueful\Uploader\Contracts\MediaProcessorInterface;
use Glueful\Uploader\MediaMetadata;
use Glueful\Uploader\Storage\StorageInterface;
use PHPUnit\Framework\TestCase;

final class MediaProcessorInterfaceTest extends TestCase
{
    public function testInterfaceExists(): void
    {
        $this->assertTrue(
            interface_exists(MediaProcessorInterface::class),
            'MediaProcessorInterface should exist in Glueful\\Uploader\\Contracts'
        );
    }

    public function testExtractMetadataReturnsMediaMetadata(): void
    {
        $processor = $this->makeFakeProcessor();

        $metadata = $processor->extractMetadata('/tmp/example.jpg', 'image/jpeg');

        $this->assertInstanceOf(MediaMetadata::class, $metadata);
        $this->assertSame('image', $metadata->type);
    }

    public function testGenerateThumbnailReturnsNullableString(): void
    {
        $processor = $this->makeFakeProcessor();
        $storage = $this->makeStubStorage();

        $thumb = $processor->generateThumbnail(
            $storage,
            '/tmp/example.jpg',
            'uploads/example.jpg',
            'example.jpg'
        );

        $this->assertIsString($thumb);
        $this->assertSame('thumbnails/example.jpg', $thumb);
    }

    public function testSupportsThumbnailReturnsBool(): void
    {
        $processor = $this->makeFakeProcessor();

        $this->assertIsBool($processor->supportsThumbnail('image/jpeg'));
        $this->assertTrue($processor->supportsThumbnail('image/jpeg'));
        $this->assertFalse($processor->supportsThumbnail('text/plain'));
    }

    public function testRenderVariantReturnsDataAndMimeKeys(): void
    {
        $processor = $this->makeFakeProcessor();

        $variant = $processor->renderVariant('/tmp/example.jpg', []);

        $this->assertIsArray($variant);
        $this->assertArrayHasKey('data', $variant);
        $this->assertArrayHasKey('mime', $variant);
        $this->assertIsString($variant['data']);
        $this->assertIsString($variant['mime']);
    }

    private function makeFakeProcessor(): MediaProcessorInterface
    {
        return new class implements MediaProcessorInterface {
            public function extractMetadata(string $filepath, string $mimeType): MediaMetadata
            {
                $type = match (true) {
                    str_starts_with($mimeType, 'image/') => 'image',
                    str_starts_with($mimeType, 'video/') => 'video',
                    str_starts_with($mimeType, 'audio/') => 'audio',
                    default => 'file',
                };

                return new MediaMetadata($type, 800, 600);
            }

            public function generateThumbnail(
                StorageInterface $storage,
                string $sourcePath,
                string $storagePath,
                string $originalFilename,
                array $options = []
            ): ?string {
                // Honour the passed-in storage; never construct our own.
                $storage->storeContent('fake-thumb-bytes', 'thumbnails/' . $originalFilename);

                return 'thumbnails/' . $originalFilename;
            }

            public function supportsThumbnail(string $mimeType): bool
            {
                return str_starts_with($mimeType, 'image/');
            }

            /**
             * @return array{data: string, mime: string}
             */
            public function renderVariant(string $sourcePath, array $options): array
            {
                return ['data' => 'fake-bytes', 'mime' => 'image/jpeg'];
            }
        };
    }

    private function makeStubStorage(): StorageInterface
    {
        return new class implements StorageInterface {
            public function store(string $sourcePath, string $destinationPath): string
            {
                return $destinationPath;
            }

            public function storeContent(string $content, string $destinationPath): string
            {
                return $destinationPath;
            }

            public function getUrl(string $path): string
            {
                return 'https://example.test/' . $path;
            }

            public function exists(string $path): bool
            {
                return false;
            }

            public function delete(string $path): bool
            {
                return true;
            }

            public function getSignedUrl(string $path, int $expiry = 3600): string
            {
                return 'https://example.test/' . $path . '?sig=stub';
            }
        };
    }
}
