<?php

declare(strict_types=1);

namespace Glueful\Controllers\DTOs;

use Glueful\Http\Contracts\HasResponseMessage;
use Glueful\Http\Contracts\ResponseData;

/**
 * Response payload for {@see \Glueful\Controllers\UploadController::signedUrl()}.
 *
 * Mirrors — by exact name AND declaration order — the `data` keys the endpoint
 * previously returned via `Response::success($payload, 'Signed URL generated')`:
 * `uuid`, `signed_url`, `expires_in`, `expires_at`, and the OPTIONAL `native_url`.
 *
 * `native_url` is an UNINITIALIZED typed property: the
 * {@see \Glueful\Serialization\ResponseDataSerializer} SKIPS uninitialized typed
 * properties, so the key is OMITTED when no native URL exists (matching the
 * pre-migration body, which only added the key when a native URL was minted) and
 * emitted only when {@see self::withNativeUrl()} sets it.
 *
 * The envelope `message` is supplied via {@see HasResponseMessage::responseMessage()}
 * and stored in a PRIVATE promoted property so it is NOT serialized into `data`.
 */
final class SignedUrlData implements ResponseData, HasResponseMessage
{
    public function __construct(
        public readonly string $uuid,
        public readonly string $signed_url,
        public readonly int $expires_in,
        public readonly string $expires_at,
        private readonly string $message = 'Signed URL generated',
    ) {
    }

    /**
     * Declared AFTER the promoted constructor properties so reflection emits it LAST
     * (matching the pre-migration array, which appended `native_url` to the payload).
     */
    public string $native_url;

    public function withNativeUrl(?string $nativeUrl): self
    {
        if ($nativeUrl !== null) {
            $this->native_url = $nativeUrl;
        }

        return $this;
    }

    public function responseMessage(): string
    {
        return $this->message;
    }
}
