<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Attributes;

use Attribute;

/**
 * Mark controller method or class as having filterable fields
 *
 * @example
 * ```php
 * #[Filterable(['status', 'role', 'created_at'])]
 * public function index(): Response
 * {
 *     // ...
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Filterable
{
    /**
     * Create a new Filterable attribute
     *
     * @param array<string> $fields Allowed filterable fields
     * @param int $maxFilters Maximum number of filters allowed
     * @param int $maxDepth Maximum nesting depth for filters
     * @param bool $strict If true, unknown fields will throw an error
     */
    public function __construct(
        public readonly array $fields,
        public readonly int $maxFilters = 20,
        public readonly int $maxDepth = 3,
        public readonly bool $strict = false,
    ) {
    }

    /**
     * Check if a field is allowed
     *
     * @param string $field The field name to check
     * @return bool
     */
    public function allows(string $field): bool
    {
        return in_array($field, $this->fields, true);
    }
}
