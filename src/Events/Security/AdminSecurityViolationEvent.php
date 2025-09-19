<?php

declare(strict_types=1);

namespace Glueful\Events\Security;

use Symfony\Component\HttpFoundation\Request;
use Glueful\Events\Contracts\BaseEvent;

/**
 * Admin Security Violation Event
 *
 * Event emitted when a security violation occurs during admin access
 * Framework emits this for admin security violations
 * Applications can listen to this for business-level security incident tracking
 */
class AdminSecurityViolationEvent extends BaseEvent
{
    public function __construct(
        public readonly string $userUuid,
        public readonly string $violationType,
        public readonly Request $request,
        public readonly string $message
    ) {
        parent::__construct();
    }
}
