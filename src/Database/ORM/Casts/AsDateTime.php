<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Casts;

use DateTimeImmutable;
use DateTimeInterface;
use Glueful\Database\ORM\Contracts\CastsAttributes;
use Glueful\Database\ORM\Model;
use InvalidArgumentException;

/**
 * DateTime Cast
 *
 * Casts an attribute to/from DateTimeImmutable. When getting, string dates
 * are parsed into DateTimeImmutable. When setting, DateTime objects are
 * formatted to the specified format.
 *
 * @implements CastsAttributes<DateTimeImmutable|null, DateTimeInterface|string|null>
 */
class AsDateTime implements CastsAttributes
{
    /**
     * The format to use for storing dates
     */
    protected string $format;

    /**
     * Create a new cast instance
     *
     * @param string $format The format for storing dates (default: Y-m-d H:i:s)
     */
    public function __construct(string $format = 'Y-m-d H:i:s')
    {
        $this->format = $format;
    }

    /**
     * Cast the given value to DateTimeImmutable
     *
     * @param Model $model
     * @param string $key
     * @param mixed $value
     * @param array<string, mixed> $attributes
     * @return DateTimeImmutable|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        $parsed = DateTimeImmutable::createFromFormat($this->format, $value);

        if ($parsed === false) {
            // Try standard datetime parsing
            $parsed = new DateTimeImmutable($value);
        }

        return $parsed;
    }

    /**
     * Cast the given value to a string for storage
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

        if ($value instanceof DateTimeInterface) {
            return $value->format($this->format);
        }

        if (is_string($value)) {
            // Validate the string is a valid datetime
            $parsed = DateTimeImmutable::createFromFormat($this->format, $value);

            if ($parsed === false) {
                $parsed = new DateTimeImmutable($value);
            }

            return $parsed->format($this->format);
        }

        throw new InvalidArgumentException(
            'Invalid datetime value. Expected DateTimeInterface or string.'
        );
    }

    /**
     * Create a new cast with the specified format
     *
     * @param string $format
     * @return static
     */
    public static function format(string $format): static
    {
        return new static($format);
    }
}
