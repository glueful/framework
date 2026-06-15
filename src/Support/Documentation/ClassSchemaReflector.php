<?php

declare(strict_types=1);

namespace Glueful\Support\Documentation;

use Glueful\Validation\Attributes\ArrayOf;
use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\FromRoute;

/**
 * Reflects a typed DTO class into an OpenAPI object schema.
 *
 * Reads the PUBLIC, non-static typed properties of a class and maps each to an
 * OpenAPI property schema:
 *  - scalars (`string`/`int`/`float`/`bool`) → the matching JSON-Schema type;
 *  - nullable (`?T`) → `nullable: true` on the property (the {@see DocGenerator}
 *    rewrites this to the 3.1 `type: [T, 'null']` array form when emitting 3.1);
 *  - `DateTimeInterface` → `{type: string, format: date-time}`;
 *  - backed enums → `{type, enum: [...case values]}`; pure enums → case names;
 *  - nested DTO classes → recursively reflected (with a depth/cycle guard);
 *  - `array` properties → `{type: array, items: ...}`, with the item type derived
 *    from a `@var Foo[]` / `@var array<Foo>` docblock when resolvable.
 *
 * Pure and robust: a missing, internal, or uninstantiable class — or any
 * reflection failure — yields `['type' => 'object']` rather than throwing. This
 * is reusable for request DTOs, so nothing here is response-specific.
 */
final class ClassSchemaReflector
{
    /** Maximum nesting depth before a nested DTO collapses to `{type: object}`. */
    private const MAX_DEPTH = 5;

    /**
     * Reflect a DTO class into an OpenAPI object schema.
     *
     * When `$requestMode` is true (v2 request DTO reflection): an `array` property's
     * `items` is read from `#[ArrayOf]` ONLY (never `@var`; a bare `array` yields
     * `items: {}`), and properties annotated `#[FromRoute]` / `#[FromQuery]` are
     * excluded from the object schema (they are not body). The default mode (used by
     * `ResponseData` reflection) is unchanged.
     *
     * @param  class-string        $class
     * @return array<string, mixed>
     */
    public static function toSchema(string $class, bool $requestMode = false): array
    {
        return self::reflect($class, [], $requestMode);
    }

    /**
     * @param  class-string   $class
     * @param  list<string>   $visited  classes already expanded on this branch (cycle guard)
     * @return array<string, mixed>
     */
    private static function reflect(string $class, array $visited, bool $requestMode = false): array
    {
        if (!class_exists($class) || in_array($class, $visited, true) || count($visited) >= self::MAX_DEPTH) {
            return ['type' => 'object'];
        }

        $reflection = new \ReflectionClass($class);

        $visited[] = $class;
        $properties = [];

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            // In request mode, route/query-sourced fields are not part of the body.
            if (
                $requestMode
                && ($property->getAttributes(FromRoute::class) !== []
                    || $property->getAttributes(FromQuery::class) !== [])
            ) {
                continue;
            }

            $schema = self::propertySchema($property, $visited, $requestMode);

            $description = self::descriptionFor($property);
            if ($description !== null && !isset($schema['description'])) {
                $schema['description'] = $description;
            }

            $properties[$property->getName()] = $schema;
        }

