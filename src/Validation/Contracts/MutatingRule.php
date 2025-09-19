<?php

declare(strict_types=1);

namespace Glueful\Validation\Contracts;

interface MutatingRule
{
    /**
     * Mutate the incoming value before other rules run.
     *
     * @param array<string, mixed> $context
     */
    public function mutate(mixed $value, array $context = []): mixed;
}
