<?php

declare(strict_types=1);

namespace Glueful\Database\Query;

/**
 * Allow-lists for SQL operators and join types that get interpolated directly into SQL
 * (i.e. not bound as parameters). Validating against a fixed set prevents an app that
 * forwards request input into a developer-facing operator argument (JOIN / HAVING /
 * ORM has()) from injecting arbitrary SQL.
 */
final class SqlOperators
{
    /** @var array<int, string> Comparison operators (column/value comparisons, counts). */
    public const COMPARISON = ['=', '!=', '<>', '<', '>', '<=', '>='];

    /** @var array<int, string> Comparison operators plus pattern matching (for HAVING). */
    public const COMPARISON_AND_LIKE = [
        '=', '!=', '<>', '<', '>', '<=', '>=',
        'LIKE', 'NOT LIKE', 'ILIKE',
    ];

    /** @var array<int, string> Supported JOIN types. */
    public const JOIN_TYPES = [
        'INNER', 'LEFT', 'RIGHT', 'FULL',
        'LEFT OUTER', 'RIGHT OUTER', 'FULL OUTER', 'CROSS',
    ];

    /**
     * Validate an operator against an allow-list (case-insensitive) and return the
     * canonical upper-case form. Throws on anything not in the list.
     *
     * @param array<int, string> $allowed
     */
    public static function assertOperator(string $operator, array $allowed = self::COMPARISON): string
    {
        $normalized = strtoupper(trim($operator));
        if (!in_array($normalized, $allowed, true)) {
            throw new \InvalidArgumentException("Unsupported SQL operator: '{$operator}'");
        }

        return $normalized;
    }

    /**
     * Validate a JOIN type against the allow-list and return its canonical upper-case form.
     */
    public static function assertJoinType(string $type): string
    {
        $normalized = strtoupper(trim($type));
        if (!in_array($normalized, self::JOIN_TYPES, true)) {
            throw new \InvalidArgumentException("Unsupported JOIN type: '{$type}'");
        }

        return $normalized;
    }
}
