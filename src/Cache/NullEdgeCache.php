<?php

declare(strict_types=1);

namespace Glueful\Cache;

/**
 * No-op default for the edge-cache capability.
 *
 * Bound in core so that {@see \Glueful\Cache\Contracts\EdgeCacheInterface}
 * always resolves even when no CDN integration is installed. Every method
 * returns the disabled-state value (edge caching off): headers are empty and
 * purges are silent no-ops. Installing the {@code glueful/cdn} extension rebinds
 * this interface to a real edge/CDN implementation.
 */
final class NullEdgeCache implements Contracts\EdgeCacheInterface
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function getProvider(): ?string
    {
        return null;
    }

    /**
     * @return array<string, string>
     */
    public function generateCacheHeaders(string $route, ?string $contentType = null): array
    {
        return [];
    }

    public function purgeUrl(string $url): bool
    {
        return false;
    }

    public function purgeByTag(string $tag): bool
    {
        return false;
    }

    public function purgeAll(): bool
    {
        return false;
    }
}
