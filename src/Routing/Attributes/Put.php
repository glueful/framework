<?php

declare(strict_types=1);

namespace Glueful\Routing\Attributes;

// Shorthand attributes for common HTTP methods
#[\Attribute(\Attribute::TARGET_METHOD)]
class Put
{
    /**
     * @param array<string, string> $where
     */
    public function __construct(
        public string $path,
        public ?string $name = null,
        public array $where = []
    ) {
    }
}
