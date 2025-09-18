<?php

declare(strict_types=1);

namespace Glueful\Events\Security;

use Symfony\Component\HttpFoundation\Request;
use Glueful\Events\Contracts\BaseEvent;

/**
 * Admin Access Event
 *
 * Event emitted when an admin user successfully accesses an admin resource
 * Framework emits this for admin access logging and monitoring
 * Applications can listen to this for business-level admin activity tracking
 */
class AdminAccessEvent extends BaseEvent
{
    public function __construct(
        public readonly string $userUuid,
        public readonly string $permission,
        public readonly string $resource,
        public readonly Request $request
    ) {
        parent::__construct();
    }
}
