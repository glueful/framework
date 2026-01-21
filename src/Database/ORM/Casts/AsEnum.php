<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Casts;

use BackedEnum;
use Glueful\Database\ORM\Contracts\CastsAttributes;
use Glueful\Database\ORM\Model;
use InvalidArgumentException;
use UnitEnum;

/**
 * Enum Cast
 *
 * Casts an attribute to/from a PHP 8.1+ backed enum. The database stores
 * the enum's backing value (string or int), and retrieval returns the
 * corresponding enum case.
 *
 * @template TEnum of BackedEnum
 * @implements CastsAttributes<TEnum|null, TEnum|string|int|null>
 */
class AsEnum implements CastsAttributes
{
    /**
     * The enum class name
     *
     * @var class-string<TEnum>
     */
    protected string $enumClass;

    /**
     * Create a new enum cast
     *
     * @param class-string<TEnum> $enumClass
     */
    public function __construct(string $enumClass)
    {
        if (!is_subclass_of($enumClass, BackedEnum::class)) {
            throw new InvalidArgumentException(
                "The [{$enumClass}] class must be a backed enum."
            );
        }

        $this->enumClass = $enumClass;
    }

    /**
     * Cast the given value to an enum
     *
     * @param Model $model
     * @param string $key
     * @param mixed $value
     * @param array<string, mixed> $attributes
     * @return TEnum|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?BackedEnum
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof $this->enumClass) {
            return $value;
        }

        return $this->enumClass::tryFrom($value);
    }

    /**
     * Cast the given enum to its backing value for storage
     *
     * @param Model $model
     * @param string $key
     * @param mixed $value
     * @param array<string, mixed> $attributes
     * @return string|int|null
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string|int|null
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        // Validate the value can be converted to the enum
        $enum = $this->enumClass::tryFrom($value);

        if ($enum === null) {
            throw new InvalidArgumentException(
                "The value [{$value}] is not a valid backing value for [{$this->enumClass}]."
            );
        }

        return $value;
    }

    /**
     * Create a new enum cast for the given class
     *
     * @param class-string<BackedEnum> $enumClass
     * @return static
     */
    public static function of(string $enumClass): static
    {
        return new static($enumClass);
    }
}
