<?php

declare(strict_types=1);

namespace Glueful\Routing\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class Middleware
{
    /**
     * @var array<string>
     */
    public array $middleware;

    /**
     * @param string|array<string> $middleware
     */
    public function __construct(string|array $middleware)
    {
        $this->middleware = (array) $middleware;
    }
}
