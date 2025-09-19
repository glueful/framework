<?php

declare(strict_types=1);

namespace Glueful\Validation\Contracts;

interface Rule
{
    /**
     * Return error message or null if ok
     * @param array<string, mixed> $context
     */
    public function validate(mixed $value, array $context = []): ?string;
}
