<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Attributes;

use Attribute;

/**
 * Mark controller method or class as having searchable fields
 *
 * @example
 * ```php
 * #[Searchable(['name', 'email', 'bio'])]
 * public function index(): Response
 * {
 *     // ...
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Searchable
{
    /**
     * Create a new Searchable attribute
     *
     * @param array<string> $fields Searchable fields
     * @param string|null $adapter Search adapter to use (null for database)
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $adapter = null,
    ) {
    }

    /**
     * Check if a field is searchable
     *
     * @param string $field The field name to check
     * @return bool
     */
    public function allows(string $field): bool
    {
        return in_array($field, $this->fields, true);
    }

    /**
     * Get the search adapter class name
     *
     * @return string|null
     */
    public function getAdapter(): ?string
    {
        return $this->adapter;
    }
}
