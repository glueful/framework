<?php

declare(strict_types=1);

namespace Glueful\Tests\Support\Fixtures\RequestData;

use Glueful\Validation\Attributes\ArrayOf;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

final class RecursiveFixture implements RequestData
{
    /** @param array<int,RecursiveFixture> $children */
    public function __construct(
        #[ArrayOf(RecursiveFixture::class)] #[Rule('array')] public array $children = [],
    ) {
    }
}
