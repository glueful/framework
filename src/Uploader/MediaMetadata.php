<?php

declare(strict_types=1);

namespace Glueful\Uploader;

/**
 * Value object representing media file metadata
 */
final readonly class MediaMetadata
{
    public function __construct(
        public string $type,
        public ?int $width = null,
        public ?int $height = null,
        public ?int $durationSeconds = null,
    ) {
    }

    /**
     * Check if this is an image
     */
    public function isImage(): bool
    {
        return $this->type === 'image';
    }

    /**
     * Check if this is a video
     */
    public function isVideo(): bool
    {
        return $this->type === 'video';
    }

    /**
     * Check if this is an audio file
     */
    public function isAudio(): bool
    {
        return $this->type === 'audio';
    }

    /**
     * Check if dimensions are available
     */
    public function hasDimensions(): bool
    {
        return $this->width !== null && $this->height !== null;
    }

    /**
     * Check if duration is available
     */
    public function hasDuration(): bool
    {
        return $this->durationSeconds !== null;
    }

    /**
     * Get aspect ratio (width / height)
     */
    public function getAspectRatio(): ?float
    {
        if (!$this->hasDimensions() || $this->height === 0) {
            return null;
        }

        return $this->width / $this->height;
    }

    /**
     * Get duration formatted as MM:SS or HH:MM:SS
     */
    public function getFormattedDuration(): ?string
    {
        if ($this->durationSeconds === null) {
            return null;
        }

        $hours = (int) floor($this->durationSeconds / 3600);
        $minutes = (int) floor(($this->durationSeconds % 3600) / 60);
        $seconds = $this->durationSeconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return sprintf('%d:%02d', $minutes, $seconds);
    }

    /**
     * Convert to array for JSON serialization
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'width' => $this->width,
            'height' => $this->height,
            'duration_s' => $this->durationSeconds,
            'duration_formatted' => $this->getFormattedDuration(),
            'aspect_ratio' => $this->getAspectRatio(),
        ];
    }
}
