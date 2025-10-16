<?php

declare(strict_types=1);

namespace Glueful\Async\Contracts;

final class Timeout
{
    public function __construct(public float $seconds)
    {
    }
}
