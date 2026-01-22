<?php

declare(strict_types=1);

namespace Glueful\Api\Filtering\Exceptions;

use InvalidArgumentException;

/**
 * Exception thrown when an operator is invalid or unknown
 */
class InvalidOperatorException extends InvalidArgumentException
{
    /**
     * Create exception for unknown operator
     *
     * @param string $operator The unknown operator name
     * @param array<string> $available Available operator names
     * @return self
     */
    public static function unknownOperator(string $operator, array $available = []): self
    {
        $message = "Unknown filter operator: '{$operator}'";

        if ($available !== []) {
            $availableList = implode(', ', $available);
            $message .= ". Available operators: {$availableList}";
        }

        return new self($message);
    }

    /**
     * Create exception for operator not allowed on field
     *
     * @param string $operator The operator name
     * @param string $field The field name
     * @return self
     */
    public static function notAllowedOnField(string $operator, string $field): self
    {
        return new self(
            "Operator '{$operator}' is not allowed on field '{$field}'"
        );
    }

    /**
     * Create exception for invalid operator argument
     *
     * @param string $operator The operator name
     * @param string $reason The reason the argument is invalid
     * @return self
     */
    public static function invalidArgument(string $operator, string $reason): self
    {
        return new self(
            "Invalid argument for operator '{$operator}': {$reason}"
        );
    }
}
