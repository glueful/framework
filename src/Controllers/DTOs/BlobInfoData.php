<?php

declare(strict_types=1);

namespace Glueful\Controllers\DTOs;

use Glueful\Http\Contracts\HasResponseMessage;
use Glueful\Http\Contracts\ResponseData;

/**
 * Response payload for {@see \Glueful\Controllers\UploadController::info()}.
 *
 * The `data` for this endpoint is a PASS-THROUGH of the blob row returned by
 * {@see \Glueful\Repository\BlobRepository::findByUuidWithDeleteFilter()} — an
 * arbitrary set of columns in DB-declared order — with its `url` rewritten to a
 * public URL and an OPTIONAL `native_url` appended only when a native object-store
 * URL was minted. A fixed-property DTO cannot reproduce an arbitrary row
 * byte-identically, so this DTO uses the serializer's `toArray()` escape hatch
 * ({@see \Glueful\Serialization\ResponseDataSerializer}) to emit the blob array
 * verbatim — preserving exact key set + order and the omit-when-absent semantics of
 * `native_url`.
 *
 * The envelope `message` is supplied via {@see HasResponseMessage::responseMessage()}
 * and stored in a PRIVATE property so it is NOT serialized into `data`. (The custom
 * `toArray()` already excludes it — the private property is belt-and-braces.)
 */
final class BlobInfoData implements ResponseData, HasResponseMessage
{
    /**
     * @param array<string, mixed> $blob The blob row, already URL-resolved and
     *                                    (optionally) native_url-augmented.
     */
    public function __construct(
        private readonly array $blob,
        private readonly string $message = 'Blob metadata',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->blob;
    }

    public function responseMessage(): string
    {
        return $this->message;
    }
}
