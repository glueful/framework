<?php

declare(strict_types=1);

namespace Glueful\Validation\Attributes;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_PARAMETER)]
final class Rule
{
    /**
     * @param string $rules Laravel-style rule string, e.g. "required|string|max:200".
     */
    public function __construct(public readonly string $rules)
    {
    }
}
