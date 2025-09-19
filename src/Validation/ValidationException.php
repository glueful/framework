<?php

declare(strict_types=1);

namespace Glueful\Validation;

final class ValidationException extends \RuntimeException
{
    /** @param array<string, string[]> $errors */
    public function __construct(private array $errors)
    {
        parent::__construct('Validation failed.');
    }

    /** @return array<string, string[]> */
    public function errors(): array
    {
        return $this->errors;
    }
}
