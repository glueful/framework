<?php

declare(strict_types=1);

namespace Glueful\Auth\Attributes;

/**
 * Attribute to declare required permissions for controller methods or classes.
 *
 * Can be applied to:
 * - Controller classes: Requires permission for all methods in the class
 * - Controller methods: Requires permission for that specific method
 *
 * Multiple attributes can be stacked to require multiple permissions.
 *
 * @example
 * ```php
 * #[RequiresPermission('posts.create')]
 * public function store(Request $request) { }
 *
 * #[RequiresPermission('posts.edit')]
 * #[RequiresPermission('posts.publish')]
 * public function publish(int $id) { }
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class RequiresPermission
{
    /**
     * @param string $name The permission name (e.g., 'posts.create', 'users.delete')
     * @param string|null $resource Optional resource identifier for context-aware checks
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $resource = null
    ) {
    }
}
