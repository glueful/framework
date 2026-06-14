<?php

declare(strict_types=1);

namespace Glueful\Routing\Attributes;

/**
 * The deliberate escape hatch for NON-JSON request bodies — chiefly multipart/file
 * uploads. A JSON body (even polymorphic/free-form) MUST use a typed RequestData
 * DTO, never this attribute. When present on a handler it overrides RequestData /
 * #[Validate] request-body inference in the reflect generator.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class ApiRequestBody
{
    /** @param array<string,mixed> $schema Inline OpenAPI schema for the non-JSON body. */
    public function __construct(
        public readonly array $schema,
        public readonly string $contentType = 'multipart/form-data',
        public readonly bool $required = true,
        public readonly string $description = '',
    ) {
        if ($contentType === 'application/json') {
            throw new \InvalidArgumentException(
                '#[ApiRequestBody] is for non-JSON bodies only (e.g. multipart); '
                . 'use a RequestData DTO for an application/json body.'
            );
        }
    }
}
