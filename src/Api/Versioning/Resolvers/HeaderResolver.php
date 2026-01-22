<?php

declare(strict_types=1);

namespace Glueful\Api\Versioning\Resolvers;

use Glueful\Api\Versioning\ApiVersion;
use Glueful\Api\Versioning\Contracts\VersionResolverInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves API version from custom HTTP header
 *
 * Examples:
 * - X-Api-Version: 2 → v2
 * - X-Api-Version: v1 → v1
 * - X-Api-Version: 1.0 → v1.0
 */
final class HeaderResolver implements VersionResolverInterface
{
    /**
     * @param string $headerName Header name to check
     * @param int $priority Resolver priority (higher = checked first)
     */
    public function __construct(
        private readonly string $headerName = 'X-Api-Version',
        private readonly int $priority = 80
    ) {
    }

    public function resolve(Request $request): ?ApiVersion
    {
        $version = $request->headers->get($this->headerName);

        if ($version === null || $version === '') {
            return null;
        }

        return ApiVersion::fromString($version);
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getName(): string
    {
        return 'header';
    }
}
