<?php

declare(strict_types=1);

namespace Glueful\Routing\Attributes;

/**
 * Documents a request body the reflect generator can't infer from a hydrating
 * RequestData param — i.e. a handler that must stay manual (polymorphic login,
 * resource store/update, or a non-JSON body). When present on a handler it
 * overrides RequestData / #[Validate] request-body inference in the reflect
 * generator.
 *
 * Type-first: `schema` is a DTO CLASS reflected via ClassSchemaReflector (the
 * JSON doc-only path — no runtime hydration); `inlineSchema` is a raw array for
 * multipart/file/non-JSON bodies ONLY (never application/json — that would
 * recreate the inline-JSON mini-language). Exactly one of the two must be set.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class ApiRequestBody
{
    /**
     * @param class-string|null        $schema        DTO class for a (usually JSON) doc-only body.
     * @param array<string,mixed>|null $inlineSchema  Raw OpenAPI schema for a NON-JSON (multipart/file) body.
     */
    public function __construct(
        public readonly ?string $schema = null,
        public readonly ?array $inlineSchema = null,
        public readonly string $contentType = 'application/json',
        public readonly bool $required = true,
        public readonly string $description = '',
    ) {
        $hasClass = $schema !== null;
        $hasInline = $inlineSchema !== null;
        if ($hasClass === $hasInline) {
            throw new \InvalidArgumentException(
                '#[ApiRequestBody] requires exactly one of $schema (DTO class) or $inlineSchema (non-JSON array).'
            );
        }
        if ($hasInline && $contentType === 'application/json') {
            throw new \InvalidArgumentException(
                '#[ApiRequestBody] inlineSchema is for non-JSON bodies only (e.g. multipart); '
                . 'use a DTO class via $schema for an application/json body.'
            );
        }
    }
}
