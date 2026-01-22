<?php

declare(strict_types=1);

namespace Glueful\Api\Versioning\Resolvers;

use Glueful\Api\Versioning\ApiVersion;
use Glueful\Api\Versioning\Contracts\VersionResolverInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves API version from URL prefix
 *
 * Examples:
 * - /api/v1/users → v1
 * - /v2/posts → v2
 * - /api/v1.2/items → v1.2
 */
final class UrlPrefixResolver implements VersionResolverInterface
{
    /** @var string Compiled regex pattern */
    private string $pattern;

    /**
     * @param string $prefix API path prefix (e.g., "/api", "")
     * @param int $priority Resolver priority (higher = checked first)
     */
    public function __construct(
        private readonly string $prefix = '/api',
        private readonly int $priority = 100
    ) {
        // Build pattern: match prefix + /v{version}/ or /v{version} at end
        $escapedPrefix = preg_quote(rtrim($prefix, '/'), '#');
        $this->pattern = '#^' . $escapedPrefix . '/v(\d+(?:\.\d+(?:\.\d+)?)?)(?:/|$)#i';
    }

    public function resolve(Request $request): ?ApiVersion
    {
        $path = $request->getPathInfo();

        if (preg_match($this->pattern, $path, $matches)) {
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
        return 'url_prefix';
    }
}
