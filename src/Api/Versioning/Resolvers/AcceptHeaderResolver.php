<?php

declare(strict_types=1);

namespace Glueful\Api\Versioning\Resolvers;

use Glueful\Api\Versioning\ApiVersion;
use Glueful\Api\Versioning\Contracts\VersionResolverInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves API version from Accept header vendor media type
 *
 * Examples:
 * - Accept: application/vnd.glueful.v2+json → v2
 * - Accept: application/vnd.glueful.v1.2+json → v1.2
 * - Accept: application/vnd.myapi.v3+xml → v3 (with vendor="myapi")
 */
final class AcceptHeaderResolver implements VersionResolverInterface
{
    /** @var string Compiled regex pattern */
    private string $pattern;

    /**
     * @param string $vendorName Vendor name in media type
     * @param int $priority Resolver priority (higher = checked first)
     */
    public function __construct(
        private readonly string $vendorName = 'glueful',
        private readonly int $priority = 70
    ) {
        // Match application/vnd.{vendor}.v{version}+{format}
        $escapedVendor = preg_quote($vendorName, '#');
        $this->pattern = '#application/vnd\.' . $escapedVendor . '\.v(\d+(?:\.\d+(?:\.\d+)?)?)\+#i';
    }

    public function resolve(Request $request): ?ApiVersion
    {
        $accept = $request->headers->get('Accept', '');

        if ($accept === '') {
            return null;
        }

        if (preg_match($this->pattern, $accept, $matches)) {
            return ApiVersion::fromString($matches[1]);
        }

        return null;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function getName(): string
    {
        return 'accept_header';
    }
}
