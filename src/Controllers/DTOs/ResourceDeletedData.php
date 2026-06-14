<?php

declare(strict_types=1);

namespace Glueful\Controllers\DTOs;

use Glueful\Http\Contracts\HasResponseMessage;
use Glueful\Http\Contracts\ResponseData;

/**
 * Response payload for {@see \Glueful\Controllers\ResourceController::destroy()}.
 *
 * Mirrors the exact `data` shape the endpoint previously returned via
 * `Response::success(['affected' => 1, 'success' => true, 'message' => 'Record
 * deleted successfully'], 'Resource deleted successfully')`.
 *
 * Note the deliberate duplication: the `data` payload carries its OWN `success`
 * and `message` keys (public promoted properties — serialized into `data` by the
 * {@see \Glueful\Serialization\ResponseDataSerializer}), distinct from the OUTER
 * envelope's `success` flag and `message`. The envelope `message` is supplied via
 * {@see HasResponseMessage::responseMessage()} and stored in a PRIVATE promoted
 * property (`$envelopeMessage`) so it is NOT serialized into `data`.
 */
final class ResourceDeletedData implements ResponseData, HasResponseMessage
{
    public function __construct(
        public readonly int $affected,
        public readonly bool $success,
        public readonly string $message,
        private readonly string $envelopeMessage = 'Resource deleted successfully',
    ) {
    }

    public function responseMessage(): string
    {
        return $this->envelopeMessage;
    }
}
