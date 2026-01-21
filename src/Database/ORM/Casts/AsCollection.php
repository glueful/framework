<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Casts;

use Glueful\Database\ORM\Collection;
use Glueful\Database\ORM\Contracts\CastsAttributes;
use Glueful\Database\ORM\Model;

/**
 * Collection Cast
 *
 * Casts a JSON attribute to an ORM Collection instance. This provides
 * access to all Collection methods on JSON array data.
 *
 * @implements CastsAttributes<Collection|null, Collection|array<mixed>|null>
 */
class AsCollection implements CastsAttributes
{
    /**
     * Cast the given value from JSON to Collection
     *
     * @param Model $model
     * @param string $key
     * @param mixed $value
     * @param array<string, mixed> $attributes
     * @return Collection|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Collection
    {
        if ($value === null) {
            return null;
        }

        $decoded = json_decode($value, true) ?? [];

        return new Collection($decoded);
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

        if ($value instanceof Collection) {
            $value = $value->toArray();
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
