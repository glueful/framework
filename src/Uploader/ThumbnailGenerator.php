<?php

declare(strict_types=1);

namespace Glueful\Uploader;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Services\ImageProcessor;
use Glueful\Uploader\Storage\StorageInterface;

/**
 * Generates thumbnails for uploaded images
 *
 * Uses ImageProcessor (Intervention Image) for high-quality thumbnail generation
 * with support for various image formats and smart cropping.
 *
 * Configuration (config/filesystem.php):
 * - filesystem.uploader.thumbnail_width: Default width (default: 400)
 * - filesystem.uploader.thumbnail_height: Default height (default: 400)
 * - filesystem.uploader.thumbnail_quality: JPEG quality 1-100 (default: 80)
 * - filesystem.uploader.thumbnail_formats: Array of supported MIME types
 */
final class ThumbnailGenerator
{
    private const DEFAULT_WIDTH = 400;
    private const DEFAULT_HEIGHT = 400;
    private const DEFAULT_QUALITY = 80;

    /**
     * Default formats that support thumbnail generation
     */
    private const DEFAULT_THUMBNAIL_FORMATS = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    public function __construct(
        private readonly StorageInterface $storage,
        private readonly ?ApplicationContext $context = null
    ) {
    }

    /**
     * Generate a thumbnail for an image file
     *
     * @param string $sourcePath Path to source image
     * @param string $storagePath Storage directory path
     * @param string $originalFilename Original filename for extension detection
     * @param array<string, mixed> $options Thumbnail options:
     *   - width: int (default: 400)
     *   - height: int (default: 400)
     *   - quality: int (default: 80)
     *   - subdirectory: string (default: 'thumbs')
     * @return string|null Thumbnail URL or null on failure
     */
    public function generate(
        string $sourcePath,
        string $storagePath,
        string $originalFilename,
        array $options = []
    ): ?string {
        $widthConfigKey = 'filesystem.uploader.thumbnail_width';
        $heightConfigKey = 'filesystem.uploader.thumbnail_height';
        $qualityConfigKey = 'filesystem.uploader.thumbnail_quality';

        $width = $this->getOption($options, 'width', $widthConfigKey, self::DEFAULT_WIDTH);
        $height = $this->getOption($options, 'height', $heightConfigKey, self::DEFAULT_HEIGHT);
        $quality = $this->getOption($options, 'quality', $qualityConfigKey, self::DEFAULT_QUALITY);
        $subdirectory = (string) ($options['subdirectory']
            ?? $this->getConfig('filesystem.uploader.thumbnail_subdirectory', 'thumbs'));

        try {
            $thumbFilename = $this->generateFilename($originalFilename);
            $thumbPath = $this->buildPath($storagePath, $subdirectory, $thumbFilename);

            $thumbData = $this->createThumbnail($sourcePath, $width, $height, $quality);
            if ($thumbData === null) {
                return null;
            }

            $this->storage->storeContent($thumbData, $thumbPath);

            return $this->storage->getUrl($thumbPath);
        } catch (\Exception $e) {
            error_log('Thumbnail generation failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Check if thumbnail generation is supported for the given MIME type
     */
    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, $this->getSupportedFormats(), true);
    }

    /**
     * Get list of supported formats for thumbnail generation
     *
     * @return array<string>
     */
    public function getSupportedFormats(): array
    {
        $configured = $this->getConfig('filesystem.uploader.thumbnail_formats');

        if (is_array($configured) && $configured !== []) {
            return $configured;
        }

        return self::DEFAULT_THUMBNAIL_FORMATS;
    }

    /**
     * Create thumbnail image data
     */
    private function createThumbnail(string $sourcePath, int $width, int $height, int $quality): ?string
    {
        if (!class_exists(ImageProcessor::class)) {
            error_log('ThumbnailGenerator requires ImageProcessor (Intervention Image)');
            return null;
        }

        $processor = ImageProcessor::make($sourcePath, $this->context);

        return $processor
            ->fit($width, $height)
            ->quality($quality)
            ->getImageData();
    }

    /**
     * Generate unique thumbnail filename
     */
    private function generateFilename(string $originalFilename): string
    {
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

        return sprintf(
            '%s_%s_thumb.%s',
            time(),
            bin2hex(random_bytes(8)),
            $extension ?: 'jpg'
        );
    }

    /**
     * Build storage path for thumbnail
     */
    private function buildPath(string $storagePath, string $subdirectory, string $filename): string
    {
        $parts = array_filter([
            rtrim($storagePath, '/'),
            $subdirectory,
            $filename,
        ], fn($part) => $part !== '');

        return implode('/', $parts);
    }

    /**
     * Get option value with config fallback
     *
     * @param array<string, mixed> $options
     */
    private function getOption(array $options, string $key, string $configKey, int $default): int
    {
        if (isset($options[$key])) {
            return (int) $options[$key];
        }

        $configValue = $this->getConfig($configKey);
        if ($configValue !== null) {
            return (int) $configValue;
        }

        return $default;
    }

    private function getConfig(string $key, mixed $default = null): mixed
    {
        if ($this->context === null) {
            return $default;
        }

        return config($this->context, $key, $default);
    }
}
