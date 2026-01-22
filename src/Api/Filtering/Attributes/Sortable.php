<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Attributes;

use Attribute;

/**
 * Mark controller method or class as having sortable fields
 *
 * @example
 * ```php
 * #[Sortable(['name', 'created_at', 'updated_at'], default: '-created_at')]
 * public function index(): Response
 * {
 *     // ...
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class Sortable
{
    /**
     * Create a new Sortable attribute
     *
     * @param array<string> $fields Sortable fields
     * @param string|null $default Default sort (e.g., '-created_at' for descending)
     * @param int $maxFields Maximum number of sort fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $default = null,
        public readonly int $maxFields = 5,
    ) {
    }

    /**
     * Check if a field is sortable
     *
     * @param string $field The field name to check
     * @return bool
     */
    public function allows(string $field): bool
    {
        return in_array($field, $this->fields, true);
    }

    /**
     * Get the default sort string
     *
     * @return string|null
     */
    public function getDefault(): ?string
    {
        return $this->default;
    }
}
