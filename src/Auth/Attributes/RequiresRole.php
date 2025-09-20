<?php

declare(strict_types=1);

namespace Glueful\Auth\Attributes;

/**
 * Attribute to declare required roles for controller methods or classes.
 *
 * Can be applied to:
 * - Controller classes: Requires role for all methods in the class
 * - Controller methods: Requires role for that specific method
 *
 * Multiple attributes can be stacked to require one of multiple roles (OR logic).
 * Use with RequiresPermission for more granular permission checks.
 *
 * @example
 * ```php
 * #[RequiresRole('admin')]
 * public function deleteAll() { }
 *
 * #[RequiresRole('editor')]
 * #[RequiresRole('admin')]
 * public function publish(int $id) { }  // editor OR admin
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class RequiresRole
{
    /**
     * @param string $name The role name (e.g., 'admin', 'editor', 'moderator')
     */
    public function __construct(
        public readonly string $name
    ) {
    }
}
