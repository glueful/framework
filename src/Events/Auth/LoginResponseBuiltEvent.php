<?php

declare(strict_types=1);

namespace Glueful\Events\Auth;

use Glueful\Events\Contracts\BaseEvent;

/**
 * Dispatched after the login response structure is finalized.
 * Intended for analytics/metrics; response is not mutated by this event.
 */
final class LoginResponseBuiltEvent extends BaseEvent
{
    /**
     * @param array<string, mixed> $response Final response map
     */
    public function __construct(private readonly array $response)
    {
        parent::__construct();
    }

    /** @return array<string, mixed> */
    public function getResponse(): array
    {
        return $this->response;
    }
}
