<?php

declare(strict_types=1);

namespace Glueful\Support\Documentation;

/**
 * Builds OpenAPI schema components matching PaginatedResourceResponse.
 *
 * Provides per-resource envelope schemas (use envelopeFor()) and the
 * shared PaginationMeta / PaginationLinks components for cross-endpoint
 * reuse.
 */
final class PaginationSchemaBuilder
{
    /**
     * Build a pagination envelope schema with $itemSchemaRef as the data items.
     *
     * @return array<string, mixed>
     */
    public function envelopeFor(string $itemSchemaRef): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'success' => ['type' => 'boolean', 'example' => true],
                'data' => [
                    'type' => 'array',
                    'items' => ['$ref' => $itemSchemaRef],
                ],
                'current_page' => ['type' => 'integer', 'example' => 1],
                'per_page' => ['type' => 'integer', 'example' => 25],
                'total' => ['type' => 'integer', 'example' => 137],
                'total_pages' => ['type' => 'integer', 'example' => 6],
                'from' => ['type' => 'integer', 'example' => 1],
                'to' => ['type' => 'integer', 'example' => 25],
                'has_next_page' => ['type' => 'boolean', 'example' => true],
                'has_previous_page' => ['type' => 'boolean', 'example' => false],
                'links' => ['$ref' => '#/components/schemas/PaginationLinks'],
            ],
            'required' => ['data', 'current_page', 'per_page', 'total', 'total_pages'],
        ];
    }

    /**
     * Get the standalone component schemas for PaginationMeta and PaginationLinks.
     *
     * @return array<string, array<string, mixed>>
     */
    public function components(): array
    {
        return [
            'PaginationMeta' => [
                'type' => 'object',
                'properties' => [
                    'current_page' => ['type' => 'integer', 'example' => 1],
                    'per_page' => ['type' => 'integer', 'example' => 25],
                    'total' => ['type' => 'integer', 'example' => 137],
                    'total_pages' => ['type' => 'integer', 'example' => 6],
                    'from' => ['type' => 'integer', 'example' => 1],
                    'to' => ['type' => 'integer', 'example' => 25],
                    'has_next_page' => ['type' => 'boolean', 'example' => true],
                    'has_previous_page' => ['type' => 'boolean', 'example' => false],
                ],
                'required' => ['current_page', 'per_page', 'total', 'total_pages'],
            ],
            'PaginationLinks' => [
                'type' => 'object',
                'properties' => [
                    'first' => ['type' => 'string', 'format' => 'uri', 'example' => '/api/users?page=1'],
                    'last' => ['type' => 'string', 'format' => 'uri', 'example' => '/api/users?page=6'],
                    'prev' => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                    'next' => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                ],
            ],
        ];
    }
}
