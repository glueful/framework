<?php

declare(strict_types=1);

namespace Glueful\Services;

use Psr\Http\Message\UploadedFileInterface;

/**
 * Image Processing Interface
 *
 * Modern, fluent interface for image processing operations.
 * Supports method chaining, multiple formats, and advanced transformations.
 */
interface ImageProcessorInterface
{
    /**
     * Static Factory Methods
     */

    /**
     * Create processor from file path or URL
     *
     * @param string $source Local file path or remote URL
     * @return self
     * @throws \Glueful\Exceptions\BusinessLogicException If source is invalid
     */
    public static function make(string $source): self;

    /**
     * Create processor from remote URL with options
     *
     * @param string $url Remote image URL
     * @param array<string, mixed> $options HTTP options (timeout, headers, etc.)
     * @return self
     * @throws \Glueful\Exceptions\BusinessLogicException If URL is invalid or unreachable
     */
    public static function fromUrl(string $url, array $options = []): self;

    /**
     * Create processor from uploaded file
     *
     * @param UploadedFileInterface $file Uploaded file instance
     * @return self
     * @throws \Glueful\Exceptions\BusinessLogicException If file is invalid
     */
    public static function fromUpload(UploadedFileInterface $file): self;

    /**
     * Create blank canvas
     *
     * @param int $width Canvas width
     * @param int $height Canvas height
     * @param string $background Background color (hex, rgb, rgba, or color name)
     * @return self
     */
    public static function create(int $width, int $height, string $background = 'ffffff'): self;

    /**
     * Transformation Operations (Fluent Interface)
     */

    /**
     * Resize image maintaining or ignoring aspect ratio
     *
     * @param int|null $width Target width (null to auto-calculate)
     * @param int|null $height Target height (null to auto-calculate)
     * @param bool $maintainAspect Whether to maintain aspect ratio
     * @return self
     */
    public function resize(?int $width = null, ?int $height = null, bool $maintainAspect = true): self;

    /**
     * Crop image to exact dimensions
     *
     * @param int $width Crop width
     * @param int $height Crop height
     * @param int|null $x X offset (null for center)
     * @param int|null $y Y offset (null for center)
     * @return self
     */
    public function crop(int $width, int $height, ?int $x = null, ?int $y = null): self;

    /**
     * Fit image into dimensions (resize and crop to fit)
     *
     * @param int $width Target width
     * @param int $height Target height
     * @param string $position Crop position: center, top, bottom, left, right
     * @return self
     */
    public function fit(int $width, int $height, string $position = 'center'): self;

    /**
     * Set image quality for lossy formats
     *
     * @param int $quality Quality percentage (0-100)
     * @return self
     */
    public function quality(int $quality): self;

    /**
     * Convert to different format
     *
     * @param string $format Target format (jpeg, png, gif, webp)
     * @return self
     */
    public function format(string $format): self;

    /**
     * Apply automatic optimization
     *
     * @return self
     */
    public function optimize(): self;

    /**
     * Rotate image by degrees
     *
     * @param float $degrees Degrees to rotate (positive = clockwise)
     * @param string $background Background color for empty areas
     * @return self
     */
    public function rotate(float $degrees, string $background = 'ffffff'): self;

    /**
     * Flip image horizontally
     *
     * @return self
     */
    public function flipHorizontal(): self;

    /**
     * Flip image vertically
     *
     * @return self
     */
    public function flipVertical(): self;

    /**
     * Add watermark image
     *
     * @param string $watermarkPath Path to watermark image
     * @param string $position Position: top-left, top-right, bottom-left, bottom-right, center
     * @param int $opacity Opacity percentage (0-100)
     * @return self
     */
    public function watermark(string $watermarkPath, string $position = 'bottom-right', int $opacity = 50): self;

    /**
     * Output Methods
     */

    /**
     * Save image to file path
     *
     * @param string $path Target file path
     * @return bool True if saved successfully
     * @throws \Glueful\Exceptions\BusinessLogicException If save fails
     */
    public function save(string $path): bool;

    /**
     * Cache processed image with automatic key generation
     *
     * @param string|null $key Custom cache key (auto-generated if null)
     * @param int $ttl Time to live in seconds
     * @return self
     */
    public function cached(?string $key = null, int $ttl = 3600): self;

    /**
     * Convert to base64 data URL
     *
     * @param string|null $format Output format (null to keep current)
     * @return string Base64 data URL
     */
    public function toBase64(?string $format = null): string;

    /**
     * Get raw image data as string
     *
     * @param string|null $format Output format (null to keep current)
     * @return string Raw image data
     */
    public function getImageData(?string $format = null): string;


    /**
     * Stream image directly to output
     *
     * @param array<string, string> $headers Additional HTTP headers
     * @return void
     */
    public function stream(array $headers = []): void;

    /**
     * Information Methods
     */

    /**
     * Get image width
     *
     * @return int Width in pixels
     */
    public function getWidth(): int;

    /**
     * Get image height
     *
     * @return int Height in pixels
     */
    public function getHeight(): int;

    /**
     * Get current MIME type
     *
     * @return string MIME type (e.g., 'image/jpeg')
     */
    public function getMimeType(): string;

    /**
     * Get estimated file size in bytes
     *
     * @return int File size in bytes
     */
    public function getFileSize(): int;

    /**
     * Check if image is valid and processable
     *
     * @return bool True if valid
     */
    public function isValid(): bool;

    /**
     * Get image aspect ratio
     *
     * @return float Aspect ratio (width/height)
     */
    public function getAspectRatio(): float;

    /**
     * Check if image has transparency
     *
     * @return bool True if has transparency
     */
    public function hasTransparency(): bool;

    /**
     * Advanced Operations
     */

    /**
     * Apply custom callback to underlying image object
     *
     * @param callable $callback Function that receives the native image object
     * @return self
     */
    public function modify(callable $callback): self;

    /**
     * Clone current processor instance
     *
     * @return self New instance with same image data
     */
    public function clone(): self;

    /**
     * Reset all pending operations (if using lazy processing)
     *
     * @return self
     */
    public function reset(): self;
}