        return [
            'type' => 'object',
            // Emit `{}` (not a malformed `[]`) when a class has no public typed
            // properties — a propertyless DTO documents a generic open object.
            'properties' => $properties === [] ? new \stdClass() : $properties,
        ];
    }

    /**
     * Build the schema for a single property.
     *
     * @param  list<string> $visited
     * @return array<string, mixed>
     */
    private static function propertySchema(
        \ReflectionProperty $property,
        array $visited,
        bool $requestMode = false
    ): array {
        $type = $property->getType();

        if (!$type instanceof \ReflectionNamedType) {
            // Untyped or union/intersection types: best-effort string.
            return ['type' => 'string'];
        }

        $schema = self::schemaForType($type->getName(), $property, $visited, $requestMode);

        if ($type->allowsNull()) {
            $schema['nullable'] = true;
        }

        return $schema;
    }

    /**
     * Map a resolved type name to an OpenAPI schema fragment.
     *
     * @param  list<string> $visited
     * @return array<string, mixed>
     */
    private static function schemaForType(
        string $name,
        \ReflectionProperty $property,
        array $visited,
        bool $requestMode = false
    ): array {
        switch ($name) {
            case 'string':
                return ['type' => 'string'];
            case 'int':
                return ['type' => 'integer'];
            case 'float':
                return ['type' => 'number'];
            case 'bool':
                return ['type' => 'boolean'];
            case 'array':
                return self::arraySchema($property, $visited, $requestMode);
            case 'mixed':
            case 'object':
                return ['type' => 'object'];
        }

        if (!class_exists($name) && !interface_exists($name)) {
            return ['type' => 'string'];
        }

        if (is_a($name, \DateTimeInterface::class, true)) {
            return ['type' => 'string', 'format' => 'date-time'];
        }

        if (is_a($name, \UnitEnum::class, true)) {
            return self::enumSchema($name);
        }

        // Nested DTO — recurse (the reflect() guard handles cycles/depth).
        return self::reflect($name, $visited, $requestMode);
    }

    /**
     * Build an array schema.
     *
     * In request mode, the element type is read from `#[ArrayOf]` ONLY: a scalar
     * `#[ArrayOf]` maps to a scalar `items`, a DTO-class `#[ArrayOf]` recurses in
     * request mode, and a bare `array` (no attribute) yields `items: {}`. The `@var`
     * docblock is never consulted. In the default mode, the item type is derived from
     * a `@var Foo[]` / `@var array<Foo>` docblock when present.
     *
     * @param  list<string> $visited
     * @return array<string, mixed>
     */
    private static function arraySchema(\ReflectionProperty $property, array $visited, bool $requestMode = false): array
    {
        if ($requestMode) {
            return self::requestArraySchema($property, $visited);
        }

        $itemClass = self::itemClassFromDocblock($property);

        if ($itemClass !== null && class_exists($itemClass)) {
            if (is_a($itemClass, \DateTimeInterface::class, true)) {
                return ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'date-time']];
            }
            if (is_a($itemClass, \UnitEnum::class, true)) {
                return ['type' => 'array', 'items' => self::enumSchema($itemClass)];
            }
            return ['type' => 'array', 'items' => self::reflect($itemClass, $visited)];
        }

        if ($itemClass !== null) {
            // Scalar item type from the docblock (e.g. `string[]`, `int[]`).
            $scalar = self::scalarSchema($itemClass);
            if ($scalar !== null) {
                return ['type' => 'array', 'items' => $scalar];
            }
        }

        return ['type' => 'array', 'items' => new \stdClass()];
    }

    /**
     * Build a request-mode array schema, resolving `items` from `#[ArrayOf]` only.
     *
     * A bare `array` (no `#[ArrayOf]`) is mixed → `items: {}`. A scalar `#[ArrayOf]`
     * maps to the matching scalar schema; a DTO-class `#[ArrayOf]` recurses in
     * request mode. The `@var` docblock is never read here.
     *
     * @param  list<string> $visited
     * @return array<string, mixed>
     */
    private static function requestArraySchema(\ReflectionProperty $property, array $visited): array
    {
        $attributes = $property->getAttributes(ArrayOf::class);
        if ($attributes === []) {
            return ['type' => 'array', 'items' => new \stdClass()];
        }

        $arrayOf = $attributes[0]->newInstance();

        if ($arrayOf->isScalar()) {
            // ArrayOf's canonical scalar names are int|float|bool|string; scalarSchema
            // maps `int` → {type: integer} etc. It is non-null for these canonicals.
            $scalar = self::scalarSchema($arrayOf->type);
            return ['type' => 'array', 'items' => $scalar ?? new \stdClass()];
        }

        $dtoClass = $arrayOf->dtoClass();
        if ($dtoClass === null) {
            return ['type' => 'array', 'items' => new \stdClass()];
        }

        return ['type' => 'array', 'items' => self::reflect($dtoClass, $visited, true)];
    }

    /**
     * Map a scalar type name to its schema, or null when not scalar.
     *
     * @return array<string, mixed>|null
     */
    private static function scalarSchema(string $name): ?array
    {
        return match ($name) {
            'string' => ['type' => 'string'],
            'int', 'integer' => ['type' => 'integer'],
            'float', 'double' => ['type' => 'number'],
            'bool', 'boolean' => ['type' => 'boolean'],
            default => null,
        };
    }

    /**
     * Build an enum schema: backed enums emit typed `enum` values, pure enums emit case names.
     *
     * @param  class-string $enum
     * @return array<string, mixed>
     */
    private static function enumSchema(string $enum): array
    {
        try {
            $reflection = new \ReflectionEnum($enum);
        } catch (\Throwable) {
            return ['type' => 'string'];
        }

        if ($reflection->isBacked()) {
            $backingType = (string) $reflection->getBackingType();
            $type = $backingType === 'int' ? 'integer' : 'string';
            $values = array_map(
                static fn (\UnitEnum $case): string|int => $case instanceof \BackedEnum ? $case->value : $case->name,
                $enum::cases(),
            );
            return ['type' => $type, 'enum' => array_values($values)];
        }

        $names = array_map(static fn (\UnitEnum $case): string => $case->name, $enum::cases());
        return ['type' => 'string', 'enum' => array_values($names)];
    }

    /**
     * Extract the item class/type from a property's `@var Foo[]` or `@var array<Foo>` docblock.
     *
     * Best-effort: returns a fully-qualified class name (resolving same-namespace
     * and imported short names), a scalar keyword, or null when nothing parses.
     */
    private static function itemClassFromDocblock(\ReflectionProperty $property): ?string
    {
        $doc = $property->getDocComment();
        if ($doc === false) {
            return null;
        }

        $item = null;
        // @var Foo[]  or  @var Foo[]|null
        if (preg_match('/@var\s+([\\\\\w]+)\s*\[\s*\]/', $doc, $m) === 1) {
            $item = $m[1];
        } elseif (preg_match('/@var\s+array<\s*(?:[\w]+\s*,\s*)?([\\\\\w]+)\s*>/', $doc, $m) === 1) {
            // array<Foo> or array<int, Foo> — take the last type argument.
            $item = $m[1];
        }

        if ($item === null) {
            return null;
        }

        if (self::scalarSchema($item) !== null) {
            return $item;
        }

        return self::resolveClassName($item, $property->getDeclaringClass());
    }

    /**
     * Resolve a (possibly short) class name against the declaring class's namespace.
     *
     * @param \ReflectionClass<object> $declaring
     */
    private static function resolveClassName(string $name, \ReflectionClass $declaring): ?string
    {
        // Already fully-qualified.
        if (str_starts_with($name, '\\')) {
            $fqcn = ltrim($name, '\\');
            return class_exists($fqcn) ? $fqcn : null;
        }
        if (class_exists($name)) {
            return $name;
        }

        // Same namespace as the declaring class.
        $namespace = $declaring->getNamespaceName();
        if ($namespace !== '') {
            $candidate = $namespace . '\\' . $name;
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Pull a short description from a property's `@var Type description` docblock, if cheap.
     */
    private static function descriptionFor(\ReflectionProperty $property): ?string
    {
        $doc = $property->getDocComment();
        if ($doc === false) {
            return null;
        }

        // The description must be SAME-LINE text after `@var <type>` — `[ \t]+`
        // (not `\s+`) so a `@var array<string,mixed>` with the type on its own line
        // does NOT capture the next docblock line (e.g. the closing `*/`) as a
        // bogus single-character description.
        if (preg_match('/@var\s+\S+[ \t]+([^\n]+?)\s*(?:\n|\*\/)/', $doc, $m) === 1) {
            $description = trim($m[1], " \t*");
            if (strlen($description) > 0) {
                return $description;
            }
        }

        return null;
    }
}
