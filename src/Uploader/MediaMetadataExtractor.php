<?php

declare(strict_types=1);

namespace Glueful\Uploader;

use getID3;

/**
 * Extracts metadata from media files
 *
 * Uses getID3 library for pure PHP extraction of dimensions and duration
 * from images, video, and audio files. No external binaries required.
 *
 * Supported formats:
 * - Images: JPEG, PNG, GIF, WebP, BMP, TIFF
 * - Video: MP4, WebM, AVI, MOV, MKV, FLV
 * - Audio: MP3, WAV, OGG, FLAC, AAC, M4A
 */
final class MediaMetadataExtractor
{
    private ?getID3 $getID3 = null;

    /**
     * Extract metadata from a media file
     *
     * @param string $filepath Path to the media file
     * @param string $mimeType MIME type of the file
     * @return MediaMetadata Extracted metadata
     */
    public function extract(string $filepath, string $mimeType): MediaMetadata
    {
        $mediaType = $this->determineMediaType($mimeType);

        // For images, use getimagesize for reliability
        if ($mediaType === 'image') {
            return $this->extractImageMetadata($filepath);
        }

        // For video/audio, use getID3
        if ($mediaType === 'video' || $mediaType === 'audio') {
            return $this->extractWithGetID3($filepath, $mediaType);
        }

        return new MediaMetadata($mediaType);
    }

    /**
     * Determine media type category from MIME type
     */
    public function determineMediaType(string $mimeType): string
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => 'image',
            str_starts_with($mimeType, 'video/') => 'video',
            str_starts_with($mimeType, 'audio/') => 'audio',
            default => 'file',
        };
    }

    /**
     * Get detailed file info using getID3
     *
     * @param string $filepath Path to the media file
     * @return array<string, mixed> Raw getID3 analysis data
     */
    public function analyze(string $filepath): array
    {
        return $this->getAnalyzer()->analyze($filepath);
    }

    /**
     * Extract metadata from image file using getimagesize
     */
    private function extractImageMetadata(string $filepath): MediaMetadata
    {
        $imageInfo = @getimagesize($filepath);

        if ($imageInfo === false) {
            return new MediaMetadata('image');
        }

        return new MediaMetadata(
            type: 'image',
            width: $imageInfo[0],
            height: $imageInfo[1],
        );
    }

    /**
     * Extract metadata using getID3 library
     */
    private function extractWithGetID3(string $filepath, string $mediaType): MediaMetadata
    {
        $info = $this->getAnalyzer()->analyze($filepath);

        // Extract duration
        $duration = null;
        if (isset($info['playtime_seconds']) && $info['playtime_seconds'] > 0) {
            $duration = (int) round((float) $info['playtime_seconds']);
        }

        // Extract dimensions (for video)
        $width = null;
        $height = null;

        if ($mediaType === 'video') {
            // Try video stream info first
            if (isset($info['video']['resolution_x'])) {
                $width = (int) $info['video']['resolution_x'];
                $height = (int) ($info['video']['resolution_y'] ?? 0);
            }

            // Fallback to streams array
            if ($width === null && isset($info['streams'])) {
                foreach ($info['streams'] as $stream) {
                    if (isset($stream['resolution_x'])) {
                        $width = (int) $stream['resolution_x'];
                        $height = (int) ($stream['resolution_y'] ?? 0);
                        break;
                    }
                }
            }
        }

        return new MediaMetadata(
            type: $mediaType,
            width: $width ?: null,
            height: $height ?: null,
            durationSeconds: $duration,
        );
    }

    /**
     * Get or create getID3 analyzer instance
     */
    private function getAnalyzer(): getID3
    {
        if ($this->getID3 === null) {
            $this->getID3 = new getID3();

            // Configure for performance
            $this->getID3->setOption(['encoding' => 'UTF-8']);
        }

        return $this->getID3;
    }
}
