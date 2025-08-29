<?php

declare(strict_types=1);

namespace Glueful\Events\Http;

use Symfony\Component\HttpFoundation\Request;
use Glueful\Events\BaseEvent;

/**
 * Event emitted when HTTP-level authentication succeeds
 * Framework emits this when JWT token is valid at HTTP protocol level
 * Applications can listen to this for business-level auth tracking
 */
class HttpAuthSuccessEvent extends BaseEvent
{
    public function __construct(
        public readonly Request $request,
        public readonly array $tokenMetadata
    ) {
        parent::__construct();
    }
}
