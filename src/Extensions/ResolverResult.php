<?php

declare(strict_types=1);

namespace Glueful\Extensions;

final class ResolverResult
{
    /**
     * @param list<string> $providers Ordered provider FQCNs to load
     * @param list<ResolverError> $errors
     */
    public function __construct(
        public readonly array $providers,
        public readonly array $errors,
    ) {
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
