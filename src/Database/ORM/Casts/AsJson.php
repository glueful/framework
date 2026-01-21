<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Casts;

use Glueful\Database\ORM\Contracts\CastsAttributes;
use Glueful\Database\ORM\Model;

/**
 * JSON Cast
 *
 * Casts an attribute to/from JSON. When getting, the JSON string is decoded
 * to an array. When setting, the array is encoded to JSON.
 *
 * @implements CastsAttributes<array<mixed>|null, array<mixed>|null>
 */
class AsJson implements CastsAttributes
{
    /**
     * Cast the given value from JSON to array
     *
     * @param Model $model
     * @param string $key
     * @param mixed $value
     * @param array<string, mixed> $attributes
     * @return array<mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        return json_decode($value, true);
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

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
