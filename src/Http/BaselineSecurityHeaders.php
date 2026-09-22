<?php

declare(strict_types=1);

namespace Glueful\Http;

use Symfony\Component\HttpFoundation\Response;

/**
 * The security headers that are safe on every response of every application, applied at the
 * response chokepoint beside CORS and the CSP, and only where the response has not set its own:
 * `X-Content-Type-Options: nosniff` and `Referrer-Policy: strict-origin-when-cross-origin`.
 *
 * Framing and HSTS are deliberately not here. `X-Frame-Options` breaks legitimate embedding (use
 * the `security_headers` route middleware or a CSP `frame-ancestors` where framing matters), and
 * HSTS belongs to whatever terminates TLS.
 */
final class BaselineSecurityHeaders
{
    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ];

    public function applyToResponse(Response $response): void
    {
        foreach (self::HEADERS as $name => $value) {
            if (!$response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }
    }
}
