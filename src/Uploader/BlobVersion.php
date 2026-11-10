<?php

declare(strict_types=1);

namespace Glueful\Uploader;

/**
 * A short code for a blob's current contents. A blob replaced in place keeps its uuid but changes
 * its update time or size, and so its version. `GET /blobs/{uuid}?v=<version>` naming the current
 * version answers with a year-long immutable cache, because that URL can never answer different
 * bytes; a page linking the blob after a change links a new URL.
 *
 * Compute it from the blob row as the database returns it (BlobRepository), the same row the
 * blob route reads.
 */
final class BlobVersion
{
    /** @param array<string, mixed> $blob */
    public static function of(array $blob): string
    {
        $updatedAt = $blob['updated_at'] ?? null;
        $size = $blob['size'] ?? null;

        return substr(hash(
            'sha256',
            (is_scalar($updatedAt) ? (string) $updatedAt : '') . '|' . (is_scalar($size) ? (string) $size : ''),
        ), 0, 12);
    }
}
