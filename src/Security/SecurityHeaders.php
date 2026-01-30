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
}
