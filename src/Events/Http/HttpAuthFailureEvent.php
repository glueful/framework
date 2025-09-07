<?php

declare(strict_types=1);

namespace Glueful\Events\Http;

use Symfony\Component\HttpFoundation\Request;
use Glueful\Events\BaseEvent;

/**
 * Event emitted when HTTP-level authentication fails
 * Framework emits this for protocol-level auth failures (missing headers, malformed tokens)
 * Applications can listen to this for business-level security logging
 */
class HttpAuthFailureEvent extends BaseEvent
{
    public function __construct(
        public readonly string $reason,
        public readonly Request $request,
        public readonly ?string $tokenPrefix = null
    ) {
        parent::__construct();
    }
}
