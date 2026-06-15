<?php

declare(strict_types=1);

namespace Glueful\Validation\Attributes;

/**
 * Declares the element type of an `array` field on a DTO. For `RequestData`
 * DTOs this is the ONLY source of an array field's element type for request
 * hydration and the generated OpenAPI request-body `items` (a bare `array` is
 * mixed; `@var` is not read for request DTOs). For `ResponseData` DTOs it is
 * authoritative for the generated OpenAPI response `items`, falling back to
 * `@var Foo[]` only when the attribute is absent. The constructor validates
 * fail-loud at load time.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final class ArrayOf
{
    private const SCALARS = ['string' => 'string', 'int' => 'int', 'integer' => 'int',
        'float' => 'float', 'number' => 'float', 'bool' => 'bool', 'boolean' => 'bool'];

    /** Canonical type: one of string|int|float|bool, or an existing class-string. */
    public readonly string $type;

    public function __construct(string $type)
    {
        if (isset(self::SCALARS[$type])) {
            $this->type = self::SCALARS[$type];
            return;
        }
        if (!class_exists($type)) {
            throw new \InvalidArgumentException(
                "#[ArrayOf] type '{$type}' is neither a known scalar (string|int|float|bool) nor an existing class."
            );
        }
        $this->type = $type;
    }

    public function isScalar(): bool
    {
        return in_array($this->type, ['string', 'int', 'float', 'bool'], true);
    }

    /** @return class-string|null */
    public function dtoClass(): ?string
    {
        return $this->isScalar() ? null : $this->type;
    }
}
