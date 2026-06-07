<?php

declare(strict_types=1);

namespace Glueful\Cache\Contracts;

/**
 * Core seam for the edge-cache / CDN capability.
 *
 * Models the whole edge-cache capability — cache-control header generation,
 * provider state, and purge — under one contract. Core only calls
 * {@see self::generateCacheHeaders()} (via ResponseCachingTrait); the purge
 * methods exist for the CDN-specific command that lives behind this same
 * contract. The {@see \Glueful\Cache\NullEdgeCache} no-op default makes every
 * method a silent disabled-state result when no real integration is installed.
 *
 * Excluded from the seam on purpose (ops-only, kept on the concrete impl):
 * getStats(), isCacheable(), getCDNAdapter(), setCDNAdapter().
 */
interface EdgeCacheInterface
{
    /**
     * Check if edge caching is enabled.
     *
     * @return bool True when a real edge/CDN integration is installed and enabled.
     */
    public function isEnabled(): bool;

    /**
     * Get the configured CDN provider.
     *
     * @return string|null Provider slug (e.g. "cloudflare"), or null when no
     *                     integration is installed.
     */
    public function getProvider(): ?string;

    /**
     * Generate cache-control headers for edge caching.
     *
     * @param string $route The route name
     * @param string|null $contentType The content type of the response
     * @return array<string, string> The cache headers; empty when disabled,
     *                               so callers add nothing.
     */
    public function generateCacheHeaders(string $route, ?string $contentType = null): array;

    /**
     * Purge a specific URL from the CDN cache.
     *
     * @param string $url The URL to purge
     * @return bool True if the purge was successful; false when no integration
     *              is installed or the purge failed.
     */
    public function purgeUrl(string $url): bool;

    /**
     * Purge content by cache tag.
     *
     * @param string $tag The cache tag to purge
     * @return bool True if the purge was successful; false when no integration
     *              is installed or the purge failed.
     */
    public function purgeByTag(string $tag): bool;

    /**
     * Purge all content from the CDN cache.
     *
     * @return bool True if the purge was successful; false when no integration
     *              is installed or the purge failed.
     */
    public function purgeAll(): bool;
}
