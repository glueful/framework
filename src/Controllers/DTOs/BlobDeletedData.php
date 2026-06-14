<?php

declare(strict_types=1);

namespace Glueful\Controllers\DTOs;

use Glueful\Http\Contracts\HasResponseMessage;
use Glueful\Http\Contracts\ResponseData;

/**
 * Response payload for {@see \Glueful\Controllers\UploadController::delete()}.
 *
 * Mirrors the single `data` key the endpoint previously returned via
 * `Response::success(['uuid' => $uuid], 'Blob deleted')`.
 *
 * The envelope `message` is supplied via {@see HasResponseMessage::responseMessage()}
 * and stored in a PRIVATE promoted property so the
 * {@see \Glueful\Serialization\ResponseDataSerializer} does NOT serialize it into
 * `data`.
 */
final class BlobDeletedData implements ResponseData, HasResponseMessage
{
    public function __construct(
        public readonly string $uuid,
        private readonly string $message = 'Blob deleted',
    ) {
    }

    public function responseMessage(): string
    {
        return $this->message;
    }
}
