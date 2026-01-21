<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Casts;

use ArrayObject;
use Glueful\Database\ORM\Contracts\CastsAttributes;
use Glueful\Database\ORM\Model;

/**
 * ArrayObject Cast
 *
 * Casts a JSON attribute to an ArrayObject instance. This allows for
 * object-style access to JSON data while still supporting array operations.
 *
 * @implements CastsAttributes<ArrayObject|null, ArrayObject|array<mixed>|null>
 */
class AsArrayObject implements CastsAttributes
{
    /**
     * Cast the given value from JSON to ArrayObject
     *
     * @param Model $model
     * @param string $key
     * @param mixed $value
     * @param array<string, mixed> $attributes
     * @return ArrayObject|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?ArrayObject
    {
        if ($value === null) {
            return null;
        }

        $decoded = json_decode($value, true) ?? [];

        return new ArrayObject($decoded, ArrayObject::ARRAY_AS_PROPS);
    }

    /**
     * Cast the given value to JSON for storage
     *
     * @param Model $model
     * @param string $key
     * @param mixed $value
     * @param array<string, mixed> $attributes
     * @return string|null
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof ArrayObject) {
            $value = $value->getArrayCopy();
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
