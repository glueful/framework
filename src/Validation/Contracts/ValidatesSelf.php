<?php

declare(strict_types=1);

namespace Glueful\Validation\Contracts;

/**
 * A RequestData DTO may implement this to run cross-field / DTO-level validation
 * after hydration. Runs after per-field rules pass and the DTO is constructed
 * (typed access to $this). Returned errors merge into the same 422 envelope.
 */
interface ValidatesSelf
{
    /** @return array<string, list<string>> field => messages (empty array = valid) */
    public function validate(): array;
}
