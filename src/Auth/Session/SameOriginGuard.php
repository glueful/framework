<?php

declare(strict_types=1);

namespace Glueful\Auth\Session;

use Glueful\Http\Cors;
use Symfony\Component\HttpFoundation\Request;

/**
 * Same-origin check for endpoints whose only credential is a cookie.
 *
 * Such endpoints cannot rely on a CSRF token — the caller may hold none yet, and the browser
 * attaches the cookie automatically — so origin provenance is the protection.
 *
 * `Sec-Fetch-Site` is authoritative when present: the browser sets it and page script cannot
 * forge it. `same-origin` passes. `same-site` (a sibling subdomain, e.g. an SPA on
 * app.example.com calling api.example.com) passes ONLY when the request's `Origin` is one the
 * CORS configuration explicitly trusts with credentials ({@see Cors::allowsCredentialedOrigin()}:
 * credentials on, no wildcard, exact entry or pattern match); with no CORS policy wired in, or
 * any other value (`cross-site`, `none`), it is rejected. Without fetch metadata, the `Origin`
 * header must match this request's own scheme, host and port exactly. A request carrying
 * neither header is rejected: browsers send one on a POST, and non-browser clients belong on
 * the bearer refresh endpoint.
 */
final class SameOriginGuard
{
    public function __construct(private readonly ?Cors $cors = null)
    {
    }

    public function isSameOrigin(Request $request): bool
    {
        $fetchSite = $request->headers->get('Sec-Fetch-Site');
        if (is_string($fetchSite) && $fetchSite !== '') {
            return match (strtolower($fetchSite)) {
                'same-origin' => true,
                'same-site' => $this->isTrustedSibling($request),
                default => false,
            };
        }

        $origin = $request->headers->get('Origin');
        if (!is_string($origin) || $origin === '') {
            return false;
        }

        return $origin === $request->getSchemeAndHttpHost();
    }

    private function isTrustedSibling(Request $request): bool
    {
        if ($this->cors === null) {
            return false;
        }

        $origin = $request->headers->get('Origin');
        if (!is_string($origin) || $origin === '') {
            return false;
        }

        return $this->cors->allowsCredentialedOrigin($origin);
    }
}
