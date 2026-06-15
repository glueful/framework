<?php

declare(strict_types=1);

namespace Glueful\Validation\Attributes;

/**
 * Marks a top-level RequestData DTO field as sourced from the query string
 * (not the JSON body). Valid only on the top-level injected DTO.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final class FromQuery
{
}
