<?php

declare(strict_types=1);

namespace Glueful\Security;

final class SecurityHeaders
{
    /**
     * Default headers for static asset responses.
     *
     * @return array<string, string>
     */
    public static function defaultStaticAssetHeaders(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'Content-Security-Policy' =>
                "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:;",
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-XSS-Protection' => '0',
        ];
    }

    /**
     * Default headers for an SPA's HTML document (index.html). Unlike a static asset, a built
     * front-end injects style elements at runtime (component libraries apply their theme that
     * way) and may load images from blobs/data URLs it created; a policy that forbids those
     * silently strips the app's styling. Scripts stay self-only.
     *
     * @return array<string, string>
     */
    public static function defaultDocumentHeaders(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => self::DEFAULT_DOCUMENT_CSP,
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-XSS-Protection' => '0',
        ];
    }

    public const DEFAULT_DOCUMENT_CSP = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; "
        . "object-src 'none'; base-uri 'self'; frame-ancestors 'self';";
}
