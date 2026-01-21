<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Contracts;

use Glueful\Database\ORM\Model;

/**
 * CastsAttributes Interface
 *
 * Defines the contract for custom attribute casters. Classes implementing
 * this interface can be used in the model's $casts array to transform
 * attribute values when getting and setting.
 *
 * @template TGet
 * @template TSet
 */
interface CastsAttributes
{
    /**
     * Transform the attribute from the underlying model values
     *
     * @param Model $model The model instance
     * @param string $key The attribute name
     * @param mixed $value The raw value from the database
     * @param array<string, mixed> $attributes All model attributes
     * @return TGet|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed;

    /**
     * Transform the attribute to its underlying model values
     *
     * @param Model $model The model instance
     * @param string $key The attribute name
     * @param TSet|null $value The value being set
     * @param array<string, mixed> $attributes All model attributes
     * @return mixed The value to store in the database
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed;
}
