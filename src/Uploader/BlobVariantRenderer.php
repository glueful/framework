<?php

declare(strict_types=1);

namespace Glueful\Uploader;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Exceptions\Domain\BusinessLogicException;
use Glueful\Services\ImageSecurityValidator;
use Glueful\Storage\StorageManager;
use Glueful\Uploader\Contracts\MediaProcessorInterface;

use function config;

/**
 * Renders one resized copy (variant) of a stored image through the media processor: the source is
 * checked before it is decoded, the size is clamped to `uploads.image_processing.max_width` and
 * `max_height`, the quality defaults to `default_quality`, and a result over `max_variant_bytes` is
 * refused. Shared by the blob route, which renders a copy on request, and GenerateBlobVariantsJob,
 * which renders the configured copies when an image is uploaded.
 */
final class BlobVariantRenderer
{
    /**
     * @param array{max_width?: int, max_height?: int, max_pixels?: int, default_quality?: int,
     *     max_variant_bytes?: int} $limits
     */
    public function __construct(
        private readonly StorageManager $storage,
        private readonly MediaProcessorInterface $media,
        private readonly ?ImageSecurityValidator $imageSecurity,
        private readonly string $defaultDisk,
        private readonly array $limits = [],
    ) {
    }

    /** Null when no media processor is bound: nothing can be rendered. */
    public static function fromContext(
        ApplicationContext $context,
        ?MediaProcessorInterface $media = null,
        ?ImageSecurityValidator $imageSecurity = null,
    ): ?self {
        $container = $context->getContainer();
        if ($media === null && $container->has(MediaProcessorInterface::class)) {
            $media = $container->get(MediaProcessorInterface::class);
        }
        if (!$media instanceof MediaProcessorInterface) {
            return null;
        }
        if ($imageSecurity === null && $container->has(ImageSecurityValidator::class)) {
            $imageSecurity = $container->get(ImageSecurityValidator::class);
        }

        return new self(
            $container->get(StorageManager::class),
            $media,
            $imageSecurity instanceof ImageSecurityValidator ? $imageSecurity : null,
            (string) config($context, 'uploads.disk', 'uploads'),
            [
                'max_width' => (int) config($context, 'uploads.image_processing.max_width', 2048),
                'max_height' => (int) config($context, 'uploads.image_processing.max_height', 2048),
                'max_pixels' => (int) config($context, 'uploads.image_processing.max_pixels', 25000000),
                'default_quality' => (int) config($context, 'uploads.image_processing.default_quality', 85),
                'max_variant_bytes' => (int) config(
                    $context,
                    'uploads.image_processing.max_variant_bytes',
                    5 * 1024 * 1024,
                ),
            ],
        );
    }

    /** The raster format of a MIME type (`jpg`, `png`, `gif`, `webp`), or null for any other. */
    public static function formatFromMime(string $mime): ?string
    {
        return match (strtolower(trim(explode(';', $mime, 2)[0]))) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => null,
        };
    }

    /**
     * The type a copy is asked for in: the requested format's, else the source's. A copy is stored
     * under this type's extension, so it is only kept when the processor answers in it.
     *
     * @param array<string, mixed> $resize
     */
    public static function expectedMime(string $sourceMime, array $resize): string
    {
        $format = $resize['format'] ?? null;
        $format = is_string($format) && $format !== ''
            ? strtolower($format)
            : self::formatFromMime($sourceMime);

        return match ($format) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };
    }

    /**
     * @param array<string, mixed> $blob
     * @param array<string, mixed> $resize
     * @return array{data: string, mime: string}
     * @throws BusinessLogicException when the source may not be decoded or the copy is too large
     */
    public function render(array $blob, array $resize): array
    {
        $path = (string) ($blob['url'] ?? '');
        if ($path === '') {
            throw new \RuntimeException('Blob file not found');
        }
        $disk = (string) ($blob['storage_type'] ?? '');
        $temp = $this->readToTempFile($disk !== '' ? $disk : $this->defaultDisk, $path);
        try {
            $this->validateSource($temp, (string) ($blob['mime_type'] ?? ''), $resize);
            $rendered = $this->media->renderVariant($temp, $this->options($resize));
            $max = (int) ($this->limits['max_variant_bytes'] ?? 5 * 1024 * 1024);
            if ($max > 0 && strlen($rendered['data']) > $max) {
                throw new BusinessLogicException('Resized image exceeds maximum allowed size');
            }

            return $rendered;
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    /**
     * Check the source before decoding it. The requested size is clamped by options(), so a
     * resize of a large source only has to be safe to decode; with no target size the copy keeps
     * the source's dimensions, so the source itself must fit the limits.
     *
     * @param array<string, mixed> $resize
     */
    private function validateSource(string $path, string $sourceMime, array $resize): void
    {
        $format = self::formatFromMime($sourceMime);
        if (($resize['width'] ?? null) === null && ($resize['height'] ?? null) === null) {
            $this->imageSecurity?->validateImageFile($path, $format);
            return;
        }
        $this->imageSecurity?->validateResizeSource(
            $path,
            (int) ($this->limits['max_pixels'] ?? 25000000),
            $format,
        );
    }

    /**
     * The option bag MediaProcessorInterface::renderVariant() takes: the requested settings with
     * width and height clamped and the default quality filled in.
     *
     * @param array<string, mixed> $resize
     * @return array<string, int|string|null>
     */
    private function options(array $resize): array
    {
        $maxWidth = (int) ($this->limits['max_width'] ?? 2048);
        $maxHeight = (int) ($this->limits['max_height'] ?? 2048);
        $width = $resize['width'] ?? null;
        $height = $resize['height'] ?? null;
        if (is_int($width) && $width > $maxWidth) {
            $width = $maxWidth;
        }
        if (is_int($height) && $height > $maxHeight) {
            $height = $maxHeight;
        }
        $quality = $resize['quality'] ?? null;

        return [
            'width' => is_int($width) ? $width : null,
            'height' => is_int($height) ? $height : null,
            'quality' => is_int($quality) ? $quality : (int) ($this->limits['default_quality'] ?? 85),
            'format' => is_string($resize['format'] ?? null) ? $resize['format'] : null,
            'fit' => is_string($resize['fit'] ?? null) ? $resize['fit'] : null,
        ];
    }

    private function readToTempFile(string $disk, string $path): string
    {
        $stream = $this->storage->disk($disk)->readStream($path);
        if (!is_resource($stream)) {
            throw new \RuntimeException('File not found');
        }
        $temp = tempnam(sys_get_temp_dir(), 'blob_');
        if ($temp === false) {
            throw new \RuntimeException('Failed to create temp file');
        }
        $out = fopen($temp, 'w');
        if ($out === false) {
            throw new \RuntimeException('Failed to write temp file');
        }
        stream_copy_to_stream($stream, $out);
        fclose($out);
        fclose($stream);

        return $temp;
    }
}
