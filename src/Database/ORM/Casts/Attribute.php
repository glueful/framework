<?php

declare(strict_types=1);

namespace Glueful\Database\ORM\Casts;

use Closure;

/**
 * Attribute Cast
 *
 * Allows defining custom getter and setter logic using closures.
 * This provides a modern way to define accessors and mutators
 * without requiring dedicated methods.
 *
 * @example
 * // In a Model class:
 * protected function firstName(): Attribute
 * {
 *     return Attribute::make(
 *         get: fn ($value) => ucfirst($value),
 *         set: fn ($value) => strtolower($value),
 *     );
 * }
 */
class Attribute
{
    /**
     * The getter callback
     */
    public ?Closure $get = null;

    /**
     * The setter callback
     */
    public ?Closure $set = null;

    /**
     * Whether the attribute should be cached
     */
    public bool $withCaching = false;

    /**
     * Create a new attribute accessor / mutator
     *
     * @param Closure|null $get The getter callback
     * @param Closure|null $set The setter callback
     */
    public function __construct(?Closure $get = null, ?Closure $set = null)
    {
        $this->get = $get;
        $this->set = $set;
    }

    /**
     * Create a new attribute instance with getter and setter
     *
     * @param Closure|null $get The getter callback
     * @param Closure|null $set The setter callback
     * @return static
     */
    public static function make(?Closure $get = null, ?Closure $set = null): static
    {
        return new static($get, $set);
    }

    /**
     * Create a read-only attribute (getter only)
     *
     * @param Closure $get The getter callback
     * @return static
     */
    public static function get(Closure $get): static
    {
        return new static($get);
    }

    /**
     * Create a write-only attribute (setter only)
     *
     * @param Closure $set The setter callback
     * @return static
     */
    public static function set(Closure $set): static
    {
        return new static(null, $set);
    }

    /**
     * Enable caching for the attribute
     *
     * @return static
     */
    public function shouldCache(): static
    {
        $this->withCaching = true;

        return $this;
    }
}
