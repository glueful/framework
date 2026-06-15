<?php

declare(strict_types=1);

namespace Glueful\Validation\Attributes;

/**
 * Marks a top-level RequestData DTO field as sourced from the matched path
 * parameters (not the JSON body). Valid only on the top-level injected DTO —
 * encountering it on a nested DTO during recursive hydration is a developer
 * error (see RequestDataHydrator).
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final class FromRoute
{
}
