<?php

declare(strict_types=1);

namespace Glueful\Validation\Rules;

use Glueful\Validation\Contracts\MutatingRule;

/**
 * Normalizes a date/datetime-like string to the given format (default `Y-m-d H:i:s`).
 *
 * A {@see MutatingRule} coerces, it does not validate: non-string or unparseable values are
 * returned UNCHANGED so a paired {@see Date} rule can reject them. Pair with {@see Date} when
 * strict validation (e.g. rejecting overflow dates) is required.
 */
final class CastToDate implements MutatingRule
{
    public function __construct(private readonly string $format = 'Y-m-d H:i:s')
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function mutate(mixed $value, array $context = []): mixed
    {
        if (!is_string($value) || trim($value) === '') {
            return $value;
        }
        $timestamp = strtotime(trim($value));
        if ($timestamp === false) {
            return $value;
        }
        return date($this->format, $timestamp);
    }
}
