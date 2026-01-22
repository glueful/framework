<?php

declare(strict_types=1);

namespace Glueful\Api\Versioning\Contracts;

use Glueful\Api\Versioning\ApiVersion;
use Symfony\Component\HttpFoundation\Request;

/**
 * Contract for resolving API version from a request
 *
 * Implementations extract version information from different request sources
 * (URL path, headers, query parameters, Accept header, etc.)
 */
interface VersionResolverInterface
{
    /**
     * Attempt to resolve version from request
     *
     * @param Request $request The HTTP request
     * @return ApiVersion|null Resolved version or null if not found in this source
     */
    public function resolve(Request $request): ?ApiVersion;

    /**
     * Get resolver priority (higher = checked first)
     *
     * Typical priorities:
     * - URL prefix: 100
     * - Header: 80
     * - Accept header: 70
     * - Query parameter: 60
     */
    public function getPriority(): int;

    /**
     * Get resolver name for debugging and logging
     */
    public function getName(): string;
}
