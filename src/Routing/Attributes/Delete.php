<?php

declare(strict_types=1);

namespace Glueful\Routing\Attributes;

#[\Attribute(\Attribute::TARGET_METHOD)]
class Delete
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
