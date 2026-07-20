<?php

declare(strict_types=1);

namespace Glueful\Http\Security;

/**
 * Immutable value object describing a validated, DNS-pinned outbound HTTP target.
 *
 * Produced exclusively by SafeOutboundTargetResolver. `host` + `ip` are meant to be
 * installed into the Symfony HttpClient `resolve` map so the connection is pinned to
 * the exact address that was checked (closing the DNS-rebinding / TOCTOU gap between
 * validation and connection), while `canonicalUrl` retains the original hostname so
 * TLS SNI and certificate verification keep working against that hostname.
 */
final class ResolvedOutboundTarget
{
    public function __construct(
        public readonly string $canonicalUrl,
        public readonly string $host,
        public readonly int $port,
        public readonly string $ip
    ) {
    }
}
