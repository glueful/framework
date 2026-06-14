<?php

declare(strict_types=1);

namespace Glueful\Support\Documentation;

/**
 * Maps Glueful Validator (Laravel-style) rule strings to an OpenAPI object schema.
 *
 * Turns a `field => 'required|email|min:8'` rule map into a JSON-Schema-style
 * object describing the request body: per-field `type`/`format`/`enum` and
 * `minLength`/`maxLength` (strings) or `minimum`/`maximum` (numbers), plus the
 * top-level `required` list. Unknown rules are ignored; a field with no
 * recognised type rule defaults to `type: string`.
 *
 * This is a pure mapper (no I/O, no state) so it is trivially unit-testable and
 * reusable by the {@see RouteReflectionDocGenerator}. The companion
 * {@see ExampleDeriver} produces an example payload from the same rules.
 */
final class ValidationRuleSchema
{
    /**
     * Build an OpenAPI object schema from a validation rule map.
     *
     * @param  array<string, string|list<string>> $rules
     * @return array{type: string, properties: array<string, array<string, mixed>>, required?: list<string>}
     */
    public static function toObjectSchema(array $rules): array
    {
        $properties = [];
        $required = [];

        foreach ($rules as $field => $rule) {
            $parts = self::normalize($rule);

            if (in_array('required', $parts, true)) {
                $required[] = $field;
            }

            $properties[$field] = self::propertyFor($parts);
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * Split a per-field rule into a list of individual rule tokens.
     *
     * @param  string|list<string> $rule
     * @return list<string>
     */
    private static function normalize(string|array $rule): array
    {
        if (is_array($rule)) {
            return array_values(array_filter(
                array_map(static fn ($r): string => is_string($r) ? $r : '', $rule),
                static fn (string $r): bool => $r !== '',
            ));
        }

        return array_values(array_filter(
            explode('|', $rule),
            static fn (string $r): bool => $r !== '',
        ));
    }

    /**
     * Build the schema fragment for a single field from its rule tokens.
     *
     * @param  list<string> $rules
     * @return array<string, mixed>
     */
    private static function propertyFor(array $rules): array
    {
        $property = ['type' => self::detectType($rules)];

        $format = self::detectFormat($rules);
        if ($format !== null) {
            $property['format'] = $format;
        }

        if ($property['type'] === 'array') {
            $property['items'] = ['type' => 'string'];
        }

        foreach ($rules as $rule) {
            [$name, $arg] = self::splitRule($rule);

            if ($name === 'in' && $arg !== null && $arg !== '') {
                $members = array_values(array_filter(
                    array_map('trim', explode(',', $arg)),
                    static fn (string $v): bool => $v !== '',
                ));
                $type = $property['type'] ?? 'string';
                $property['enum'] = match ($type) {
                    'integer' => array_map(static fn (string $v): int => (int) $v, $members),
                    'number' => array_map(static fn (string $v): float => (float) $v, $members),
                    default => $members,
                };
                continue;
            }

            if (($name === 'min' || $name === 'max') && $arg !== null && is_numeric($arg)) {
                self::applyBound($property, $name, (int) $arg);
            }
        }

        return $property;
    }

    /**
     * Resolve the OpenAPI `type` for a field from its type-bearing rules.
     *
     * Falls back to `string` when no recognised type rule is present.
     *
     * @param list<string> $rules
     */
    private static function detectType(array $rules): string
    {
        foreach ($rules as $rule) {
            $name = self::splitRule($rule)[0];
            $type = match ($name) {
                'integer', 'int' => 'integer',
                'numeric' => 'number',
                'boolean', 'bool' => 'boolean',
                'array' => 'array',
                'string' => 'string',
                default => null,
            };
            if ($type !== null) {
                return $type;
            }
        }

        return 'string';
    }

    /**
     * Resolve the OpenAPI string `format` for a field, if any rule implies one.
     *
     * @param list<string> $rules
     */
    private static function detectFormat(array $rules): ?string
    {
        foreach ($rules as $rule) {
            $name = self::splitRule($rule)[0];
            $format = match ($name) {
                'email' => 'email',
                'uuid' => 'uuid',
                'url' => 'uri',
                'date' => 'date',
                'datetime', 'date_format' => 'date-time',
                default => null,
            };
            if ($format !== null) {
                return $format;
            }
        }

        return null;
    }

    /**
     * Apply a min/max bound using the keyword that's valid for the field's type:
     * minimum/maximum (numbers), minItems/maxItems (arrays), minLength/maxLength
     * (strings). Booleans have no meaningful bound, so the rule is dropped.
     *
     * @param array<string, mixed> $property
     */
    private static function applyBound(array &$property, string $name, int $value): void
    {
        $isMin = $name === 'min';

        match ($property['type'] ?? 'string') {
            'integer', 'number' => $property[$isMin ? 'minimum' : 'maximum'] = $value,
            'array' => $property[$isMin ? 'minItems' : 'maxItems'] = $value,
            'boolean' => null, // bounds are meaningless on a boolean
            default => $property[$isMin ? 'minLength' : 'maxLength'] = $value,
        };
    }

    /**
     * Split a rule token into its name and optional argument (`min:8` => [min, 8]).
     *
     * @return array{0: string, 1: string|null}
     */
    private static function splitRule(string $rule): array
    {
        $segments = explode(':', $rule, 2);

        return [$segments[0], $segments[1] ?? null];
    }
}
