<?php

declare(strict_types=1);

namespace Glueful\Routing\Attributes;

/**
 * Hand-authored OpenAPI operation prose for a controller method, overriding the
 * values the reflect generator derives from the route name/path. Every field is
 * optional — omitted fields keep the derived value.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class ApiOperation
{
    /** @param list<string> $tags */
    public function __construct(
        public readonly string $summary = '',
        public readonly string $description = '',
        public readonly array $tags = [],
        public readonly ?string $operationId = null,
        public readonly bool $deprecated = false,
    ) {
    }
}
