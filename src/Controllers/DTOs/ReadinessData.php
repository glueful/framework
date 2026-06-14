<?php

declare(strict_types=1);

namespace Glueful\Controllers\DTOs;

use Glueful\Http\Contracts\HasResponseMessage;
use Glueful\Http\Contracts\ResponseData;

/**
 * Response payload for the SUCCESS (200) branch of
 * {@see \Glueful\Controllers\HealthController::readiness()}.
 *
 * Mirrors — by exact name AND declaration order — the `data` keys the endpoint
 * previously returned via `Response::success($payload, 'Service is ready')`:
 * `status` ('ready'), `timestamp`, `checks`.
 *
 * Both `timestamp` and `checks` are VOLATILE — they are the controller's
 * already-computed values (a per-request `date('c')` and the live database/cache/
 * config check results) and are passed through UNCHANGED. They are typed loosely
 * (string / array) precisely so the DTO never reshapes them.
 *
 * The envelope `message` ('Service is ready') is supplied via
 * {@see HasResponseMessage::responseMessage()} and stored in a PRIVATE promoted
 * property so it is NOT serialized into `data` by
 * {@see \Glueful\Serialization\ResponseDataSerializer} (which reflects public
 * properties only).
 */
final class ReadinessData implements ResponseData, HasResponseMessage
{
    /**
     * @param array<string, mixed> $checks The live database/cache/config check results.
     */
    public function __construct(
        public readonly string $status,
        public readonly string $timestamp,
        public readonly array $checks,
        private readonly string $message = 'Service is ready',
    ) {
    }

    public function responseMessage(): string
    {
        return $this->message;
    }
}
