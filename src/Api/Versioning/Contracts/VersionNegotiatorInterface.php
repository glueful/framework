<?php

declare(strict_types=1);

namespace Glueful\Api\Versioning\Contracts;

use Glueful\Api\Versioning\ApiVersion;
use Symfony\Component\HttpFoundation\Request;

/**
 * Contract for negotiating API version from request using multiple strategies
 *
 * The negotiator tries multiple resolvers in priority order and returns
 * the first successfully resolved version, or falls back to a default.
 */
interface VersionNegotiatorInterface
{
    /**
     * Negotiate the API version from request
     *
     * @param Request $request The HTTP request
     * @return ApiVersion The negotiated version (returns default if none resolved)
     */
    public function negotiate(Request $request): ApiVersion;

    /**
     * Register a version resolver
     *
     * @param VersionResolverInterface $resolver The resolver to register
     */
    public function registerResolver(VersionResolverInterface $resolver): void;

    /**
     * Check if a version is supported
     *
     * @param ApiVersion $version The version to check
     * @return bool True if the version is supported
     */
    public function isSupported(ApiVersion $version): bool;

    /**
     * Check if a version is deprecated
     *
     * @param ApiVersion $version The version to check
     * @return bool True if the version is deprecated
     */
    public function isDeprecated(ApiVersion $version): bool;

    /**
     * Get sunset date for a deprecated version
     *
     * @param ApiVersion $version The version to check
     * @return \DateTimeImmutable|null The sunset date or null if not set
     */
    public function getSunsetDate(ApiVersion $version): ?\DateTimeImmutable;

    /**
     * Get the default API version
     *
     * @return ApiVersion The default version
     */
    public function getDefaultVersion(): ApiVersion;

    /**
     * Get all supported versions
     *
     * @return array<string> List of supported version strings
     */
    public function getSupportedVersions(): array;
}
