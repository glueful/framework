<?php

declare(strict_types=1);

namespace Glueful\Controllers\DTOs;

use Glueful\Http\Contracts\HasResponseMessage;
use Glueful\Http\Contracts\ResponseData;

/**
 * Response payload for {@see \Glueful\Controllers\UploadController::upload()}.
 *
 * DOC-ONLY: the endpoint returns `Response::created($result, 'Upload successful')`
 * where `$result` is the plain array assembled by
 * {@see \Glueful\Uploader\FileUploader::uploadMedia()} (then augmented in the
 * controller with `visibility`). This DTO is never instantiated at runtime — it
 * exists solely so the reflect generator can document the 201 body schema, and it
 * mirrors — by exact name AND declaration order — the `data` keys that array
 * carries: `type`, `url`, `thumb_url`, `mime_type`, `size_bytes`, `width`,
 * `height`, `duration_s`, `filename`, `path`, `blob_uuid` (present because the
 * controller always passes `save_to_blobs => true`), and the controller-appended
 * `visibility`.
 *
 * Nullable media fields (`thumb_url`/`width`/`height`/`duration_s`) reflect the
 * type-only metadata path: without `glueful/media` they come back null.
 *
 * The envelope `message` is supplied via {@see HasResponseMessage::responseMessage()}
 * and stored in a PRIVATE promoted property so it is NOT serialized into `data`.
 */
final class UploadResultData implements ResponseData, HasResponseMessage
{
    public function __construct(
        public readonly string $type,
        public readonly string $url,
        public readonly ?string $thumb_url,
        public readonly string $mime_type,
        public readonly int $size_bytes,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly ?int $duration_s,
        public readonly string $filename,
        public readonly string $path,
        public readonly string $blob_uuid,
        public readonly string $visibility,
        private readonly string $message = 'Upload successful',
    ) {
    }

    public function responseMessage(): string
    {
        return $this->message;
    }
}
