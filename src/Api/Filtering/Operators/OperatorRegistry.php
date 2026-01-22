<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Operators;

use Glueful\Api\Filtering\Contracts\FilterOperatorInterface;
use Glueful\Api\Filtering\Exceptions\InvalidOperatorException;

/**
 * Registry for filter operators
 *
 * Manages registration and retrieval of filter operators.
 * Operators are registered by name and aliases for flexible lookup.
 */
class OperatorRegistry
{
    /** @var array<string, FilterOperatorInterface> */
    private static array $operators = [];

    /** @var bool */
    private static bool $initialized = false;

    /**
     * Initialize registry with default operators
     */
    public static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }

        // Comparison operators
        self::register(new EqualOperator());
        self::register(new NotEqualOperator());
        self::register(new GreaterThanOperator());
        self::register(new GreaterThanOrEqualOperator());
        self::register(new LessThanOperator());
        self::register(new LessThanOrEqualOperator());

        // String operators
        self::register(new ContainsOperator());
        self::register(new StartsWithOperator());
        self::register(new EndsWithOperator());

        // Array operators
        self::register(new InOperator());
        self::register(new NotInOperator());
        self::register(new BetweenOperator());

        // Null operators
        self::register(new NullOperator());
        self::register(new NotNullOperator());

        self::$initialized = true;
    }

    /**
     * Register an operator
     *
     * @param FilterOperatorInterface $operator The operator to register
     */
    public static function register(FilterOperatorInterface $operator): void
    {
        self::$operators[$operator->name()] = $operator;

        foreach ($operator->aliases() as $alias) {
            self::$operators[$alias] = $operator;
        }
    }

    /**
     * Get an operator by name or alias
     *
     * @param string $name The operator name or alias
     * @return FilterOperatorInterface
     * @throws InvalidOperatorException If operator not found
     */
    public static function get(string $name): FilterOperatorInterface
    {
        self::initialize();

        $name = strtolower($name);

        if (!isset(self::$operators[$name])) {
            throw InvalidOperatorException::unknownOperator($name, self::getOperatorNames());
        }

        return self::$operators[$name];
    }

    /**
     * Check if an operator exists
     *
     * @param string $name The operator name or alias
     * @return bool
     */
    public static function has(string $name): bool
    {
        self::initialize();
        return isset(self::$operators[strtolower($name)]);
    }

    /**
     * Get all operator names and aliases
     *
     * @return array<string>
     */
    public static function getAliases(): array
    {
        self::initialize();
        return array_keys(self::$operators);
    }

    /**
     * Get unique operator names (without aliases)
     *
     * @return array<string>
     */
    public static function getOperatorNames(): array
    {
        self::initialize();

        $names = [];
        foreach (self::$operators as $operator) {
            $names[$operator->name()] = true;
        }

        return array_keys($names);
    }

    /**
     * Reset the registry (mainly for testing)
     */
    public static function reset(): void
    {
        self::$operators = [];
        self::$initialized = false;
    }
}
