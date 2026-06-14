<?php

declare(strict_types=1);

namespace Glueful\Serialization;

use Glueful\Http\Contracts\ResponseData;

/**
 * Converts a {@see ResponseData} DTO into a plain array payload.
 *
 * Resolution rules, in order:
 *  - Escape hatch: if the DTO declares its own `toArray()` method AND it returns
 *    an array, that result is returned verbatim (the DTO controls its own shape).
 *    The {@see ResponseData} interface does NOT declare `toArray()` — this is an
 *    optional method check. A `toArray()` that returns a non-array (e.g. an
 *    inherited method with a different contract) is ignored and reflection is used
 *    instead, so a misdeclared method degrades gracefully rather than throwing.
 *    NOTE: the escape hatch opts OUT of payload↔schema symmetry — the reflect-mode
 *    doc generator ({@see \Glueful\Support\Documentation\ClassSchemaReflector})
 *    always reflects the public properties, so a custom `toArray()` shape will not
 *    be reflected in the generated OpenAPI schema. Use it only when you accept that.
 *  - Otherwise the DTO's PUBLIC, non-static properties are reflected. An
 *    uninitialized typed property is SKIPPED (reading one throws a PHP Error).
 *  - Each value is mapped: scalar/null as-is; backed enum → `->value`; pure enum
 *    → `->name`; {@see \DateTimeInterface} → ISO-8601 (`format('c')`); a nested
 *    ResponseData → recursed; an array → element-wise mapped (recursing
 *    ResponseData/enum/DateTime, scalars as-is); any other object → best-effort
 *    `get_object_vars()` with each member mapped recursively (so nested
 *    enums/DateTimes/DTOs inside a plain object are resolved too).
 *
 * Cycle/depth guard: objects already on the current branch are tracked in an
 * SplObjectStorage, and recursion is additionally capped at {@see self::MAX_DEPTH}
 * (matching {@see \Glueful\Support\Documentation\ClassSchemaReflector}). A
 * self-referential DTO terminates with the cyclic reference rendered as `null`
 * rather than looping forever.
 *
 * Pure and stateless across calls: the visited set is created per top-level call.
 */
final class ResponseDataSerializer
{
    /** Maximum nesting depth before a value collapses to null. */
    private const MAX_DEPTH = 5;

    /**
     * @return array<string, mixed>
     */
    public function toArray(ResponseData $dto): array
    {
        /** @var \SplObjectStorage<object, true> $visited */
        $visited = new \SplObjectStorage();

        return $this->serializeDto($dto, $visited, 0);
    }

    /**
     * @param  \SplObjectStorage<object, true> $visited
     * @return array<string, mixed>
     */
    private function serializeDto(ResponseData $dto, \SplObjectStorage $visited, int $depth): array
    {
        // Escape hatch: let a DTO control its own shape. Only honoured when the
        // method actually yields an array — an inherited/misdeclared `toArray()`
        // with another return contract falls through to property reflection
        // rather than tripping this method's own `: array` return type.
        if (method_exists($dto, 'toArray')) {
            $custom = $dto->toArray();
            if (is_array($custom)) {
                /** @var array<string, mixed> $custom */
                return $custom;
            }
        }

        $visited->attach($dto);

        $result = [];
        $reflection = new \ReflectionClass($dto);

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }
            // Reading an uninitialized typed property throws — skip it.
            if (!$property->isInitialized($dto)) {
                continue;
            }

            $result[$property->getName()] = $this->mapValue($property->getValue($dto), $visited, $depth + 1);
        }

        $visited->detach($dto);

        return $result;
    }

    /**
     * @param  \SplObjectStorage<object, true> $visited
     * @return mixed
     */
    private function mapValue(mixed $value, \SplObjectStorage $visited, int $depth)
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            if ($depth >= self::MAX_DEPTH) {
                return null;
            }
            return array_map(
                fn (mixed $item): mixed => $this->mapValue($item, $visited, $depth + 1),
                $value,
            );
        }

        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }

        if (is_object($value)) {
            // Cycle or depth guard: do not re-enter an object already on this branch.
            if ($visited->contains($value) || $depth >= self::MAX_DEPTH) {
                return null;
            }

            if ($value instanceof ResponseData) {
                return $this->serializeDto($value, $visited, $depth);
            }

            // Best-effort for any other object: emit its public properties, mapping each
            // value through mapValue so nested enums / DateTimes / DTOs / cycles are
            // handled uniformly (with the same visited + depth guard).
            $visited->attach($value);
            $vars = [];
            foreach (get_object_vars($value) as $name => $member) {
                $vars[$name] = $this->mapValue($member, $visited, $depth + 1);
            }
            $visited->detach($value);

            return $vars;
        }

        return null;
    }
}
