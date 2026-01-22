<?php

declare(strict_types=1);

namespace Glueful\Api\Versioning\Resolvers;

use Glueful\Api\Versioning\ApiVersion;
use Glueful\Api\Versioning\Contracts\VersionResolverInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves API version from query parameter
 *
 * Examples:
 * - ?api-version=2 → v2
 * - ?api-version=v1 → v1
 * - ?api-version=1.0 → v1.0
 */
final class QueryParameterResolver implements VersionResolverInterface
{
    /**
     * @param string $parameterName Query parameter name
     * @param int $priority Resolver priority (higher = checked first)
     */
    public function __construct(
        private readonly string $parameterName = 'api-version',
        private readonly int $priority = 60
    ) {
    }

    public function resolve(Request $request): ?ApiVersion
    {
        $version = $request->query->get($this->parameterName);

        if ($version === null || $version === '' || !is_string($version)) {
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
        return 'query_parameter';
    }
}
