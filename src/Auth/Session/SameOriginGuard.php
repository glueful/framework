<?php

declare(strict_types=1);

namespace Glueful\Auth\Session;

use Symfony\Component\HttpFoundation\Request;

/**
 * Same-origin check for endpoints whose only credential is a cookie.
 *
 * Such endpoints cannot rely on a CSRF token — the caller may hold none yet, and the browser
 * attaches the cookie automatically — so origin provenance is the protection.
 *
 * `Sec-Fetch-Site` is authoritative when present: the browser sets it and page script cannot
 * forge it. Only `same-origin` passes; `same-site` is rejected because a sibling subdomain is
 * a different origin. Without fetch metadata, the `Origin` header must match this request's
 * own scheme, host and port exactly. A request carrying neither header is rejected: browsers
 * send one on a POST, and non-browser clients belong on the bearer refresh endpoint.
 */
final class SameOriginGuard
{
    public function isSameOrigin(Request $request): bool
    {
        $fetchSite = $request->headers->get('Sec-Fetch-Site');
        if (is_string($fetchSite) && $fetchSite !== '') {
            return strtolower($fetchSite) === 'same-origin';
        }

        $origin = $request->headers->get('Origin');
        if (!is_string($origin) || $origin === '') {
            return false;
        }

        return $origin === $request->getSchemeAndHttpHost();
    }
}
