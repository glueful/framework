<?php

declare(strict_types=1);

namespace Glueful\Services;

use Glueful\Bootstrap\ApplicationContext;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Encoders\AutoEncoder;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\GifEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Glueful\Http\Exceptions\Domain\BusinessLogicException;
use Glueful\Cache\CacheStore;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;

/**
 * Image Processor
 *
 * Modern image processing implementation using Intervention Image v3.
 * Provides fluent API for image transformations, caching, and output.
 */
class ImageProcessor implements ImageProcessorInterface
{
    private static ?ApplicationContext $defaultContext = null;

    private ImageManager $manager;
    private ImageInterface $image;
    /** @var CacheStore<mixed> */
    private CacheStore $cache;
    private ImageSecurityValidator $security;
    private LoggerInterface $logger;
    /** @var array<string, mixed> */
    private array $config;
    /** @var array<int, array<string, mixed>> */
    private array $operations = [];
    private ?string $cacheKey = null;

    /**
     * @param CacheStore<mixed> $cache
     * @param array<string, mixed> $config
     */
    public function __construct(
        ImageManager $manager,
        CacheStore $cache,
        ImageSecurityValidator $security,
        LoggerInterface $logger,
        array $config = []
    ) {
        $this->manager = $manager;
        $this->cache = $cache;
        $this->security = $security;
        $this->logger = $logger;
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    /**
     * Static Factory Methods
     */

    public static function setContext(?ApplicationContext $context): void
    {
        self::$defaultContext = $context;
    }

    public static function make(string $source, ?ApplicationContext $context = null): self
    {
        $instance = app(self::resolveContext($context), self::class);

        try {
            // Validate source security
            if (filter_var($source, FILTER_VALIDATE_URL)) {
                $instance->security->validateUrl($source);
            }

            $instance->image = $instance->manager->read($source);
            $instance->validateImage();

            $instance->logger->debug('Image loaded successfully', [
                'source' => $source,
                'width' => $instance->image->width(),
                'height' => $instance->image->height()
            ]);

            return $instance;
        } catch (\Exception $e) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'Failed to load image: ' . $e->getMessage()
            );
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromUrl(
        string $url,
        array $options = [],
        ?ApplicationContext $context = null
    ): self {
        $instance = app(self::resolveContext($context), self::class);
        $instance->security->validateUrl($url);

        try {
            // Setup HTTP context for remote images
            $context = stream_context_create([
                'http' => array_merge([
                    'timeout' => $instance->config['security']['timeout'] ?? 10,
                    'user_agent' => $instance->config['security']['user_agent'] ?? 'Glueful-ImageProcessor/1.0',
                    'follow_location' => true,
                    'max_redirects' => 3,
                ], $options)
            ]);

            $instance->image = $instance->manager->read($url, context: $context);
            $instance->validateImage();

            $instance->logger->info('Remote image loaded', [
                'url' => $url,
                'width' => $instance->image->width(),
                'height' => $instance->image->height()
            ]);

            return $instance;
        } catch (\Exception $e) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'Failed to load remote image: ' . $e->getMessage()
            );
        }
    }

    public static function fromUpload(UploadedFileInterface $file, ?ApplicationContext $context = null): self
    {
        $instance = app(self::resolveContext($context), self::class);

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'File upload error: ' . $file->getError()
            );
        }

        // Validate file size
        $instance->security->validateFileSize($file->getSize());

        // Validate format
        $filename = $file->getClientFilename() ?? 'upload';
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $instance->security->validateFormat($extension, $file->getClientMediaType());

        try {
            $instance->image = $instance->manager->read($file->getStream()->getContents());
            $instance->validateImage();

            return $instance;
        } catch (\Exception $e) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'Failed to process uploaded file: ' . $e->getMessage()
            );
        }
    }

    public static function create(
        int $width,
        int $height,
        string $background = 'ffffff',
        ?ApplicationContext $context = null
    ): self {
        $instance = app(self::resolveContext($context), self::class);

        // Validate dimensions
        $instance->security->validateDimensions($width, $height);

        try {
            $instance->image = $instance->manager->create($width, $height, $background);

            $instance->logger->debug('Blank canvas created', [
                'width' => $width,
                'height' => $height,
                'background' => $background
            ]);

            return $instance;
        } catch (\Exception $e) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'Failed to create canvas: ' . $e->getMessage()
            );
        }
    }

    /**
     * Transformation Operations
     */

    public function resize(?int $width = null, ?int $height = null, bool $maintainAspect = true): self
    {
        if ($width !== null && $width > 0) {
            $this->security->validateDimensions($width, $height ?? $width);
        }
        if ($height !== null && $height > 0) {
            $this->security->validateDimensions($width ?? $height, $height);
        }

        $this->operations[] = ['resize', compact('width', 'height', 'maintainAspect')];

        if ($maintainAspect) {
            $this->image = $this->image->scale($width, $height);
        } else {
            $this->image = $this->image->resize($width, $height);
        }

        return $this;
    }

    private static function resolveContext(?ApplicationContext $context): ApplicationContext
    {
        $resolved = $context ?? self::$defaultContext;
        if ($resolved === null) {
            throw new \RuntimeException('ApplicationContext is required for ImageProcessor factories.');
        }

        return $resolved;
    }

    public function crop(int $width, int $height, ?int $x = null, ?int $y = null): self
    {
        $this->security->validateDimensions($width, $height);

        $this->operations[] = ['crop', compact('width', 'height', 'x', 'y')];

        if ($x !== null && $y !== null) {
            $this->image = $this->image->crop($width, $height, $x, $y);
        } else {
            $this->image = $this->image->crop($width, $height);
        }

        return $this;
    }

    public function fit(int $width, int $height, string $position = 'center'): self
    {
        $this->security->validateDimensions($width, $height);

        $this->operations[] = ['fit', compact('width', 'height', 'position')];

        // Scale to fit and crop to exact dimensions
        $this->image = $this->image->cover($width, $height, $position);

        return $this;
    }

    public function quality(int $quality): self
    {
        $this->security->validateQuality($quality);

        $this->operations[] = ['quality', compact('quality')];

        // Quality is applied during save/encode
        $this->config['current_quality'] = $quality;

        return $this;
    }

    public function format(string $format): self
    {
        $this->security->validateFormat($format);

        $this->operations[] = ['format', compact('format')];
        $this->config['current_format'] = $format;

        return $this;
    }

    public function optimize(): self
    {
        $this->operations[] = ['optimize', []];

        // Enable optimization flags
        $this->config['optimize'] = true;

        return $this;
    }

    public function rotate(float $degrees, string $background = 'ffffff'): self
    {
        $this->operations[] = ['rotate', compact('degrees', 'background')];

        $this->image = $this->image->rotate($degrees, $background);

        return $this;
    }

    public function flipHorizontal(): self
    {
        $this->operations[] = ['flipHorizontal', []];

        $this->image = $this->image->flop();

        return $this;
    }

    public function flipVertical(): self
    {
        $this->operations[] = ['flipVertical', []];

        $this->image = $this->image->flip();

        return $this;
    }

    public function watermark(string $watermarkPath, string $position = 'bottom-right', int $opacity = 50): self
    {
        if (($this->config['features']['watermarking'] ?? true) === false) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'Watermarking is disabled'
            );
        }

        $this->operations[] = ['watermark', compact('watermarkPath', 'position', 'opacity')];

        try {
            $watermark = $this->manager->read($watermarkPath);

            // Apply opacity
            if ($opacity < 100) {
                // Apply opacity through color manipulation
                $watermark = $watermark->reduceColors(256);
            }

            // Position calculation
            $positions = $this->calculateWatermarkPosition($position, $watermark);

            $this->image = $this->image->place($watermark, 'top-left', $positions['x'], $positions['y']);

            return $this;
        } catch (\Exception $e) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'Watermark failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Output Methods
     */

    public function save(string $path): bool
    {
        try {
            $quality = $this->config['current_quality'] ?? $this->getDefaultQuality();

            $this->image->save($path, $quality);

            $this->logger->info('Image saved successfully', [
                'path' => $path,
                'operations' => count($this->operations),
                'final_size' => filesize($path)
            ]);

            return true;
        } catch (\Exception $e) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'Save failed: ' . $e->getMessage()
            );
        }
    }

    public function cached(?string $key = null, int $ttl = 3600): self
    {
        $this->cacheKey = $key ?? $this->generateCacheKey();

        try {
            $cacheData = [
                'image_data' => $this->getImageData(),
                'mime_type' => $this->getMimeType(),
                'width' => $this->getWidth(),
                'height' => $this->getHeight(),
                'operations' => $this->operations,
                'created_at' => time()
            ];

            $fullCacheKey = ($this->config['cache']['prefix'] ?? 'image_') . $this->cacheKey;

            $this->cache->set($fullCacheKey, $cacheData, $ttl);

            $this->logger->debug('Image cached', [
                'cache_key' => $this->cacheKey,
                'ttl' => $ttl,
                'size' => strlen($cacheData['image_data'])
            ]);

            return $this;
        } catch (\Exception $e) {
            $this->logger->warning('Cache save failed', [
                'error' => $e->getMessage(),
                'cache_key' => $this->cacheKey
            ]);

            // Don't throw - caching is optional
            return $this;
        }
    }

    public function toBase64(?string $format = null): string
    {
        $imageData = $this->getImageData($format);
        $mimeType = ($format !== null && $format !== '') ? "image/{$format}" : $this->getMimeType();

        return 'data:' . $mimeType . ';base64,' . base64_encode($imageData);
    }

    public function getImageData(?string $format = null): string
    {
        $format = $format ?? $this->config['current_format'] ?? null;
        $quality = $this->config['current_quality'] ?? $this->getDefaultQuality();

        try {
            if ($format !== null && $format !== '') {
                $encoder = $this->getEncoder($format, $quality);
                return (string) $this->image->encode($encoder);
            } else {
                return (string) $this->image->encode(new AutoEncoder(quality: $quality));
            }
        } catch (\Exception $e) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'Encode failed: ' . $e->getMessage()
            );
        }
    }


    /**
     * @param array<string, string> $headers
     */
    public function stream(array $headers = []): void
    {
        $imageData = $this->getImageData();
        $mimeType = $this->getMimeType();

        // Set headers
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . strlen($imageData));
        header('Cache-Control: public, max-age=3600');

        foreach ($headers as $name => $value) {
            header("{$name}: {$value}");
        }

        echo $imageData;
    }

    /**
     * Information Methods
     */

    public function getWidth(): int
    {
        return $this->image->width();
    }

    public function getHeight(): int
    {
        return $this->image->height();
    }

    public function getMimeType(): string
    {
        // Get MIME type from current format or original
        $format = $this->config['current_format'] ?? null;

        if ($format !== null && $format !== '') {
            return match ($format) {
                'jpeg', 'jpg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                default => 'image/jpeg'
            };
        }

        return $this->image->origin()->mediaType();
    }

    public function getFileSize(): int
    {
        return strlen($this->getImageData());
    }

    public function isValid(): bool
    {
        try {
            return $this->image->width() > 0 && $this->image->height() > 0;
        } catch (\Exception) {
            return false;
        }
    }

    public function getAspectRatio(): float
    {
        $height = $this->getHeight();
        return $height > 0 ? $this->getWidth() / $height : 1.0;
    }

    public function hasTransparency(): bool
    {
        // Check if image has alpha channel by sampling colors
        try {
            // For a more accurate transparency check, we could sample multiple pixels
            // but for now, assume PNG/GIF formats typically have transparency
            $mediaType = $this->image->origin()->mediaType();
            $format = strtolower($mediaType);
            return strpos($format, 'png') !== false || strpos($format, 'gif') !== false;
        } catch (\Exception) {
            // Fallback: assume no transparency if we can't determine
            return false;
        }
    }

    /**
     * Advanced Operations
     */

    public function modify(callable $callback): self
    {
        $this->operations[] = ['modify', ['callback' => 'custom']];

        try {
            $callback($this->image);
            return $this;
        } catch (\Exception $e) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'Custom modification failed: ' . $e->getMessage()
            );
        }
    }

    public function clone(): self
    {
        $clone = new self(
            $this->manager,
            $this->cache,
            $this->security,
            $this->logger,
            $this->config
        );

        $clone->image = clone $this->image;
        $clone->operations = $this->operations;

        return $clone;
    }

    public function reset(): self
    {
        $this->operations = [];
        $this->cacheKey = null;
        unset($this->config['current_quality'], $this->config['current_format']);

        return $this;
    }

    /**
     * Private Helper Methods
     */

    private function validateImage(): void
    {
        if (!isset($this->image)) {
            throw BusinessLogicException::operationNotAllowed(
                'image_processing',
                'Invalid image data'
            );
        }

        // Validate dimensions
        $this->security->validateDimensions($this->image->width(), $this->image->height());

        // Additional integrity checks
        if (($this->config['security']['check_image_integrity'] ?? true) === true) {
            if ($this->image->width() <= 0 || $this->image->height() <= 0) {
                throw BusinessLogicException::operationNotAllowed(
                    'image_processing',
                    'Invalid image dimensions'
                );
            }
        }
    }

    /**
     * @return array{x: int, y: int}
     */
    private function calculateWatermarkPosition(string $position, ImageInterface $watermark): array
    {
        $imageWidth = $this->getWidth();
        $imageHeight = $this->getHeight();
        $watermarkWidth = $watermark->width();
        $watermarkHeight = $watermark->height();

        return match ($position) {
            'top-left' => ['x' => 10, 'y' => 10],
            'top-right' => ['x' => $imageWidth - $watermarkWidth - 10, 'y' => 10],
            'bottom-left' => ['x' => 10, 'y' => $imageHeight - $watermarkHeight - 10],
            'bottom-right' => ['x' => $imageWidth - $watermarkWidth - 10, 'y' => $imageHeight - $watermarkHeight - 10],
            'center' => [
                'x' => ($imageWidth - $watermarkWidth) / 2,
                'y' => ($imageHeight - $watermarkHeight) / 2
            ],
            default => ['x' => $imageWidth - $watermarkWidth - 10, 'y' => $imageHeight - $watermarkHeight - 10]
        };
    }

    private function generateCacheKey(): string
    {
        $data = [
            'operations' => $this->operations,
            'config' => [
                'quality' => $this->config['current_quality'] ?? null,
                'format' => $this->config['current_format'] ?? null,
            ],
            'dimensions' => [$this->getWidth(), $this->getHeight()]
        ];

        return md5(serialize($data));
    }

    private function getDefaultQuality(): int
    {
        $format = $this->config['current_format'] ?? 'jpeg';

        return match ($format) {
            'jpeg', 'jpg' => $this->config['optimization']['jpeg_quality'] ?? 85,
            'webp' => $this->config['optimization']['webp_quality'] ?? 80,
            'png' => 100, // PNG is lossless
            'gif' => $this->config['optimization']['gif_quality'] ?? 85,
            default => 85
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function getDefaultConfig(): array
    {
        return [
            'optimization' => [
                'jpeg_quality' => 85,
                'webp_quality' => 80,
                'gif_quality' => 85,
            ],
            'security' => [
                'timeout' => 10,
                'user_agent' => 'Glueful-ImageProcessor/1.0',
                'check_image_integrity' => true,
            ],
            'cache' => [
                'prefix' => 'image_',
            ],
            'features' => [
                'watermarking' => true,
                'format_conversion' => true,
            ]
        ];
    }

    /**
     * Get appropriate encoder for format and quality
     *
     * @param string $format Image format
     * @param int $quality Quality setting
     * @return \Intervention\Image\Interfaces\EncoderInterface
     */
    private function getEncoder(string $format, int $quality): \Intervention\Image\Interfaces\EncoderInterface
    {
        return match (strtolower($format)) {
            'jpg', 'jpeg' => new JpegEncoder(quality: $quality),
            'png' => new PngEncoder(),
            'gif' => new GifEncoder(),
            'webp' => new WebpEncoder(quality: $quality),
            default => new AutoEncoder(quality: $quality)
        };
    }
}
