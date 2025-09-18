<?php

declare(strict_types=1);

namespace Glueful\Events\Security;

use Symfony\Component\HttpFoundation\Request;
use Glueful\Events\Contracts\BaseEvent;

/**
 * CSRF Violation Event
 *
 * Event emitted when CSRF token validation fails
 * Framework emits this for HTTP protocol CSRF violations
 * Applications can listen to this for business-level security logging
 */
class CSRFViolationEvent extends BaseEvent
{
    public function __construct(
        public readonly string $reason,
        public readonly Request $request
    ) {
        parent::__construct();
    }
}
