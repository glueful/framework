<?php

declare(strict_types=1);

namespace Glueful\Uploader\Contracts;

use Glueful\Uploader\MediaMetadata;
use Glueful\Uploader\Storage\StorageInterface;

/**
 * Narrow, upload-facing seam for rich-media processing.
 *
 * Core consumes this interface only if a concrete implementation is bound
 * (e.g. by the optional `glueful/media` extension). Without a binding, the
 * upload path falls back to dependency-free no-op behaviour. Core itself never
 * implements rich processing — it only delegates through this contract.
 */
interface MediaProcessorInterface
{
    /**
     * Extract type/dimensions/duration from a stored-or-temp file.
     * Implementations may use getID3 / getimagesize.
     */
    public function extractMetadata(string $filepath, string $mimeType): MediaMetadata;

    /**
     * Generate a thumbnail (writing it through the caller's storage) and return its
     * public URL, or null if the MIME type is unsupported or generation failed.
     * The processor MUST write via the passed-in $storage so the thumb lands on the
     * same disk the upload used — it never constructs its own storage.
     *
     * @param array<string, mixed> $options width,height,quality,subdirectory
     */
    public function generateThumbnail(
        StorageInterface $storage,
        string $sourcePath,
        string $storagePath,
        string $originalFilename,
        array $options = []
    ): ?string;

    /** True if a thumbnail can be produced for this MIME type. */
    public function supportsThumbnail(string $mimeType): bool;

    /**
     * Render an on-demand variant; returns encoded bytes + mime.
     * $options: width,height,quality,format,fit (contain|cover|fill).
     *
     * @param array<string, mixed> $options
     * @return array{data: string, mime: string}
     */
    public function renderVariant(string $sourcePath, array $options): array;
}
