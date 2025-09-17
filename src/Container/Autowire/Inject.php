<?php

declare(strict_types=1);

namespace Glueful\Container\Autowire;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
class Inject
{
    public function __construct(public ?string $id = null, public ?string $param = null)
    {
    }
}
