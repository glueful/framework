<?php

declare(strict_types=1);

namespace Glueful\Routing\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD)]
class Route
{
    /**
     * @var array<string>
     */
    public array $methods;

    /**
     * @param string|array<string> $methods
     * @param array<string> $middleware
     * @param array<string, string> $where
     */
    public function __construct(
        public string $path,
        string|array $methods = 'GET',
        public ?string $name = null,
        public array $middleware = [],
        public array $where = []
    ) {
        $this->methods = (array) $methods;
    }
}
