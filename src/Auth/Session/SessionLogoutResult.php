<?php

declare(strict_types=1);

namespace Glueful\Auth\Session;

use Symfony\Component\HttpFoundation\Response;

/**
 * Outcome of a logout: the response with both cookies cleared, and whether the server-side
 * session was actually revoked.
 *
 * Callers must not report success on `revoked === false` — the browser credential is gone but
 * the server session may still be live, so a copied token could remain usable.
 */
final class SessionLogoutResult
{
    public function __construct(
        public readonly bool $revoked,
        public readonly Response $response,
    ) {
    }
}
