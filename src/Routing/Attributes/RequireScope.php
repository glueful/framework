<?php

declare(strict_types=1);

namespace Glueful\Routing\Attributes;

use Attribute;

/**
 * Declare required scopes for a route.
 *
 * IS_REPEATABLE is load-bearing: stacking the attribute expresses AND
 * semantics across requirements. Multiple scopes within one attribute = OR.
 *
 * @example
 * #[RequireScope('read:posts')]
 *
 * @example
 * // OR within one attribute, AND across two attributes
 * #[RequireScope(['write:posts', 'admin:posts'])]
 * #[RequireScope('publish:posts')]
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class RequireScope
{
    /** @var array<int, string> */
    public readonly array $scopes;

    /**
     * @param string|array<int, string> $scopes  OR semantics within this attribute.
     */
    public function __construct(string|array $scopes)
    {
        $this->scopes = is_string($scopes) ? [$scopes] : array_values($scopes);
    }
}
