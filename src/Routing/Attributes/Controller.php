<?php

declare(strict_types=1);

namespace Glueful\Routing\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS)]
class Controller
{
    /**
     * @param array<string> $middleware
     */
    public function __construct(
        public string $prefix = '',
        public array $middleware = []
    ) {
    }
}
