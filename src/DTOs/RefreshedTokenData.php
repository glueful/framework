<?php

declare(strict_types=1);

namespace Glueful\DTOs;

use Glueful\Http\Contracts\HasResponseMessage;
use Glueful\Http\Contracts\ResponseData;

/**
 * Response payload for {@see \Glueful\Controllers\AuthController::refreshToken()}.
 *
 * The public properties mirror — by exact name AND declaration order (the
 * serializer reflects properties in order, so the emitted JSON key order stays
 * byte-identical) — the `data` keys the endpoint previously returned via
 * `Response::success($result, ...)`:
 * `access_token`, `refresh_token`, `expires_in`, `token_type`, `user`.
 *
 * The envelope `message` is supplied via {@see HasResponseMessage::responseMessage()}
 * and stored in a PRIVATE promoted property so the
 * {@see \Glueful\Serialization\ResponseDataSerializer} (public-property reflection)
 * does NOT serialize it into `data`.
 */
final class RefreshedTokenData implements ResponseData, HasResponseMessage
{
    /**
     * @param array<string, mixed> $user
     */
    public function __construct(
        public readonly string $access_token,
        public readonly string $refresh_token,
        public readonly int $expires_in,
        public readonly string $token_type,
        public readonly array $user,
        private readonly string $message = 'Token refreshed successfully',
    ) {
    }

    public function responseMessage(): string
    {
        return $this->message;
    }
}
