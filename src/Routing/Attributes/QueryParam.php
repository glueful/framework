<?php

declare(strict_types=1);

namespace Glueful\Routing\Attributes;

/**
 * Documents an arbitrary query parameter on a controller method for the reflect
 * OpenAPI generator. Repeatable. An explicit QueryParam overrides a generated
 * query parameter (e.g. field-selection) of the same name.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class QueryParam
{
    /** @param list<string>|null $enum */
    public function __construct(
        public readonly string $name,
        public readonly string $type = 'string',
        public readonly string $description = '',
        public readonly bool $required = false,
        public readonly ?string $format = null,
        public readonly ?array $enum = null,
    ) {
    }
}
